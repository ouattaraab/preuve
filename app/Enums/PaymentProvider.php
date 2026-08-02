<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Opérateurs de paiement retenus.
 *
 * CinetPay est INTERDIT par la stack du projet et n'a délibérément aucune
 * valeur ici : l'énumération, doublée de l'ENUM en base, rend son usage
 * impossible plutôt que simplement déconseillé. Un test le verrouille.
 */
enum PaymentProvider: string
{
    case Paystack = 'paystack';
    case PawapayWave = 'pawapay_wave';
    case PawapayOrangeMoney = 'pawapay_om';
    case PawapayMtnMomo = 'pawapay_momo';

    public function label(): string
    {
        return match ($this) {
            self::Paystack => 'Carte bancaire (Paystack)',
            self::PawapayWave => 'Wave',
            self::PawapayOrangeMoney => 'Orange Money',
            self::PawapayMtnMomo => 'MTN MoMo',
        };
    }

    /** Vrai si l'opérateur est un portefeuille mobile — le cas majoritaire en Côte d'Ivoire. */
    public function isMobileMoney(): bool
    {
        return $this !== self::Paystack;
    }
}
