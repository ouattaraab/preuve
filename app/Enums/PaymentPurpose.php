<?php

declare(strict_types=1);

namespace App\Enums;

/** Objet d'un paiement. */
enum PaymentPurpose: string
{
    case DetailedReport = 'detailed_report';
    case ClaimFee = 'claim_fee';
    case AssetSlot = 'asset_slot';
    case FleetSubscription = 'fleet_subscription';

    public function label(): string
    {
        return match ($this) {
            self::DetailedReport => 'Rapport détaillé',
            self::ClaimFee => 'Frais de dossier de réclamation',
            self::AssetSlot => 'Enregistrement de bien supplémentaire',
            self::FleetSubscription => 'Abonnement flotte',
        };
    }
}
