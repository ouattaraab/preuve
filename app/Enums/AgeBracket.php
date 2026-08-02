<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * Tranches d'ancienneté affichées sur le verdict public (ST-0404).
 *
 * DES TRANCHES, PAS DES DATES. Une date exacte de création de compte, recoupée
 * avec ce qu'un acheteur sait par ailleurs — la personne en face de lui, ce
 * qu'elle raconte —, aiderait à l'identifier. C'est exactement ce que
 * l'anonymat du déclarant interdit (règle métier absolue n° 4). La tranche
 * donne le signal utile en retirant la précision qui permet le recoupement.
 *
 * Le seuil de la semaine existe pour une raison précise : un bien enregistré il
 * y a trois jours par un compte créé il y a trois jours est le profil exact
 * d'un vol récent qu'on cherche à revendre. Ce n'est pas une accusation — un
 * acheteur honnête qui vient d'acquérir un bien produit le même signal — mais
 * c'est ce que l'acheteur suivant doit savoir avant de payer.
 */
enum AgeBracket: string
{
    case ThisWeek = 'this_week';
    case ThisMonth = 'this_month';
    case Months = 'months';
    case Year = 'year';
    case Years = 'years';

    public static function fromDate(?Carbon $depuis): ?self
    {
        if ($depuis === null) {
            return null;
        }

        $jours = $depuis->diffInDays(now(), true);

        return match (true) {
            $jours < 7 => self::ThisWeek,
            $jours < 30 => self::ThisMonth,
            $jours < 365 => self::Months,
            $jours < 730 => self::Year,
            default => self::Years,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ThisWeek => 'Moins d\'une semaine',
            self::ThisMonth => 'Moins d\'un mois',
            self::Months => 'Plusieurs mois',
            self::Year => 'Plus d\'un an',
            self::Years => 'Plus de deux ans',
        };
    }

    /**
     * Vrai si la tranche mérite l'attention de l'acheteur.
     *
     * Signale, n'accuse pas : la formulation côté interface doit rester
     * factuelle. « Enregistré cette semaine » est une information ;
     * « suspect » serait un jugement que la plateforme n'a pas à porter.
     */
    public function deservesAttention(): bool
    {
        return $this === self::ThisWeek;
    }
}
