<?php

declare(strict_types=1);

namespace App\Enums;

/** Niveau de fiabilité d'un enregistrement (§3.1 du PRD). */
enum TrustLevel: string
{
    case Declared = 'F1';
    case Documented = 'F2';
    case Verified = 'F3';

    public function label(): string
    {
        return match ($this) {
            self::Declared => 'Déclaré, non vérifié',
            self::Documented => 'Documenté',
            self::Verified => 'Vérifié',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Declared => '#5C6470',
            self::Documented => '#1D4ED8',
            self::Verified => '#1E8A4C',
        };
    }

    /**
     * Vrai si l'enregistrement n'est appuyé par aucun justificatif.
     * Détermine l'éligibilité à la bascule gratuite au mieux-documenté (§6 de la spec).
     */
    public function isBareDeclaration(): bool
    {
        return $this === self::Declared;
    }
}
