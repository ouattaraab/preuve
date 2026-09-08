<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\NotificationType;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Abonnements de flotte : relances et suspension douce (ST-0805).
 *
 * LA SUSPENSION NE RETIRE JAMAIS LA PROTECTION ACQUISE. Une flotte en retard de
 * paiement ne peut plus ajouter de véhicules, mais ceux qu'elle a déjà
 * enregistrés restent protégés, consultables et surveillés. Couper la
 * protection punirait les véhicules et non le débiteur : un acheteur consultant
 * un véhicule volé recevrait « bien inconnu » parce que son propriétaire a du
 * retard — et la plateforme perdrait exactement ce qui fait sa valeur.
 *
 * LES RELANCES PRÉCÈDENT LA SUSPENSION. Trois rappels avant fermeture de
 * l'ajout : une facture peut se perdre, un responsable peut changer, un virement
 * peut traîner. Suspendre au premier jour de retard ferait perdre un client sur
 * un malentendu administratif.
 */
final class SubscriptionService
{
    /** Jours de retard déclenchant chaque relance, puis la lecture seule. */
    private const DUNNING_DAYS = [3, 10, 20];

    private const READ_ONLY_DAYS = 30;

    public function __construct(
        private readonly QuotaService $quotas,
        private readonly AuditChain $auditChain,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Fait avancer une flotte dans le parcours de relance selon son retard.
     *
     * @return bool vrai si l'état a changé
     */
    public function advance(Company $societe): bool
    {
        $echeance = $societe->subscription_due_at;

        if ($echeance === null || $echeance->isFuture()) {
            return false;
        }

        $retard = (int) floor($echeance->diffInDays(now(), true));

        if ($retard >= self::READ_ONLY_DAYS) {
            return $this->suspend($societe);
        }

        return $this->remind($societe, $retard);
    }

    /**
     * Bascule en lecture seule : l'ajout de véhicules se ferme, la protection
     * des véhicules existants reste entière.
     */
    private function suspend(Company $societe): bool
    {
        if ($societe->subscription_status === SubscriptionStatus::ReadOnly) {
            return false;
        }

        $this->auditChain->append(
            ActorType::System,
            null,
            'subscription.read_only',
            'company',
            $societe->id,
            ['days_overdue' => self::READ_ONLY_DAYS],
        );

        $societe->forceFill(['subscription_status' => SubscriptionStatus::ReadOnly])->save();

        $this->notify(
            $societe,
            'Ajout de véhicules suspendu',
            'Faute de règlement, vous ne pouvez plus ajouter de véhicules. Ceux déjà enregistrés restent '.
            'protégés, consultables et surveillés — nous ne coupons jamais la protection d\'un véhicule.',
        );

        return true;
    }

    private function remind(Company $societe, int $retard): bool
    {
        $aEnvoyer = 0;

        foreach (self::DUNNING_DAYS as $index => $jour) {
            if ($retard >= $jour) {
                $aEnvoyer = $index + 1;
            }
        }

        if ($aEnvoyer === 0 || $aEnvoyer <= $societe->dunning_count) {
            return false;
        }

        $du = $this->quotas->forCompany($societe);
        $restants = max(0, self::READ_ONLY_DAYS - $retard);

        $societe->forceFill([
            'subscription_status' => SubscriptionStatus::PastDue,
            'dunning_count' => $aEnvoyer,
            'last_dunning_at' => now(),
        ])->save();

        $this->notify(
            $societe,
            'Abonnement en retard de paiement',
            "Votre abonnement de {$du['monthly_fcfa']} FCFA par mois ({$du['billable']} véhicule(s) facturé(s)) ".
            "reste impayé depuis {$retard} jours. Vos véhicules restent protégés ; passé {$restants} jours, ".
            'vous ne pourrez plus en ajouter de nouveaux.',
        );

        return true;
    }

    /** Rétablit une flotte après règlement. */
    public function markPaid(Company $societe, ?int $moisSuivants = 1): Company
    {
        $this->auditChain->append(
            ActorType::System,
            null,
            'subscription.paid',
            'company',
            $societe->id,
            ['months' => $moisSuivants],
        );

        $societe->forceFill([
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_due_at' => now()->addMonths(max(1, (int) $moisSuivants)),
            'dunning_count' => 0,
            'last_dunning_at' => null,
        ])->save();

        return $societe->fresh() ?? $societe;
    }

    /**
     * Produit le décompte mensuel remis au loueur.
     *
     * Document texte plutôt que PDF : le rendu importe moins que le contenu, et
     * les mentions fiscales exigibles en Côte d'Ivoire — régime, numéro de
     * contribuable, TVA applicable — doivent être validées par un comptable
     * avant d'être imprimées sur une facture opposable. Ce décompte n'en tient
     * pas lieu et le dit.
     */
    public function statement(Company $societe): string
    {
        $du = $this->quotas->forCompany($societe);

        $texte = implode("\n", [
            'PREUVE — décompte mensuel',
            '=========================',
            '',
            'Société           : '.$societe->legal_name,
            'RCCM              : '.$societe->rccm_number,
            'Période           : '.now()->format('m/Y'),
            'État              : '.$societe->subscription_status->label(),
            '',
            'Véhicules enregistrés : '.$du['fleet_size'],
            'Dont gratuits         : '.$du['free_quota'],
            'Facturés              : '.$du['billable'],
            'Prix unitaire         : '.$du['unit_price_fcfa'].' FCFA / véhicule / mois',
            'TOTAL                 : '.$du['monthly_fcfa'].' FCFA',
            '',
            'Ce décompte n\'est pas une facture fiscale. Les mentions exigibles',
            '(régime d\'imposition, numéro de contribuable, TVA) restent à porter',
            'sur la facture émise par la comptabilité.',
        ]);

        $chemin = 'statements/'.$societe->id.'/'.now()->format('Y-m').'.txt';
        Storage::disk($this->disk())->put($chemin, $texte);

        return $texte;
    }

    private function notify(Company $societe, string $titre, string $corps): void
    {
        $representant = $societe->owner;

        if ($representant instanceof User) {
            $this->notifications->notify($representant, NotificationType::System, $titre, $corps);
        }
    }

    private function disk(): string
    {
        $disque = config('preuve.documents.disk');

        return is_string($disque) && $disque !== '' ? $disque : 's3';
    }
}
