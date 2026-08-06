<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\PaystackGateway;
use App\Services\PricingService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tarifs de la plateforme, réglables sans livraison (ST-0804, ST-0805).
 *
 * ZÉRO EST UNE VALEUR LÉGITIME : il rend la chose GRATUITE, il ne la « désactive »
 * pas. C'est ce qui permet d'ouvrir le rapport détaillé pendant un lancement, ou
 * de lever les frais de dossier pour une population qui ne peut pas les payer —
 * sans qu'aucune de ces décisions demande une mise en production.
 *
 * CHAQUE CHANGEMENT EST JOURNALISÉ AVEC L'ANCIEN ET LE NOUVEAU MONTANT. Savoir
 * qu'un prix a bougé ne suffit pas à juger s'il a ouvert ou restreint l'accès au
 * service : c'est l'écart qui le dit, et c'est lui qu'un contrôle relira.
 */
final class PricingController extends Controller
{
    public function __construct(
        private readonly PricingService $tarifs,
        private readonly SettingsRepository $settings,
        private readonly PaystackGateway $paystack,
        private readonly AuditChain $auditChain,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'pricing' => $this->tarifs->all(),
            // L'état de la passerelle accompagne les tarifs : fixer un prix sans
            // moyen d'encaisser produirait un bouton « payer » qui échoue, et
            // l'administrateur n'aurait aucun moyen de le savoir depuis cet écran.
            'paystack' => [
                'configured' => $this->paystack->isConfigured(),
                'notice' => $this->paystack->isConfigured()
                    ? null
                    : 'Aucune clé Paystack n\'est renseignée : tout tarif supérieur à zéro '.
                        'produira un refus au moment de payer. Mettez les montants à zéro, '.
                        'ou renseignez la clé.',
            ],
            // LE SECRET DES AUTRES OPÉRATEURS. Paystack n'en a pas besoin — il
            // signe ses rappels avec la clé secrète ci-dessus. Celui-ci garde
            // l'endpoint générique, et sans lui cet endpoint refuse tout.
            'webhook' => [
                'configured' => $this->secretDeRappelPose(),
                'endpoint' => url('/api/v1/webhooks/payments/paystack'),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'pricing' => ['required', 'array'],
            'pricing.*' => ['required', 'integer', 'min:0', 'max:10000000'],
        ]);

        $administrateur = $request->user();

        if (! $administrateur instanceof User) {
            abort(401);
        }

        $avant = [];

        foreach ($this->tarifs->all() as $tarif) {
            $avant[$tarif['key']] = $tarif['amount_fcfa'];
        }

        /** @var array<string, mixed> $demandes */
        $demandes = $request->array('pricing');
        $changes = [];

        foreach ($demandes as $cle => $montant) {
            if (! in_array($cle, $this->tarifs->keys(), true) || ! is_numeric($montant)) {
                continue;
            }

            $nouveau = max(0, (int) $montant);

            if ($nouveau === ($avant[$cle] ?? null)) {
                continue;
            }

            $this->tarifs->set($cle, $nouveau, $administrateur);
            $changes[$cle] = ['from' => $avant[$cle] ?? null, 'to' => $nouveau];
        }

        if ($changes !== []) {
            $this->auditChain->append(
                ActorType::Agent,
                $administrateur->id,
                'pricing.updated',
                'settings',
                // La chaîne d'audit exige une entité : pour un réglage, c'est
                // l'administrateur qui l'a changé — il n'y a pas d'autre objet.
                $administrateur->id,
                // L'ANCIEN ET LE NOUVEAU, jamais seulement le nouveau.
                ['changes' => $changes],
            );
        }

        return response()->json([
            'pricing' => $this->tarifs->fresh()->all(),
            'changed' => $changes,
        ]);
    }

    /**
     * Enregistre le secret partagé des opérateurs au format maison.
     *
     * IL N'AVAIT AUCUN ÉCRAN, et ne se posait donc qu'en base, à la main.
     * Un secret qu'on ne peut régler que par une console SQL finit par ne pas
     * être réglé du tout — et l'endpoint générique refuse alors tous les
     * rappels, en silence.
     *
     * PAYSTACK N'EN A PAS BESOIN : il signe avec sa propre clé secrète. Ce
     * champ ne concerne que les opérateurs qui exigent un secret distinct.
     */
    public function updateWebhookSecret(Request $request): JsonResponse
    {
        $request->validate(['secret' => ['required', 'string', 'min:16', 'max:200']]);

        $administrateur = $request->user();

        if (! $administrateur instanceof User) {
            abort(401);
        }

        $this->settings->setSecret(
            PaymentWebhookController::SECRET_SETTING,
            $request->string('secret')->toString(),
            $administrateur->id,
        );

        $this->auditChain->append(
            ActorType::Agent,
            $administrateur->id,
            'pricing.webhook_secret_updated',
            'settings',
            $administrateur->id,
            // JAMAIS LE SECRET : la chaîne d'audit est inaltérable.
            ['configured' => true],
        );

        return response()->json(['webhook' => ['configured' => true]]);
    }

    private function secretDeRappelPose(): bool
    {
        $secret = $this->settings->get(PaymentWebhookController::SECRET_SETTING);

        return is_string($secret) && $secret !== '';
    }

    /**
     * Enregistre la clé secrète Paystack.
     *
     * ELLE EST STOCKÉE CHIFFRÉE ET N'EST JAMAIS RELUE en clair par l'API : une
     * clé secrète qu'on peut réafficher est une clé qui fuit au premier accès
     * indu à la console. On ne rend que l'état « renseignée ou non ».
     */
    public function updatePaystack(Request $request): JsonResponse
    {
        $request->validate(['secret_key' => ['required', 'string', 'min:10', 'max:200']]);

        $administrateur = $request->user();

        if (! $administrateur instanceof User) {
            abort(401);
        }

        $this->settings->setSecret(
            PaystackGateway::SECRET_SETTING,
            $request->string('secret_key')->toString(),
            $administrateur->id,
        );

        $this->auditChain->append(
            ActorType::Agent,
            $administrateur->id,
            'pricing.paystack_key_updated',
            'settings',
            $administrateur->id,
            // JAMAIS LA CLÉ, ni son début : la chaîne d'audit est inaltérable, et
            // ce qui y entre y reste.
            ['configured' => true],
        );

        return response()->json(['paystack' => ['configured' => true]]);
    }
}
