<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\NotificationType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Asset;
use App\Models\AssetStatusHistory;
use App\Models\Payment;
use App\Models\ReportPurchase;
use App\Models\User;
use DomainException;
use Illuminate\Support\Str;

/**
 * Rapport détaillé payant : accès et contenu (ST-0801 à ST-0803).
 *
 * DEUX RÈGLES GOUVERNENT CE SERVICE, ET ELLES TIRENT EN SENS CONTRAIRE.
 *
 * Personne n'accède au rapport sans être identifié (règle métier absolue n° 7) :
 * compte connecté, ou nom + e-mail + téléphone vérifié par code AVANT paiement.
 * Un rapport anonyme deviendrait l'outil de repérage idéal — on paierait
 * quelques centaines de francs pour savoir combien de fois un bien a changé de
 * mains et s'il a été déclaré volé.
 *
 * Et pourtant le propriétaire n'apprend RIEN de l'acheteur (règle métier
 * absolue n° 4), et l'acheteur n'apprend rien du propriétaire. L'anonymat est
 * symétrique : payer n'achète pas le droit de savoir QUI, seulement le droit de
 * savoir QUOI. C'est ce qui distingue un registre de confiance d'un service de
 * renseignement privé.
 *
 * Le rapport dit donc : combien de détenteurs, à quelles dates, quels
 * incidents, quel niveau de fiabilité. Jamais un nom, jamais un numéro, jamais
 * un identifiant de compte.
 */
final class ReportAccessService
{
    /** Durée de validité d'un accès acheté (§4.7). */
    private const ACCESS_DAYS = 30;

