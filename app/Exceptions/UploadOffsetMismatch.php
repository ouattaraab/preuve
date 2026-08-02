<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Le client a envoyé un morceau à la mauvaise position (ST-0206).
 *
 * PORTE LA POSITION RÉELLE, et c'est tout son intérêt. Un client qui a perdu
 * le fil — application tuée par le système, réponse perdue avant d'être lue,
 * deux réessais partis en même temps — n'a pas besoin qu'on lui dise qu'il a
 * tort : il a besoin de savoir où reprendre. Sans cette valeur, la seule issue
 * serait de renvoyer les quatre mégaoctets depuis le début, en 3G.
 */
final class UploadOffsetMismatch extends RuntimeException
{
    public function __construct(public readonly int $expectedOffset)
    {
        parent::__construct(
            'Ce morceau ne suit pas ce qui a déjà été reçu. Reprenez à la position indiquée.'
        );
    }
}
