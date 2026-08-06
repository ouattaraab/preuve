<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PaymentPurpose;
use DomainException;

/**
 * Un geste réclame un règlement qui n'a pas eu lieu.
 *
 * POURQUOI UNE EXCEPTION DISTINCTE, et non un simple refus. « Payez d'abord »
 * n'est pas une erreur de saisie : l'utilisateur n'a rien fait de mal, il n'a
 * rien à corriger, et lui rendre un 422 avec un message dans un champ de
 * formulaire le laisserait chercher ce qu'il a mal rempli. C'est un état du
 * dossier, pas un défaut de la requête — d'où le 402, et d'où le montant porté
 * par l'exception elle-même : le client a besoin du chiffre pour l'annoncer
 * avant d'ouvrir la page de l'opérateur.
 *
 * ELLE HÉRITE DE `DomainException` À DESSEIN. Les contrôleurs existants
 * rattrapent déjà `DomainException` ; sans cet héritage, un chemin oublié
 * laisserait remonter une erreur 500 là où il fallait une demande de paiement.
 * Le contrôleur qui sait faire mieux la rattrape AVANT — l'ordre des `catch`
 * est ce qui distingue les deux.
 */
final class PaymentRequiredException extends DomainException
{
    public function __construct(
        public readonly PaymentPurpose $purpose,
        public readonly int $amountFcfa,
        string $message,
    ) {
        parent::__construct($message);
    }
}
