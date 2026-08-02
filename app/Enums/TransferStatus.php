<?php

declare(strict_types=1);

namespace App\Enums;

/** État d'un transfert de propriété (§4.4). */
enum TransferStatus: string
{
    case Initiated = 'initiated';
    case BuyerConfirmed = 'buyer_confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'En attente de confirmation',
            self::BuyerConfirmed => "Confirmé par l'acheteur",
            self::Completed => 'Finalisé',
            self::Cancelled => 'Annulé',
            self::Expired => 'Expiré',
        };
    }

    /** Vrai si le transfert peut encore aboutir ou être annulé. */
    public function isOpen(): bool
    {
        return $this === self::Initiated || $this === self::BuyerConfirmed;
    }
}
