<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Asset;
use DomainException;

/**
 * Un enregistrement actif existe déjà pour cet identifiant (règle métier
 * absolue n° 3).
 *
 * Porte le bien existant, car la seule issue offerte à l'utilisateur est sa
 * fiche publique et le parcours de réclamation — jamais une création. L'appelant
 * doit n'en exposer que les données publiques : l'identité du détenteur n'est
 * jamais divulguée (règle métier absolue n° 4).
 */
final class DoublonActifException extends DomainException
{
    public function __construct(public readonly Asset $existant)
    {
        parent::__construct(
            'Ce bien est déjà enregistré sur PREUVE. Consultez sa fiche ; '.
            's\'il vous appartient, ouvrez une réclamation.'
        );
    }
}
