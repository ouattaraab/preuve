<?php

declare(strict_types=1);

namespace App\Enums;

/** État d'un paiement. */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Succeeded => 'Payé',
            self::Failed => 'Échoué',
            self::Refunded => 'Remboursé',
        };
    }

    /** Vrai si l'état est définitif : un webhook tardif ne doit plus le changer. */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
