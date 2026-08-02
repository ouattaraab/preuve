<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\LifeStatus;
use App\Enums\TriggerType;
use DomainException;

/**
 * Levée quand une transition de statut ne figure pas dans la matrice
 * (règle métier absolue n° 6). Le message reste interne : côté API, il ne doit
 * jamais être renvoyé tel quel — il nomme des codes de statut, que l'interface
 * ne montre jamais (CT-04).
 */
final class TransitionInterditeException extends DomainException
{
    public function __construct(
        public readonly LifeStatus $from,
        public readonly LifeStatus $to,
        public readonly TriggerType $trigger,
    ) {
        parent::__construct(sprintf(
            'Transition de statut interdite : %s → %s déclenchée par « %s ». '.
            'Seules les transitions de la matrice (systemPatterns.md §1) sont possibles.',
            $from->value,
            $to->value,
            $trigger->value,
        ));
    }
}
