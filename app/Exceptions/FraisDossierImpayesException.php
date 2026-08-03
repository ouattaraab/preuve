<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Les frais de dossier n'ont pas été réglés (ST-0501, décision produit du
 * 03/08/2026).
 *
 * PORTE LE MONTANT ET SON ÉTAT, pas seulement un refus : un réclamant qui
 * reçoit « non » sans savoir combien ni comment régler n'a d'autre issue que
 * l'abandon — et l'abandon, ici, veut dire une victime qui renonce à son seul
 * recours.
 *
 * Ressort en 402 : la demande est valide et le restera, il n'y a rien à
 * corriger dans le dossier.
 */
final class FraisDossierImpayesException extends RuntimeException
{
    /** @param array<string, mixed> $frais */
    public function __construct(public readonly array $frais)
    {
        parent::__construct(
            'Les frais de dossier doivent être réglés avant le dépôt. Ils vous seront remboursés si '.
            'votre réclamation aboutit.'
        );
    }
}
