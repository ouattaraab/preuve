<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\LifeStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentPurpose;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use App\Services\PaymentCheckout;
use App\Services\PaymentService;
use App\Services\PricingService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Le péage de déclaration de vol, quand l'exploitant en a ouvert un (ST-0604).
 *
 * CE TARIF VAUT ZÉRO PAR DÉFAUT, ET CE DÉFAUT EST UNE POSITION. Déclarer un vol
 * est ce qui rend un bien invendable ; celui qui déclare vient de se faire
 * dépouiller, et chaque obstacle est une déclaration qui n'aura pas lieu. La
 * valeur du registre vient de sa COUVERTURE : moins de vols déclarés, c'est un
 * registre moins fiable pour les acheteurs, donc un rapport détaillé qui vaut
 * moins cher. Le péage existe, il est réglable, et il reste fermé tant qu'un
 * exploitant ne l'ouvre pas sciemment.
 *
 * L'ORDRE EST : PAIEMENT, PUIS CODE. Le code de confirmation part quand
 * l'opérateur a confirmé, pas avant — envoyer un code d'abord ferait entrer un
 * délai d'expiration dans un parcours qui traverse une page bancaire, et le
 * code aurait péri avant le retour. C'est `PaymentService::fulfill()` qui
 * l'émet, sur le chemin du webhook.
 *
 * CE CONTRÔLEUR NE DÉCLARE RIEN. Il annonce le prix et ouvre la caisse ; le
 * changement de statut reste à `AssetLifecycleService`, derrière le code. Un
 * paiement n'est pas une preuve d'identité.
 */
final class TheftDeclarationFeeController extends Controller
{
    public function __construct(
        private readonly PricingService $tarifs,
        private readonly PaymentService $paiements,
        private readonly PaymentCheckout $caisse,
    ) {}

    /** Ce que la déclaration coûte à cet utilisateur, pour ce bien, maintenant. */
    public function show(Request $request, int $asset): JsonResponse
    {
        $detenteur = $this->utilisateur($request);
        $bien = $this->bienDuDetenteur($request, $asset);

        return response()->json($this->etat($bien, $detenteur));
    }

    /** Ouvre le paiement qui débloquera l'envoi du code. */
    public function store(Request $request, int $asset): JsonResponse
    {
        $detenteur = $this->utilisateur($request);
        $bien = $this->bienDuDetenteur($request, $asset);
        $montant = $this->montant();

        // GRATUIT VEUT DIRE GRATUIT : rien à ouvrir, le parcours ordinaire
        // reprend la main et demandera le code comme il l'a toujours fait.
        if ($montant === 0) {
            return response()->json([
                'payment_required' => false,
                'message' => 'La déclaration de vol est gratuite. Demande ton code de confirmation.',
            ] + $this->etat($bien, $detenteur));
        }

        if ($this->paiements->hasPaidFor($detenteur, $bien, PaymentPurpose::TheftDeclaration)) {
            return response()->json([
                'payment_required' => false,
                'message' => 'Paiement déjà reçu. Demande ton code de confirmation.',
            ] + $this->etat($bien, $detenteur));
        }

        // ON REFUSE DE FAIRE PAYER POUR RIEN. Un bien déjà déclaré volé n'a pas
        // besoin d'un second règlement : l'encaisser puis ne rien changer
        // serait un prélèvement sans contrepartie.
        if ($bien->life_status === LifeStatus::Stolen) {
            throw ValidationException::withMessages([
                'asset' => 'Ce bien est déjà déclaré volé. Il n\'y a rien à régler.',
            ]);
        }

        $request->validate(['provider' => ['sometimes', 'string']]);

        $operateur = PaymentProvider::tryFrom($request->string('provider')->toString())
            ?? PaymentProvider::Paystack;

        try {
            $ouverture = $this->caisse->open(
                $detenteur,
                $bien,
                $operateur,
                PaymentPurpose::TheftDeclaration,
                $montant,
                url('/api/v1/assets/'.$bien->id.'/theft-fee'),
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }

        return response()->json([
            'payment_required' => true,
            'fee_fcfa' => $montant,
            'checkout_url' => $ouverture['checkout_url'],
            'message' => 'Règle le montant : ton code de confirmation part dès que '
                .'l\'opérateur aura confirmé le paiement.',
        ], 201);
    }

    /** @return array<string, mixed> */
    private function etat(Asset $bien, User $detenteur): array
    {
        $montant = $this->montant();
        $regle = $montant === 0
            || $this->paiements->hasPaidFor($detenteur, $bien, PaymentPurpose::TheftDeclaration);

        return [
            'fee_fcfa' => $montant,
            'free' => $montant === 0,
            'paid' => $regle,
            'already_stolen' => $bien->life_status === LifeStatus::Stolen,
            // CE QUI RESTE GRATUIT, DIT ICI. Sans cette phrase, un utilisateur
            // devant un tarif croirait que sans payer son bien n'est protégé
            // par rien — et renoncerait à un enregistrement qui vaut déjà.
            'explanation' => $montant === 0
                ? 'Déclarer un vol est gratuit.'
                : 'Le règlement couvre la déclaration elle-même. Ton bien reste enregistré '
                    .'et consultable dans tous les cas ; c\'est l\'alerte « volé », visible de '
                    .'quiconque vérifie son numéro, qui est soumise à ce montant.',
        ];
    }

    private function montant(): int
    {
        return $this->tarifs->amount('theft_declaration');
    }

    private function bienDuDetenteur(Request $request, int $asset): Asset
    {
        $bien = Asset::query()
            ->whereKey($asset)
            // 404 ET NON 403 : distinguer « ce bien n'existe pas » de « il
            // n'est pas à vous » permettrait d'énumérer le registre.
            ->where('owner_user_id', $this->utilisateur($request)->id)
            ->first();

        if (! $bien instanceof Asset) {
            abort(404);
        }

        return $bien;
    }

    private function utilisateur(Request $request): User
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        return $utilisateur;
    }
}