    public function __construct(
        private readonly AuditChain $auditChain,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Ouvre l'accès une fois le paiement abouti. Idempotent : un webhook rejoué
     * ne crée pas un second accès.
     *
     * @throws DomainException
     */
    /**
     * Accès accordé SANS paiement, quand le tarif est à zéro.
     *
     * LE JETON RESTE LA SEULE PORTE, gratuit ou non : c'est lui qui borne
     * l'accès dans le temps et qui permet d'ouvrir le rapport sur un autre
     * appareil. Rendre le rapport « en direct » parce qu'il est gratuit
     * créerait un second chemin de lecture, qui finirait par diverger du
     * premier — et l'écart ne se verrait que sur ce qu'il ne faut pas montrer.
     *
     * Aucune ligne de paiement n'est créée : il n'y a rien à réconcilier, et
     * un paiement de zéro franc dans le journal comptable serait un mensonge.
     */
    public function grantFree(Asset $bien, ?User $acheteur): ReportPurchase
    {
        return ReportPurchase::create([
            'asset_id' => $bien->id,
            'payment_id' => null,
            'user_id' => $acheteur?->id,
            'access_token' => Str::random(40),
            'expires_at' => now()->addDays(self::ACCESS_DAYS),
        ]);
    }

    public function grant(Payment $paiement, Asset $bien): ReportPurchase
    {
        if ($paiement->status !== PaymentStatus::Succeeded) {
            throw new DomainException("L'accès au rapport suppose un paiement abouti.");
        }

        if ($paiement->purpose !== PaymentPurpose::DetailedReport) {
            throw new DomainException('Ce paiement ne porte pas sur un rapport détaillé.');
        }

        $existant = ReportPurchase::where('payment_id', $paiement->id)->first();

        if ($existant instanceof ReportPurchase) {
            return $existant;
        }

        $achat = ReportPurchase::create([
            'asset_id' => $bien->id,
            'payment_id' => $paiement->id,
            'user_id' => $paiement->user_id,
            // 40 caractères tirés d'une source cryptographique : le jeton est
            // le seul rempart devant le rapport, un identifiant devinable
            // l'ouvrirait à tous.
            'access_token' => Str::random(40),
            'expires_at' => now()->addDays(self::ACCESS_DAYS),
        ]);

        $this->auditChain->append(
            ActorType::User,
            $paiement->user_id,
            'report.purchased',
            'asset',
            $bien->id,
            [
                'payment_id' => $paiement->id,
                'amount_fcfa' => $paiement->amount_fcfa,
                // Ni le nom, ni l'e-mail, ni le téléphone de l'acheteur : la
                // chaîne d'audit est inaltérable, et l'anonymat de l'acheteur
                // doit survivre à toute relecture ultérieure.
            ],
        );

        $this->announceToHolder($bien);

        return $achat;
    }

    /**
     * Ouvre le rapport à partir de son jeton, et compte l'accès.
     *
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    public function read(string $jeton): array
    {
        $achat = ReportPurchase::where('access_token', $jeton)->first();

        if (! $achat instanceof ReportPurchase) {
            throw new DomainException('Ce lien de rapport est invalide.');
        }

        if (! $achat->isUsable()) {
            throw new DomainException(
                'Ce lien a expiré. Un rapport reste accessible '.self::ACCESS_DAYS.' jours après son achat.'
            );
        }

        $bien = $achat->asset;

        if (! $bien instanceof Asset) {
            throw new DomainException('Le bien de ce rapport est introuvable.');
        }

        $achat->forceFill([
            'first_access_at' => $achat->first_access_at ?? now(),
            'access_count' => $achat->access_count + 1,
        ])->save();

        return $this->compose($bien, $achat);
    }

    /**
     * Compose le rapport. Construit à partir de champs sûrs plutôt qu'en
     * retirant des champs sensibles d'un modèle complet : une liste
     * d'exclusions se périme silencieusement à chaque colonne ajoutée, et ici
     * l'oubli coûterait l'anonymat d'une personne.
     *
     * @return array<string, mixed>
     */
    private function compose(Asset $bien, ReportPurchase $achat): array
    {
        $chaine = Asset::query()
            ->where('identifier_normalized', $bien->identifier_normalized)
            ->orderBy('registered_at')
            ->get();

        $historique = AssetStatusHistory::query()
            ->whereIn('asset_id', $chaine->pluck('id'))
            ->orderBy('created_at')
            ->get();

        return [
            'asset' => [
                'public_ref' => $bien->public_ref,
                'category' => $bien->asset_category_key,
                'identifier_type' => $bien->identifier_type,
                'life_status' => [
                    'code' => $bien->life_status->value,
                    'label' => $bien->life_status->label(),
                    'message' => $bien->life_status->publicMessage(),
                    'warning' => $bien->life_status->isPublicWarning(),
                ],
                'trust_level' => [
                    'code' => $bien->trust_level->value,
                    'label' => $bien->trust_level->label(),
                ],
                'registered_at' => $bien->registered_at->toIso8601String(),
            ],
            // Le NOMBRE de détenteurs et les dates de changement, jamais leur
            // identité : c'est ce qui permet à un acheteur de juger un bien
            // qui a changé quatre fois de mains en un an, sans lui livrer les
            // personnes.
            'ownership' => [
                'holders_count' => $chaine->count(),
                'changed_at' => $chaine->skip(1)->map(
                    fn (Asset $enregistrement): string => $enregistrement->registered_at->toIso8601String()
                )->values()->all(),
                'first_registered_at' => $chaine->first()?->registered_at->toIso8601String(),
            ],
            'history' => $historique->map(fn (AssetStatusHistory $ligne): array => [
                'from_status' => $ligne->from_status?->value,
                'to_status' => $ligne->to_status->value,
                'to_status_label' => $ligne->to_status->label(),
                'from_trust' => $ligne->from_trust?->value,
                'to_trust' => $ligne->to_trust?->value,
                // L'origine de la transition, jamais son auteur : « décision
                // d'arbitrage » informe, « décidé par Awa Koné » dénonce.
                'trigger' => $ligne->trigger_type->value,
                'trigger_label' => $ligne->trigger_type->label(),
                'at' => $ligne->created_at?->toIso8601String(),
            ])->all(),
            'incidents' => [
                'stolen_declared_at' => $bien->stolen_declared_at?->toIso8601String(),
                'stolen_consolidated' => $bien->stolen_consolidated,
                'currently_disputed' => $bien->life_status->value === 'V-LIT',
            ],
            'access' => [
                'expires_at' => $achat->expires_at->toIso8601String(),
                'access_count' => $achat->access_count,
            ],
        ];
    }

    /**
     * Prévient le détenteur qu'un rapport a été acheté sur son bien (ST-0803).
     *
     * Le signal a de la valeur : quelqu'un se renseigne sérieusement, donc une
     * transaction se prépare — peut-être sans lui. Mais il n'apprend jamais
     * qui : l'inverse ferait du rapport un moyen de se faire identifier par le
     * propriétaire, et découragerait précisément l'acheteur prudent que le
     * produit veut servir.
     */
    private function announceToHolder(Asset $bien): void
    {
        $detenteur = $bien->owner;

        if (! $detenteur instanceof User) {
            return;
        }

        $this->notifications->notify(
            $detenteur,
            NotificationType::AssetReportPurchased,
            'Un rapport détaillé a été acheté sur votre bien',
            'Quelqu\'un s\'est renseigné en profondeur sur ce bien : une transaction se prépare peut-être. '.
            'Les achats de rapport sont anonymes : PREUVE ne vous dira jamais qui achète.',
            $bien,
        );
    }
}
