<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rôle d'un compte dans le back-office.
 *
 * Aligné sur ActorType, qui distingue déjà l'utilisateur de l'agent dans la
 * chaîne d'audit : un agent instruit les dossiers (revue des justificatifs
 * ST-0208, arbitrage des réclamations), un administrateur configure la
 * plateforme elle-même. Les deux pouvoirs sont distincts — instruire un
 * litige ne donne aucune raison de changer le fournisseur SMS.
 */
enum UserRole: string
{
    case User = 'user';
    case Agent = 'agent';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Utilisateur',
            self::Agent => 'Agent',
            self::Admin => 'Administrateur',
        };
    }

    /** Vrai si le rôle donne accès au back-office, à quelque titre que ce soit. */
    public function hasBackOfficeAccess(): bool
    {
        return $this !== self::User;
    }
}
