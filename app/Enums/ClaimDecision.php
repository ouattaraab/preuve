<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Issues d'un arbitrage (§5.2). Trois, et seulement trois.
 *
 * « Litige non tranché » n'est pas un échec de la plateforme : c'est la
 * reconnaissance honnête que les preuves ne départagent pas les parties. Forcer
 * une décision dans ce cas reviendrait à trancher un droit de propriété sur une
 * intime conviction — ce qui appartient au juge, pas à un opérateur privé.
 */
enum ClaimDecision: string
{
    case TransferToClaimant = 'transfer_to_claimant';
    case KeepCurrent = 'keep_current';
    case Unresolved = 'unresolved';

    public function label(): string
    {
        return match ($this) {
            self::TransferToClaimant => 'Transfert au réclamant',
            self::KeepCurrent => 'Maintien du détenteur actuel',
            self::Unresolved => 'Litige non tranché',
        };
    }

    public function publicMessage(): string
    {
        return match ($this) {
            self::TransferToClaimant => 'La réclamation est fondée : le bien revient au réclamant.',
            self::KeepCurrent => 'La réclamation n\'est pas fondée : le détenteur enregistré est confirmé.',
            self::Unresolved => 'Les preuves ne départagent pas les parties. Le bien reste gelé et le litige '.
                'relève désormais de la justice : l\'export horodaté vous est remis à cette fin.',
        };
    }
}
