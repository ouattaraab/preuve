<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * État d'abonnement d'une flotte (ST-0805).
 *
 * `read_only` est le terme du parcours de relance, jamais une coupure : les
 * véhicules déjà enregistrés restent protégés, consultables et alertés. Seul
 * l'ajout de nouveaux véhicules est fermé.
 */
enum SubscriptionStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case ReadOnly = 'read_only';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Période gratuite',
            self::Active => 'Abonnement à jour',
            self::PastDue => 'Paiement en retard',
            self::ReadOnly => 'Lecture seule',
        };
    }

    /** Vrai si la flotte peut encore accueillir de nouveaux véhicules. */
    public function allowsNewAssets(): bool
    {
        return $this !== self::ReadOnly;
    }

    /**
     * La protection des véhicules DÉJÀ enregistrés ne dépend jamais de l'état
     * de l'abonnement : un acheteur consultant un véhicule volé ne doit pas
     * recevoir « bien inconnu » parce que son propriétaire a du retard de
     * paiement.
     */
    public function keepsExistingProtection(): bool
    {
        return true;
    }

    public function message(): string
    {
        return match ($this) {
            self::Trial => 'Vos premiers véhicules sont gratuits.',
            self::Active => 'Votre abonnement est à jour.',
            self::PastDue => 'Votre abonnement est en retard de paiement. Vos véhicules restent protégés.',
            self::ReadOnly => 'Ajout de véhicules suspendu faute de règlement. Vos véhicules déjà enregistrés '.
                'restent protégés, consultables et surveillés.',
        };
    }
}
