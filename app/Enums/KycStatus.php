<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * État de la vérification d'identité d'un compte (ST-0103).
 *
 * Le KYC conditionne le niveau « Documenté » (F2), le dépôt d'une réclamation
 * et le transfert de propriété : c'est le point où la friction est
 * légitimement forte (CT-06). Il n'est jamais exigé pour enregistrer un bien ni
 * pour consulter un statut.
 */
enum KycStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Identité non vérifiée',
            self::Pending => 'Vérification en cours',
            self::Verified => 'Identité vérifiée',
            self::Rejected => 'Vérification refusée',
        };
    }

    /** Vrai si une nouvelle soumission est recevable. */
    public function allowsNewSubmission(): bool
    {
        // Une vérification en cours ne se resoumet pas : cela produirait deux
        // dossiers concurrents pour un même compte, et un agent trancherait
        // sur une pièce que l'autre a déjà écartée.
        return $this !== self::Pending;
    }
}
