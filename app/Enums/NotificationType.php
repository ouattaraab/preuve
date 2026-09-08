<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Types d'événements notifiables.
 *
 * Chaque type porte lui-même sa criticité et son caractère désactivable, plutôt
 * que de laisser ces règles se disperser dans le service ou l'interface : c'est
 * ici, et nulle part ailleurs, qu'on décide qu'une tentative d'enregistrement
 * frauduleux ne se coupe pas.
 */
enum NotificationType: string
{
    case AssetLookup = 'asset_lookup';
    case AssetReportPurchased = 'asset_report_purchased';
    case DuplicateAttempt = 'duplicate_attempt';
    case LookupSpike = 'lookup_spike';
    case ClaimOpened = 'claim_opened';
    case ClaimDecided = 'claim_decided';
    case TransferInvitation = 'transfer_invitation';
    case TransferCompleted = 'transfer_completed';
    case StatusChange = 'status_change';
    case KycResult = 'kyc_result';
    case System = 'system';

    /**
     * Événements critiques : ils touchent à la propriété du bien ou à une
     * fraude en cours. Seuls ceux-là justifient un SMS — le canal coûte, et un
     * usage banalisé apprendrait aux propriétaires à ignorer les alertes.
     */
    public function isCritical(): bool
    {
        return in_array($this, [
            self::DuplicateAttempt,
            self::LookupSpike,
            self::ClaimOpened,
            self::ClaimDecided,
            self::TransferInvitation,
        ], true);
    }

    /**
     * Vrai si l'utilisateur peut cesser de recevoir ce type (ST-0107).
     *
     * Les événements critiques ne sont PAS désactivables : couper l'alerte
     * d'une tentative d'enregistrement frauduleux ou l'ouverture d'une
     * réclamation sur son propre bien reviendrait à laisser un propriétaire
     * perdre son bien sans jamais l'apprendre. L'opt-out porte alors sur
     * l'interruption — push, SMS — jamais sur la trace au centre de
     * notifications.
     */
    public function isOptional(): bool
    {
        return ! $this->isCritical();
    }

    public function label(): string
    {
        return match ($this) {
            self::AssetLookup => 'Consultations de vos biens',
            self::AssetReportPurchased => 'Achat de rapport sur vos biens',
            self::DuplicateAttempt => "Tentative d'enregistrement de votre identifiant",
            self::LookupSpike => 'Pic de consultations',
            self::ClaimOpened => 'Réclamation ouverte',
            self::ClaimDecided => 'Décision de réclamation',
            self::TransferInvitation => 'Invitation de transfert',
            self::TransferCompleted => 'Transfert finalisé',
            self::StatusChange => 'Changement de statut',
            self::KycResult => "Résultat de vérification d'identité",
            self::System => 'Messages de la plateforme',
        };
    }
}
