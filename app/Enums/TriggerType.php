<?php

declare(strict_types=1);

namespace App\Enums;

/** Origine d'une transition de statut, journalisée dans asset_status_history. */
enum TriggerType: string
{
    case System = 'system';
    case Owner = 'owner';
    case Claim = 'claim';
    case Arbitration = 'arbitration';
    case Transfer = 'transfer';
    case Backoffice = 'backoffice';
}
