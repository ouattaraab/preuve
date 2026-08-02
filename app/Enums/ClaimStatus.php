<?php

declare(strict_types=1);

namespace App\Enums;

/** Étapes d'une réclamation, du brouillon à la clôture (§4.5). */
enum ClaimStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Admissible = 'admissible';
    case Contradictory = 'contradictory';
    case UnderReview = 'under_review';
    case Decided = 'decided';
    case Appealed = 'appealed';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Submitted => 'Déposée',
            self::Admissible => 'Recevable',
            self::Contradictory => 'Contradictoire en cours',
            self::UnderReview => 'En instruction',
            self::Decided => 'Décidée',
            self::Appealed => 'En appel',
            self::Closed => 'Clôturée',
        };
    }

    /** Vrai si des pièces peuvent encore être versées au dossier. */
    public function acceptsEvidence(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::Admissible, self::Contradictory], true);
    }
}
