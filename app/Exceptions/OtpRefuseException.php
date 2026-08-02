<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\OtpRefus;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Refus d'une demande ou d'une vérification de code OTP.
 *
 * Le message porté est celui destiné à l'utilisateur, en langage courant : il
 * ne contient jamais le code attendu, ni le nombre de tentatives restantes, ni
 * l'existence d'un compte pour cette destination.
 */
final class OtpRefuseException extends DomainException
{
    public function __construct(
        public readonly OtpRefus $raison,
        public readonly ?Carbon $reessayerApres = null,
    ) {
        parent::__construct($raison->label());
    }

    /** Secondes à attendre avant une nouvelle tentative, pour l'en-tête Retry-After. */
    public function retryAfterSeconds(): ?int
    {
        if ($this->reessayerApres === null) {
            return null;
        }

        return max(1, (int) ceil(now()->diffInSeconds($this->reessayerApres, true)));
    }
}
