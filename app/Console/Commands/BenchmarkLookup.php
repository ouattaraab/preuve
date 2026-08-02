<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LookupService;
use App\Services\TelemetryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mesure de CT-01 sur volume (ST-0903).
 *
 * CT-01 promet un verdict en moins d'une seconde au 95e centile. Cette promesse
 * n'avait jamais été éprouvée que sur des tables vides, où toute requête est
 * rapide — ce qui ne prouve rien. Le risque réel apparaît au volume : un index
 * mal choisi passe inaperçu sur mille lignes et s'effondre sur deux cent mille.
 *
 * La commande sert autant à mesurer qu'à REMESURER : elle est faite pour être
 * relancée après chaque changement de schéma ou d'index, et c'est ce qui la
 * distingue d'un test ponctuel jeté après usage.
 *
 * Elle ÉCRIT des données de mesure et refuse donc la production. Le volume
 * généré l'est par insertion directe, sans passer par l'enregistrement
 * ordinaire : deux cent mille passages par le verrou de la chaîne d'audit
 * prendraient des heures et ne mesureraient pas ce qu'on cherche.
 */
final class BenchmarkLookup extends Command
{
    protected $signature = 'preuve:benchmark-lookup
                            {--seed=0 : Biens à générer avant la mesure (0 pour mesurer l\'existant)}
                            {--seed-lookups=0 : Consultations à pré-journaliser avant la mesure}
                            {--runs=500 : Consultations à chronométrer}';

    protected $description = 'Mesure la latence de consultation sur volume (CT-01)';

    /**
     * Lot volontairement modeste : un INSERT de deux mille lignes fabrique
     * plusieurs dizaines de milliers de paramètres liés, et PDO épuise la
     * mémoire du processus avant d'atteindre la base.
     */
    private const BATCH = 500;

    public function handle(LookupService $lookups): int
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'Cette mesure écrit des biens et des consultations fictifs : interdite en production.'
            );
        }

        // Le journal de requêtes de Laravel garde chaque requête en mémoire :
        // sur cent mille insertions, il épuise la limite du processus avant la
        // fin de la génération.
        DB::disableQueryLog();

        $aGenerer = max(0, (int) $this->option('seed'));
        $mesures = max(1, (int) $this->option('runs'));

        if ($aGenerer > 0) {
            $this->generate($aGenerer);
        }

        // Le journal des consultations est la table qui grossit le plus vite —
        // une ligne par consultation, conservée douze mois — et c'est elle que
        // le plafond anti-profilage interroge à CHAQUE appel. Mesurer sur un
        // journal vide donnerait un chiffre flatteur et faux.
        $journalASimuler = max(0, (int) $this->option('seed-lookups'));

        if ($journalASimuler > 0) {
            $this->generateLookups($journalASimuler);
        }

        $volume = DB::table('assets')->count();
        $journal = DB::table('lookups')->count();

        $this->components->info("Volume : {$volume} biens, {$journal} consultations déjà journalisées.");

        $identifiants = DB::table('assets')
            ->whereNotNull('active_flag')
            ->inRandomOrder()
            ->limit(min($mesures, 1000))
            ->pluck('identifier_normalized')
            ->all();

        if ($identifiants === []) {
            $this->components->error('Aucun bien actif : générez un volume avec --seed.');

            return self::FAILURE;
        }

        $durees = [];

        foreach (range(1, $mesures) as $tour) {
            $identifiant = $identifiants[array_rand($identifiants)];

            // Adresse variable : le plafond anti-profilage compte par empreinte
            // d'adresse, et mesurer un service qui refuse de répondre après dix
            // appels ne mesurerait rien.
            $adresse = '41.'.random_int(1, 254).'.'.random_int(1, 254).'.'.random_int(1, 254);

            $debut = hrtime(true);
            $lookups->lookup(is_string($identifiant) ? $identifiant : '', $adresse);
            $durees[] = (int) round((hrtime(true) - $debut) / 1_000_000);
        }

        sort($durees);

