<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rôle d'un collaborateur dans une flotte (ST-0705).
 *
 * LA DÉLÉGATION S'ARRÊTE AUX ACTES DE PROPRIÉTÉ. Un opérateur de comptoir
 * marque un véhicule en location vingt fois par jour ; il n'a aucune raison de
 * pouvoir le céder, le déclarer hors d'usage ou changer l'abonnement. Confondre
 * « gérer la flotte au quotidien » et « disposer des biens » ferait d'un vol de
 * téléphone d'employé une perte de parc.
 */
enum CompanyRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur de flotte',
            self::Operator => 'Opérateur',
        };
    }

    /** Marquer, démarquer, importer : le quotidien du comptoir. */
    public function managesFleet(): bool
    {
        return true;
    }

    /** Inviter ou révoquer un collaborateur. */
    public function managesMembers(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Céder un véhicule, le déclarer hors d'usage, régler l'abonnement.
     *
     * Réservé au représentant légal, jamais délégué — pas même à un
     * administrateur de flotte : disposer d'un bien engage la société.
     */
    public function disposesOfAssets(): bool
    {
        return false;
    }
}
