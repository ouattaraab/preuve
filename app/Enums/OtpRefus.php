<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Motif de refus d'une demande ou d'une vérification de code OTP.
 *
 * Les libellés sont en langage courant (CT-04) et volontairement avares : ils
 * ne disent jamais si un compte existe pour cette destination, sans quoi le
 * parcours de connexion deviendrait un service d'énumération de numéros.
 */
enum OtpRefus: string
{
    case DestinationInvalide = 'destination_invalide';
    case TropDeDemandes = 'trop_de_demandes';
    case Verrouille = 'verrouille';
    case CodeInvalide = 'code_invalide';
    case CodeExpire = 'code_expire';

    public function label(): string
    {
        return match ($this) {
            self::DestinationInvalide => 'Ce numéro de téléphone ne semble pas valide.',
            self::TropDeDemandes => 'Trop de demandes de code. Patientez avant de réessayer.',
            self::Verrouille => 'Trop de tentatives. Réessayez plus tard.',
            self::CodeInvalide => 'Ce code est incorrect.',
            self::CodeExpire => 'Ce code a expiré. Demandez-en un nouveau.',
        };
    }

    /**
     * Code HTTP correspondant. Un refus lié au rythme des demandes ou à un
     * verrouillage est un 429 : le client mobile doit pouvoir distinguer
     * « réessayez plus tard » de « saisie incorrecte » pour ne pas relancer
     * une demande en boucle sur un réseau 3G (CT-05).
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::TropDeDemandes, self::Verrouille => 429,
            self::DestinationInvalide => 422,
            self::CodeInvalide, self::CodeExpire => 401,
        };
    }
}
