<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Le quota d'enregistrement est épuisé (ST-0804, décision produit du
 * 03/08/2026).
 *
 * PORTE L'ÉTAT COMPLET DU QUOTA, et pas seulement un refus. Un client qui
 * reçoit « non » sans savoir combien de places il a, ce que coûte la suivante
 * ni comment la régler ne peut rien faire d'autre qu'abandonner — et
 * l'abandon, ici, veut dire un bien qui reste non enregistré.
 *
 * Le refus se distingue d'une erreur de saisie : il n'y a rien à corriger dans
 * la demande, elle est valide et le restera. C'est pourquoi il ressort en 402
 * et non en 422.
 */
final class QuotaEpuiseException extends RuntimeException
{
    /** @param array<string, mixed> $quota */
    public function __construct(public readonly array $quota)
    {
        parent::__construct(
            'Vos enregistrements gratuits sont utilisés. Ajoutez une place pour enregistrer ce bien.'
        );
    }
}
