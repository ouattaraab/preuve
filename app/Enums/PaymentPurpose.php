<?php

declare(strict_types=1);

namespace App\Enums;

/** Objet d'un paiement. */
enum PaymentPurpose: string
{
    case DetailedReport = 'detailed_report';
    case ClaimFee = 'claim_fee';
    case AssetSlot = 'asset_slot';
    case FleetSubscription = 'fleet_subscription';

    /**
     * Déclarer un bien volé.
     *
     * À ZÉRO PAR DÉFAUT, ET CE DÉFAUT COMPTE. Une déclaration de vol est ce qui
     * rend un bien invendable : celui qui la fait vient de se faire dépouiller,
     * et chaque obstacle est une déclaration qui n'aura pas lieu. La valeur du
     * registre vient de sa COUVERTURE — moins de vols déclarés, c'est un
     * registre moins fiable pour les acheteurs, donc un rapport détaillé qui
     * vaut moins cher.
     *
     * Le péage existe donc, réglable, mais il reste fermé tant qu'un exploitant
     * ne l'ouvre pas sciemment.
     */
    case TheftDeclaration = 'theft_declaration';

    /**
     * Mettre un bien volé EN AVANT sur la liste publique.
     *
     * LA DISTINCTION EST LE CŒUR DU DISPOSITIF. Déclarer rend le bien
     * invendable pour quiconque VÉRIFIE son numéro — c'est la protection, et
     * elle ne se monnaie pas. Être PUBLIÉ sur la liste que tout le monde
     * parcourt est un service en plus : de la visibilité, pas un droit.
     */
    case TheftListing = 'theft_listing';

    public function label(): string
    {
        return match ($this) {
            self::DetailedReport => 'Rapport détaillé',
            self::ClaimFee => 'Frais de dossier de réclamation',
            self::AssetSlot => 'Enregistrement de bien supplémentaire',
            self::FleetSubscription => 'Abonnement flotte',
            self::TheftDeclaration => 'Déclaration de vol',
            self::TheftListing => 'Mise en avant d\'un bien volé',
        };
    }
}
