<?php

declare(strict_types=1);

namespace App\Enums;

/** Issue de la revue d'un justificatif par un agent (ST-0208). */
enum DocumentReviewStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case SuspectedForgery = 'suspected_forgery';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente de revue',
            self::Accepted => 'Accepté',
            self::Rejected => 'Rejeté',
            self::SuspectedForgery => 'Falsification suspectée',
        };
    }

    /** Vrai si l'issue exige un motif écrit. */
    public function requiresReason(): bool
    {
        return $this === self::Rejected || $this === self::SuspectedForgery;
    }

    /**
     * Vrai si la revue est close. Une pièce en attente ne fait rien monter :
     * le niveau de fiabilité ne doit jamais reposer sur une pièce que personne
     * n'a encore regardée.
     */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
