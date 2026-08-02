<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Payment;
use App\Models\User;

/**
 * Quotas d'enregistrement et dû d'abonnement (ST-0804, ST-0805).
 *
 * LE QUOTA N'EMPÊCHE JAMAIS D'ENREGISTRER, et c'est la décision structurante
 * de ce service. Le critère d'acceptation de ST-0804 dit « upsell non
 * bloquant », et la raison est plus forte qu'un choix commercial : un bien non
 * enregistré est un bien non protégé. Refuser le quatrième enregistrement
 * d'une famille, c'est laisser une moto dehors — et c'est précisément le vol
 * que la plateforme existe pour rendre inutile. La mission prime sur la
 * facturation à l'acte.
 *
 * Le compteur sert donc à PROPOSER, pas à barrer : il dit combien de places
 * gratuites restent, ce que coûte la suivante, et laisse l'utilisateur décider.
 * Un enregistrement au-delà du quota aboutit, et l'invitation à régulariser
 * l'accompagne.
 *
 * Conséquence assumée : le revenu de ce poste repose sur la bonne volonté.
 * Le rendre bloquant est une décision produit, pas une correction technique —
 * elle se prendrait ici, en une ligne, mais elle appartient au métier.
 */
final class QuotaService
{
    /** Durée de validité d'une place achetée (500 FCFA/bien/an, §6). */
    private const SLOT_VALIDITY_MONTHS = 12;

    /**
     * État du quota d'un particulier.
     *
     * @return array{free_quota: int, used: int, paid_slots: int, remaining: int, over_quota: bool, next_slot_price_fcfa: int, message: string|null}
     */
    public function forUser(User $utilisateur): array
    {
        $gratuits = $this->freeQuotaOf($utilisateur);
        $utilises = $this->activeAssetsOf($utilisateur);
        $achetes = $this->paidSlotsOf($utilisateur);
        $restants = max(0, $gratuits + $achetes - $utilises);
        $depasse = $utilises > $gratuits + $achetes;

        return [
            'free_quota' => $gratuits,
            'used' => $utilises,
            'paid_slots' => $achetes,
            'remaining' => $restants,
            'over_quota' => $depasse,
            'next_slot_price_fcfa' => $this->slotPrice(),
            'message' => $this->message($restants, $depasse),
        ];
    }

    /**
     * Dû mensuel d'une flotte (ST-0805).
     *
     * Les premiers véhicules restent gratuits, comme pour un particulier : un
     * loueur doit pouvoir éprouver le service sur une partie de son parc avant
     * d'engager un abonnement.
     *
     * @return array{free_quota: int, fleet_size: int, billable: int, monthly_fcfa: int, unit_price_fcfa: int}
     */
    public function forCompany(Company $societe): array
    {
        $gratuits = max(0, $societe->free_fleet_quota);

        $flotte = Asset::query()
            ->where('company_id', $societe->id)
            ->whereNotNull('active_flag')
            ->count();

        $facturables = max(0, $flotte - $gratuits);
        $prixUnitaire = $this->fleetUnitPrice($flotte);

        return [
            'free_quota' => $gratuits,
            'fleet_size' => $flotte,
            'billable' => $facturables,
            'unit_price_fcfa' => $prixUnitaire,
            'monthly_fcfa' => $facturables * $prixUnitaire,
        ];
    }

    /**
     * Prix unitaire mensuel selon la taille du parc : les paliers font baisser
     * le coût par véhicule, faute de quoi un loueur de cent véhicules paierait
     * cent fois le prix d'un particulier pour le même service.
     */
    public function fleetUnitPrice(int $flotte): int
    {
        $paliers = config('preuve.fleet_tiers');

        if (! is_array($paliers)) {
            return 500;
        }

        $prix = 500;

        foreach ($paliers as $seuil => $tarif) {
            if (is_numeric($seuil) && is_numeric($tarif) && $flotte >= (int) $seuil) {
                $prix = (int) $tarif;
            }
        }

        return $prix;
    }

    /** Places achetées et encore valides. */
    private function paidSlotsOf(User $utilisateur): int
    {
        return Payment::query()
            ->where('user_id', $utilisateur->id)
            ->where('purpose', PaymentPurpose::AssetSlot->value)
            ->where('status', PaymentStatus::Succeeded->value)
            ->where('paid_at', '>', now()->subMonths(self::SLOT_VALIDITY_MONTHS))
            ->count();
    }

    /**
     * Seuls les enregistrements ACTIFS comptent : un bien cédé ou archivé ne
     * pèse plus sur le quota de son ancien détenteur, qui n'en tire plus aucun
     * service.
     */
    private function activeAssetsOf(User $utilisateur): int
    {
        return Asset::query()
            ->where('owner_user_id', $utilisateur->id)
            ->whereNotNull('active_flag')
            // Les biens de flotte relèvent de l'abonnement de la société, pas
            // du quota personnel du représentant légal.
            ->whereNull('company_id')
            ->count();
    }

    private function freeQuotaOf(User $utilisateur): int
    {
        $quota = $utilisateur->getAttribute('free_assets_quota');

        return is_numeric($quota) ? max(0, (int) $quota) : 3;
    }

    private function slotPrice(): int
    {
        $prix = config('preuve.asset_slot_price_fcfa');

        return is_numeric($prix) && (int) $prix > 0 ? (int) $prix : 500;
    }

    /**
     * Message d'invitation, jamais d'avertissement : l'utilisateur vient de
     * protéger un bien de plus, ce n'est pas le moment de lui reprocher quoi
     * que ce soit.
     */
    private function message(int $restants, bool $depasse): ?string
    {
        if ($depasse) {
            return 'Vos enregistrements gratuits sont utilisés. Vos biens restent protégés — '.
                'contribuez quand vous le souhaitez pour soutenir le service.';
        }

        if ($restants === 0) {
            return 'C\'était votre dernier enregistrement gratuit. Les suivants restent possibles.';
        }

        if ($restants === 1) {
            return 'Il vous reste 1 enregistrement gratuit.';
        }

        return null;
    }
}
