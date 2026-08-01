<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut de vie d'un bien. Les valeurs sont les codes stockés en base ;
 * les libellés sont ceux affichés à l'utilisateur — jamais les codes (CT-04).
 */
enum LifeStatus: string
{
    case Active = 'V-ACT';
    case Provisional = 'V-PRV';
    case Rented = 'V-LOC';
    case Transferring = 'V-VTE';
    case Stolen = 'V-VOL';
    case Disputed = 'V-LIT';
    case EndOfLife = 'V-FDV';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Provisional => 'Enregistrement récent',
            self::Rented => 'En location',
            self::Transferring => 'Transfert en cours',
            self::Stolen => 'Volé déclaré',
            self::Disputed => 'Litige en cours',
            self::EndOfLife => "Hors d'usage",
        };
    }

    /** Message complet affiché sur le verdict public. */
    public function publicMessage(): string
    {
        return match ($this) {
            self::Active => 'Aucun signalement sur ce bien.',
            self::Provisional => 'Enregistrement récent, fenêtre de contestation en cours.',
            self::Rented => 'Bien de location — une vente est frauduleuse.',
            self::Transferring => 'Un transfert de propriété est en cours.',
            self::Stolen => "Volé déclaré — n'achetez pas ce bien.",
            self::Disputed => 'Propriété contestée — litige en cours.',
            self::EndOfLife => "Bien déclaré hors d'usage.",
        };
    }

    /** Couleur du design system DJASSA. */
    public function color(): string
    {
        return match ($this) {
            self::Active => '#1E8A4C',
            self::Provisional, self::EndOfLife => '#5C6470',
            self::Rented => '#C77700',
            self::Transferring => '#1D4ED8',
            self::Stolen, self::Disputed => '#C62F21',
        };
    }

    /** Vrai si le statut doit alerter l'acheteur avant une transaction. */
    public function isPublicWarning(): bool
    {
        return in_array($this, [self::Stolen, self::Disputed, self::Rented], true);
    }
}
