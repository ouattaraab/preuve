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

    /**
     * En langage courant (CT-04).
     *
     * L'ORIGINE, JAMAIS L'AUTEUR : « décision d'arbitrage » informe, « décidé
     * par Awa Koné » dénonce (règle métier absolue n° 4). Ces libellés sont
     * lus par un acheteur dans son rapport payé, pas par un développeur : le
     * rapport affichait « owner » là où il fallait lire « le détenteur ».
     */
    public function label(): string
    {
        return match ($this) {
            self::System => 'Automatique',
            self::Owner => 'Une action du détenteur',
            self::Claim => 'Une réclamation',
            self::Arbitration => 'Une décision d\'arbitrage',
            self::Transfer => 'Un transfert de propriété',
            self::Backoffice => 'Une intervention de la plateforme',
        };
    }
}
