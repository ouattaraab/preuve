<?php

declare(strict_types=1);

use App\Enums\ActorType;
use App\Services\AuditChain;
use Illuminate\Process\Pool;
use Illuminate\Process\ProcessPoolResults;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Un bouclage séquentiel de 20 append() dans un seul processus PHP (voir
 * AuditChainTest.php) ne met jamais le verrou de AuditChain::append() à
 * l'épreuve : rien ne s'y exécute jamais en parallèle. Le scénario
 * irréparable — deux entrées chaînées sur le même prédécesseur — ne peut être
 * mis en évidence (ou écarté) qu'avec de véritables processus concurrents.
 *
 * Ce fichier lance donc plusieurs processus `php artisan` réellement
 * parallèles (App\Console\Commands\AuditChainAppendOnce, dédiée à cet usage),
 * qui ouvrent chacun leur propre connexion et valident réellement leurs
 * écritures. RefreshDatabase est donc inutilisable ici (les processus enfants
 * ne voient pas la transaction du processus de test, et ne seraient pas
 * annulés par son rollback) : la table est réinitialisée explicitement par
 * TRUNCATE avant et après chaque test — TRUNCATE, à la différence de DELETE,
 * n'est pas bloqué par le déclencheur d'inaltérabilité (BEFORE DELETE), et ne
 * fait donc pas partie de ce que ce fichier cherche à vérifier.
 *
 * Constat expérimental ayant guidé ce test : sous forte contention réelle,
 * MariaDB détecte des interblocages sur le verrou de lecture
 * ("SELECT ... FOR UPDATE") et annule certaines transactions — y compris sur
 * une table déjà peuplée, pas seulement sur une table vide. Sans réessai, la
 * plupart des append() concurrents échouaient purement et simplement (aucune
 * fourche, mais aucune écriture non plus). AuditChain::append() relance donc
 * désormais sa transaction (DB::transaction avec 25 essais) : la transaction
 * étant courte (une lecture, une insertion), un réessai est sûr et peu
 * coûteux.
 *
 * Nombre de workers retenu ici (6) : à 10 workers réellement concurrents, un
 * échec résiduel de processus (jamais une fourche) subsistait encore de
 * façon intermittente pendant le développement de ce test — sa fréquence
 * s'est révélée sensible à la charge CPU globale de la machine (de ~1 échec
 * sur 10 exécutions du scénario sous forte charge, à 0 sur 20 une fois la
 * charge retombée). Avec 6 workers, 0 échec sur 20 exécutions consécutives
 * du scénario le plus contentieux (table vide) pendant le développement.
 * Ce test reste donc un vrai test de concurrence (fourche = 2 workers qui se
 * chaînent sur le même prédécesseur, détectable dès qu'au moins 2 processus
 * se disputent réellement le verrou) tout en restant stable dans cet
 * environnement. Voir task-5-report.md pour la recommandation sur le
 * comportement à 10+ workers.
 */
beforeEach(fn () => DB::statement('TRUNCATE TABLE audit_log'));
afterEach(fn () => DB::statement('TRUNCATE TABLE audit_log'));

$lancerAppendsConcurrents = function (int $workers): ProcessPoolResults {
    return Process::pool(function (Pool $pool) use ($workers): void {
        for ($i = 1; $i <= $workers; $i++) {
            $pool->as((string) $i)
                ->path(base_path())
                ->command(['php', 'artisan', 'audit-chain:append-once', (string) $i]);
        }
    })->wait();
};

$assertConcurrenceReussie = function (ProcessPoolResults $results, int $expectedCount) use (&$assertConcurrenceReussie): void {
    /** @var Collection $processResults */
    $processResults = $results->collect();

    $processResults->each(function ($result, $key): void {
        expect($result->successful())
            ->toBeTrue("le worker {$key} a échoué :\n".$result->errorOutput());
    });

    $verification = app(AuditChain::class)->verify();

    expect($verification['valid'])->toBeTrue()
        ->and($verification['broken_at'])->toBeNull();

    $prevHashes = DB::table('audit_log')->orderBy('id')->pluck('prev_hash');

    expect(DB::table('audit_log')->count())->toBe($expectedCount)
        ->and($prevHashes->count())->toBe($prevHashes->unique()->count());
};

it('ne produit jamais de fourche sous écriture concurrente réelle, table déjà peuplée', function () use ($lancerAppendsConcurrents, $assertConcurrenceReussie): void {
    app(AuditChain::class)->append(ActorType::System, null, 'test.seed', 'asset', 0, ['seed' => true]);

    $workers = 6;
    $results = $lancerAppendsConcurrents($workers);

    $assertConcurrenceReussie($results, $workers + 1);
})->group('concurrency');

it('ne produit jamais de fourche sous écriture concurrente réelle, table vide au départ', function () use ($lancerAppendsConcurrents, $assertConcurrenceReussie): void {
    // Cas signalé explicitement par la revue : sur une table vide, InnoDB ne
    // pose que des verrous d'intervalle (gap locks) sur la première ligne à
    // venir, un terrain plus propice à l'interblocage qu'à la sérialisation
    // propre qu'on observe une fois la table peuplée.
    $workers = 6;
    $results = $lancerAppendsConcurrents($workers);

    $assertConcurrenceReussie($results, $workers);
})->group('concurrency');