        $p50 = $this->centile($durees, 50);
        $p95 = $this->centile($durees, 95);
        $p99 = $this->centile($durees, 99);
        $seuil = TelemetryService::CT01_TARGET_MS;

        $this->components->twoColumnDetail('Consultations mesurées', (string) count($durees));
        $this->components->twoColumnDetail('Médiane', $p50.' ms');
        $this->components->twoColumnDetail('95e centile', $p95.' ms');
        $this->components->twoColumnDetail('99e centile', $p99.' ms');
        $this->components->twoColumnDetail('Seuil CT-01', $seuil.' ms');

        if ($p95 <= $seuil) {
            $this->components->info("CT-01 tenu : {$p95} ms au 95e centile, sous les {$seuil} ms promis.");

            return self::SUCCESS;
        }

        $this->components->error("CT-01 NON tenu : {$p95} ms au 95e centile, au-dessus des {$seuil} ms promis.");

        return self::FAILURE;
    }

    /**
     * Génère un volume par insertion directe. Les biens produits n'ont ni
     * historique ni entrée d'audit : ce sont des lignes de mesure, pas des
     * enregistrements — et c'est pourquoi la commande refuse la production.
     */
    private function generate(int $combien): void
    {
        $proprietaire = DB::table('users')->value('id');

        if ($proprietaire === null) {
            $proprietaire = DB::table('users')->insertGetId([
                'phone' => '+2250700000000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->components->info("Génération de {$combien} biens…");
        $barre = $this->output->createProgressBar($combien);

        for ($fait = 0; $fait < $combien; $fait += self::BATCH) {
            $lot = [];
            $taille = min(self::BATCH, $combien - $fait);

            for ($i = 0; $i < $taille; $i++) {
                $identifiant = 'BENCH'.str_pad((string) ($fait + $i), 12, '0', STR_PAD_LEFT);

                $lot[] = [
                    'public_ref' => 'PRV-'.strtoupper(substr(md5($identifiant), 0, 8)),
                    'owner_user_id' => $proprietaire,
                    'asset_category_key' => 'voiture',
                    'identifier_type' => 'plate',
                    'identifier_raw' => $identifiant,
                    'identifier_normalized' => $identifiant,
                    'active_flag' => 1,
                    'attributes' => '{}',
                    'trust_level' => 'F1',
                    'life_status' => 'V-ACT',
                    'registered_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('assets')->insertOrIgnore($lot);
            $barre->advance($taille);
        }

        $barre->finish();
        $this->newLine(2);
    }

    /** Remplit le journal des consultations, pour mesurer dans les conditions réelles. */
    private function generateLookups(int $combien): void
    {
        $this->components->info("Pré-journalisation de {$combien} consultations…");
        $barre = $this->output->createProgressBar($combien);

        for ($fait = 0; $fait < $combien; $fait += self::BATCH) {
            $lot = [];
            $taille = min(self::BATCH, $combien - $fait);

            for ($i = 0; $i < $taille; $i++) {
                $lot[] = [
                    'identifier_normalized' => 'BENCH'.str_pad((string) random_int(0, 199999), 12, '0', STR_PAD_LEFT),
                    'found_asset_id' => null,
                    'ip_hash' => hash('sha256', (string) random_int(1, 500000)),
                    'user_id' => null,
                    'source' => 'app',
                    'duration_ms' => random_int(1, 40),
                    // Réparties sur l'année de rétention, comme en exploitation.
                    'created_at' => now()->subMinutes(random_int(1, 525600))->format('Y-m-d H:i:s'),
                ];
            }

            DB::table('lookups')->insert($lot);
            $barre->advance($taille);
        }

        $barre->finish();
        $this->newLine(2);
    }

    /** @param  list<int>  $valeurs */
    private function centile(array $valeurs, int $centile): int
    {
        if ($valeurs === []) {
            return 0;
        }

        $rang = (int) ceil($centile / 100 * count($valeurs));

        return $valeurs[max(0, $rang - 1)];
    }
}
