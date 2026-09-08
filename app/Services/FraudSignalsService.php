<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LifeStatus;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord anti-fraude (ST-0902).
 *
 * Agrège les signaux que la plateforme produit déjà sans les exploiter :
 * tentatives d'enregistrement d'un identifiant existant, pics de consultation,
 * comptes qui reviennent trop souvent dans ces deux listes.
 *
 * L'ORDRE DE PRIORITÉ EST LE PRODUIT. Un agent ne peut pas tout contrôler ; ce
 * tableau lui dit par où commencer. Un compte qui a tenté trois enregistrements
 * déjà pris mérite un regard avant celui qui en a tenté un — non parce qu'il
 * est coupable, mais parce que la répétition est ce qui distingue une erreur
 * d'un procédé.
 *
 * AUCUN SIGNAL N'EST UNE ACCUSATION. Un acheteur de bonne foi qui enregistre le
 * bien qu'il vient d'acquérir déclenche exactement le même signal qu'un
 * fraudeur. C'est pourquoi rien n'est automatisé ici : le tableau prépare une
 * décision humaine, il n'en prend aucune.
 */
final class FraudSignalsService
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(int $jours = 30): array
    {
        $depuis = now()->subDays(max(1, $jours))->format('Y-m-d H:i:s');

        return [
            'window_days' => max(1, $jours),
            'duplicate_attempts' => $this->duplicateAttempts($depuis),
            'lookup_spikes' => $this->lookupSpikes($depuis),
            'suspicious_accounts' => $this->suspiciousAccounts($depuis),
            'disputed_assets' => $this->disputedAssets(),
            'stolen_unconsolidated' => $this->stolenWithoutReport(),
        ];
    }

    /**
     * Biens les plus visés par des tentatives d'enregistrement.
     *
     * @return array{total: int, top_assets: list<array{asset_id: int, attempts: int}>}
     */
    private function duplicateAttempts(string $depuis): array
    {
        $lignes = DB::table('audit_log')
            ->select('entity_id', DB::raw('COUNT(*) as total'))
            ->where('action', 'asset.duplicate_attempt')
            ->where('created_at', '>=', $depuis)
            ->groupBy('entity_id')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        $total = DB::table('audit_log')
            ->where('action', 'asset.duplicate_attempt')
            ->where('created_at', '>=', $depuis)
            ->count();

        $biens = [];

        foreach ($lignes as $ligne) {
            $biens[] = [
                'asset_id' => $this->entier($ligne->entity_id ?? null),
                'attempts' => $this->entier($ligne->total ?? null),
            ];
        }

        return ['total' => $total, 'top_assets' => $biens];
    }

    /**
     * Biens anormalement consultés sur la période.
     *
     * @return array{threshold: int, assets: list<array{asset_id: int, lookups: int}>}
     */
    private function lookupSpikes(string $depuis): array
    {
        $seuil = config('preuve.lookup_spike.threshold');
        $seuil = is_numeric($seuil) && (int) $seuil > 0 ? (int) $seuil : 5;

        $lignes = DB::table('lookups')
            ->select('found_asset_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('found_asset_id')
            ->where('created_at', '>=', $depuis)
            ->groupBy('found_asset_id')
            ->havingRaw('COUNT(*) >= ?', [$seuil])
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        $biens = [];

        foreach ($lignes as $ligne) {
            $biens[] = [
                'asset_id' => $this->entier($ligne->found_asset_id ?? null),
                'lookups' => $this->entier($ligne->total ?? null),
            ];
        }

        return ['threshold' => $seuil, 'assets' => $biens];
    }

    /**
     * Comptes qui reviennent dans les tentatives de doublon.
     *
     * La répétition est le seul critère retenu : elle distingue une erreur d'un
     * procédé. Un compte n'apparaît ici qu'à partir de deux tentatives, pour
     * ne pas transformer chaque acheteur de bonne foi en suspect.
     *
     * @return list<array{user_id: int, duplicate_attempts: int}>
     */
    private function suspiciousAccounts(string $depuis): array
    {
        $lignes = DB::table('audit_log')
            ->select('actor_id', DB::raw('COUNT(*) as total'))
            ->where('action', 'asset.duplicate_attempt')
            ->whereNotNull('actor_id')
            ->where('created_at', '>=', $depuis)
            ->groupBy('actor_id')
            ->havingRaw('COUNT(*) >= 2')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        $comptes = [];

        foreach ($lignes as $ligne) {
            $comptes[] = [
                'user_id' => $this->entier($ligne->actor_id ?? null),
                'duplicate_attempts' => $this->entier($ligne->total ?? null),
            ];
        }

        return $comptes;
    }

    private function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    /** Biens actuellement gelés : autant de dossiers qui attendent une décision. */
    private function disputedAssets(): int
    {
        return DB::table('assets')
            ->where('life_status', LifeStatus::Disputed->value)
            ->whereNotNull('active_flag')
            ->count();
    }

    /**
     * Vols déclarés sans récépissé au-delà du délai de consolidation.
     *
     * Ni une accusation ni un motif de levée : une plainte prend du temps, et
     * certaines victimes n'iront jamais au commissariat. Le chiffre sert à
     * mesurer la part de déclarations non consolidées, pas à les invalider.
     *
     * @return array{count: int, consolidation_days: int}
     */
    private function stolenWithoutReport(): array
    {
        $delai = config('preuve.theft_consolidation_days');
        $delai = is_numeric($delai) && (int) $delai > 0 ? (int) $delai : 15;

        return [
            'count' => DB::table('assets')
                ->where('life_status', LifeStatus::Stolen->value)
                ->where('stolen_consolidated', false)
                ->whereNotNull('stolen_declared_at')
                ->where('stolen_declared_at', '<', now()->subDays($delai)->format('Y-m-d H:i:s'))
                ->count(),
            'consolidation_days' => $delai,
        ];
    }
}
