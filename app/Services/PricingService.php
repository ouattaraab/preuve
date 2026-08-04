<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Services\Settings\SettingsRepository;

/**
 * Tous les tarifs de la plateforme, en un seul endroit.
 *
 * UN PRIX QUI VIT DANS LE CODE EST UN PRIX QU'ON NE PEUT PAS CORRIGER. Ces
 * montants dépendent du pouvoir d'achat local, de la concurrence et d'arbitrages
 * qui changeront plus vite qu'on ne livre : les laisser en configuration
 * obligerait à une mise en production pour ajuster un rapport de 500 francs.
 *
 * ZÉRO EST UNE VALEUR LÉGITIME, ET C'EST LE POINT LE PLUS IMPORTANT DE CETTE
 * CLASSE. Un tarif à zéro ne veut pas dire « non réglé » : il veut dire GRATUIT,
 * et il lève entièrement le paiement. Un `?:` naïf rétablirait un prix que
 * l'administrateur vient précisément de supprimer — c'est le même piège que les
 * frais de dossier, et un test le verrouille ici aussi.
 *
 * LA CONFIGURATION RESTE LE FILET, jamais la source : elle sert de valeur de
 * départ tant qu'aucun réglage n'a été posé, pour qu'une base vierge ne fasse
 * pas tout tomber à zéro sans que personne l'ait décidé.
 */
final class PricingService
{
    /**
     * Le catalogue des choses payantes. Ajouter une ligne ici suffit à la faire
     * apparaître dans l'espace administrateur : c'est ce qui empêche un
     * nouveau tarif d'être codé en dur ailleurs, faute d'endroit où le mettre.
     *
     * @var array<string, array{setting: string, config: string, label: string, help: string}>
     */
    private const CATALOGUE = [
        'report' => [
            'setting' => 'pricing.report_fcfa',
            'config' => 'preuve.report_price_fcfa',
            'label' => 'Rapport détaillé d\'un bien',
            'help' => 'Ce qu\'un acheteur paie pour voir le détail d\'un bien qui n\'est pas le sien : '.
                'historique des statuts, nombre de détenteurs et dates. Jamais les identités. '.
                'À zéro, le détail s\'affiche sans paiement.',
        ],
        'asset_slot' => [
            'setting' => 'pricing.asset_slot_fcfa',
            'config' => 'preuve.asset_slot_price_fcfa',
            'label' => 'Place d\'enregistrement supplémentaire',
            'help' => 'Au-delà des places gratuites, ce que coûte l\'enregistrement d\'un bien de plus. '.
                'À zéro, les enregistrements deviennent illimités et gratuits.',
        ],
        'claim_fee' => [
            'setting' => ClaimArbitrationService::FEE_SETTING,
            'config' => 'preuve.claim_fee_fcfa',
            'label' => 'Frais de dossier de réclamation',
            'help' => 'Exigés au DÉPÔT du dossier, jamais à son ouverture, et remboursés si la '.
                'réclamation aboutit. À zéro, le recours redevient entièrement gratuit — '.
                'c\'est ce qui empêche le filtre anti-nuisance de devenir un filtre anti-pauvres.',
        ],
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** Montant en francs CFA. Zéro signifie GRATUIT, jamais « non réglé ». */
    public function amount(string $cle): int
    {
        $entree = self::CATALOGUE[$cle] ?? null;

        if ($entree === null) {
            return 0;
        }

        $regle = $this->settings->get($entree['setting']);

        // `is_numeric` et non `?:` : zéro est une valeur, et la confondre avec
        // l'absence rétablirait un tarif que l'administrateur a supprimé.
        if (is_numeric($regle)) {
            return max(0, (int) $regle);
        }

        $defaut = config($entree['config']);

        return is_numeric($defaut) ? max(0, (int) $defaut) : 0;
    }

    public function isFree(string $cle): bool
    {
        return $this->amount($cle) === 0;
    }

    /**
     * Le catalogue complet, tel que l'espace administrateur l'affiche.
     *
     * @return list<array{key: string, label: string, help: string, amount_fcfa: int, free: bool}>
     */
    public function all(): array
    {
        $tarifs = [];

        foreach (self::CATALOGUE as $cle => $entree) {
            $montant = $this->amount($cle);

            $tarifs[] = [
                'key' => $cle,
                'label' => $entree['label'],
                'help' => $entree['help'],
                'amount_fcfa' => $montant,
                'free' => $montant === 0,
            ];
        }

        return $tarifs;
    }

    /**
     * Fixe un tarif.
     *
     * CHAQUE CHANGEMENT EST JOURNALISÉ AVEC L'ANCIEN ET LE NOUVEAU MONTANT.
     * Savoir qu'un prix a changé ne suffit pas à juger s'il a ouvert ou restreint
     * l'accès au service : c'est l'écart qui le dit.
     */
    public function set(string $cle, int $montant, ?User $administrateur = null): void
    {
        $entree = self::CATALOGUE[$cle] ?? null;

        if ($entree === null) {
            return;
        }

        $this->settings->set($entree['setting'], max(0, $montant), $administrateur?->id);
    }

    /**
     * Une instance qui relit les réglages depuis la base.
     *
     * Nécessaire après une écriture : le dépôt garde les valeurs en mémoire pour
     * la durée de la requête, et rendre l'ancien montant juste après l'avoir
     * changé ferait croire que l'enregistrement a échoué.
     */
    public function fresh(): self
    {
        return new self($this->settings->fresh());
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::CATALOGUE);
    }
}
