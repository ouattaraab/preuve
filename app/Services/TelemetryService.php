<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Vérification continue des promesses produit (ST-0903).
 *
 * CT-01 et CT-02 ne sont pas des objectifs internes : ce sont les deux
 * promesses que le produit fait à ses utilisateurs — un verdict en moins d'une
 * seconde, un enregistrement en moins de 90 secondes. Non mesurées, elles ne
 * sont que des intentions, et leur dérive passerait inaperçue jusqu'au jour où
 * un loueur pilote renoncerait sans dire pourquoi.
 *
 * LES CENTILES PLUTÔT QUE LA MOYENNE. Une moyenne de 400 ms peut cacher un
 * utilisateur sur vingt qui attend quatre secondes — et c'est précisément
 * celui-là qui abandonne. CT-01 est écrit en 95e centile, la mesure l'est
 * aussi.
 *
 * LA MESURE EST ANONYME. Elle ne lit que des durées et des compteurs, jamais
 * qui a fait quoi : une télémétrie qui saurait relier un temps de parcours à
 * une personne serait un profilage de plus, sur les utilisateurs qu'on
 * protège.
 */
final class TelemetryService
{
    /** Seuil promis pour la consultation (CT-01), en millisecondes. */
    public const CT01_TARGET_MS = 1000;

    /** Seuil promis pour l'enregistrement (CT-02), en millisecondes. */
    public const CT02_TARGET_MS = 90_000;

    /**
     * Tableau complet sur une fenêtre glissante.
     *
     * @return array<string, mixed>
     */
    public function report(int $jours = 7): array
    {
        $depuis = now()->subDays(max(1, $jours));

        return [
            'window' => [
                'from' => $depuis->toIso8601String(),
                'to' => now()->toIso8601String(),
                'days' => max(1, $jours),
            ],
            'ct01_lookup' => $this->lookupLatency($depuis),
            'ct02_registration' => $this->registrationDuration($depuis),
            'report_conversion' => $this->reportConversion($depuis),
        ];
    }

    /**
     * Latence de consultation : 95e centile, médiane, et respect du seuil.
     *
     * @return array{samples: int, p50_ms: int|null, p95_ms: int|null, target_ms: int, meets_target: bool|null}
     */
    public function lookupLatency(Carbon $depuis): array
    {
        $brutes = DB::table('lookups')
            ->whereNotNull('duration_ms')
            ->where('created_at', '>=', $depuis->format('Y-m-d H:i:s'))
            ->orderBy('duration_ms')
            ->pluck('duration_ms');

        $durees = [];

        foreach ($brutes as $valeur) {
            $durees[] = is_numeric($valeur) ? (int) $valeur : 0;
        }

        $p95 = $this->percentile($durees, 95);

        return [
            'samples' => count($durees),
            'p50_ms' => $this->percentile($durees, 50),
            'p95_ms' => $p95,
            'target_ms' => self::CT01_TARGET_MS,
            // `null` quand aucun échantillon : dire « objectif tenu » sans
            // mesure serait pire que de ne rien dire.
            'meets_target' => $p95 === null ? null : $p95 <= self::CT01_TARGET_MS,
        ];
    }

    /**
     * Durée d'enregistrement telle que le client l'a chronométrée (CT-02).
     *
     * Lue dans la chaîne d'audit, seul endroit où le chronomètre est consigné.
     * La médiane fait foi ici, comme le critère l'énonce : un enregistrement
     * long tient souvent à une photo reprise trois fois, pas au produit.
     *
     * @return array{samples: int, median_ms: int|null, p95_ms: int|null, target_ms: int, meets_target: bool|null}
     */
    public function registrationDuration(Carbon $depuis): array
    {
        $lignes = DB::table('audit_log')
            ->where('action', 'asset.registered')
            ->where('created_at', '>=', $depuis->format('Y-m-d H:i:s'))
            ->pluck('payload');

        $durees = [];

        foreach ($lignes as $payload) {
            if (! is_string($payload)) {
                continue;
            }

            $decode = json_decode($payload, true);

            if (is_array($decode) && isset($decode['client_elapsed_ms']) && is_numeric($decode['client_elapsed_ms'])) {
                $durees[] = (int) $decode['client_elapsed_ms'];
            }
        }

        sort($durees);
        $mediane = $this->percentile($durees, 50);

        return [
            'samples' => count($durees),
            'median_ms' => $mediane,
            'p95_ms' => $this->percentile($durees, 95),
            'target_ms' => self::CT02_TARGET_MS,
            'meets_target' => $mediane === null ? null : $mediane <= self::CT02_TARGET_MS,
        ];
    }

    /**
     * Conversion des consultations en rapports achetés.
     *
     * @return array{lookups: int, reports_purchased: int, rate: float}
     */
    public function reportConversion(Carbon $depuis): array
    {
        $consultations = DB::table('lookups')
            ->where('created_at', '>=', $depuis->format('Y-m-d H:i:s'))
            ->count();

        $rapports = DB::table('report_purchases')
            ->where('created_at', '>=', $depuis->format('Y-m-d H:i:s'))
            ->count();

        return [
            'lookups' => $consultations,
            'reports_purchased' => $rapports,
            'rate' => $consultations === 0 ? 0.0 : round($rapports / $consultations, 4),
        ];
    }

    /**
     * Centile par la méthode du plus proche rang : sans interpolation, la
     * valeur rendue est une mesure réellement observée, ce qui rend le chiffre
     * défendable devant qui le conteste.
     *
     * @param  list<int>  $valeurs  triées par ordre croissant
     */
    private function percentile(array $valeurs, int $centile): ?int
    {
        $total = count($valeurs);

        if ($total === 0) {
            return null;
        }

        $rang = (int) ceil($centile / 100 * $total);

        return $valeurs[max(0, $rang - 1)];
    }
}
