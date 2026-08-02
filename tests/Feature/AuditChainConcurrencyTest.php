<?php

declare(strict_types=1);

use App\Enums\ActorType;
use App\Services\AuditChain;
use Illuminate\Process\Pool;
use Illuminate\Process\ProcessPoolResults;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/**
 * Un bouclage séquentiel de append() dans un seul processus PHP (voir
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
 * Ce fichier n'utilisant pas RefreshDatabase, rien ne déclenche jamais les
 * migrations pour lui : sur une base vierge (CI, `php artisan db:wipe`), et
 * comme Pest trie ce fichier avant AuditChainTest.php (qui migre via
 * RefreshDatabase), TRUNCATE échouait avec « Table ... doesn't exist ». On ne
 * migre donc que si la table n'existe pas encore (`Schema::hasTable`),
 * plutôt que d'appeler `migrate` sans condition à chaque test : au-delà de la
 * performance, un `migrate` inconditionnel répété s'est montré, une fois sur
 * plusieurs dizaines d'exécutions pendant le développement de ce correctif,
 * en porte-à-faux avec l'état réel de la table `migrations` (« table already
 * exists » sur une migration pourtant déjà enregistrée) — ne le rappeler que
 * lorsque la table cible est effectivement absente réduit la surface de ce
 * risque.
 *
 * Mécanisme de sérialisation : AuditChain::append() sérialise désormais les
 * écritures concurrentes avec un verrou nommé MariaDB (GET_LOCK), après
 * qu'une mesure a montré que le motif précédent (verrou de ligne sur la
 * dernière entrée + reprise sur interblocage) produisait un interblocage
 * garanti entre écritures concurrentes plutôt qu'une contention absorbable
 * par des essais — un nombre d'essais devenait alors un plafond dur
 * d'écritures servies, pas une marge de sécurité (voir la docstring de
 * AuditChain::append() et task-5-report.md pour le détail des mesures).
 *
 * Nombre de workers retenu ici (20) : au-delà de « au moins deux processus se
 * disputent réellement le verrou » (le strict minimum pour mettre en évidence
 * une fourche), une charge plus élevée garde une marge d'observation utile
 * sur le mécanisme de sérialisation lui-même. Mesuré pendant le
 * développement de ce correctif : 12, 24 et 32 processus réellement
 * concurrents, 3 essais chacun → succès total à chaque fois (aucune entrée
 * perdue, aucune fourche).
 */
beforeEach(function (): void {
    if (! Schema::hasTable('audit_log')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    DB::statement('TRUNCATE TABLE audit_log');
});
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

    // Ce qui compte vraiment : aucune entrée d'audit perdue. Un worker qui
    // réussirait à s'exécuter (exit code 0) sans que sa ligne soit écrite ne
    // serait pas détecté par la seule assertion ->successful() ci-dessus.
    $actualCount = DB::table('audit_log')->count();
    expect($actualCount)->toBe(
        $expectedCount,
        "nombre de lignes écrites ({$actualCount}) différent du nombre de processus lancés ({$expectedCount}) : au moins une entrée d'audit a été perdue."
    );

    $verification = app(AuditChain::class)->verify();

    expect($verification['valid'])->toBeTrue()
        ->and($verification['broken_at'])->toBeNull();

    $prevHashes = DB::table('audit_log')->orderBy('id')->pluck('prev_hash');

    expect($prevHashes->count())->toBe($prevHashes->unique()->count());
};

it('ne perd aucune entrée et ne produit jamais de fourche sous écriture concurrente réelle, table déjà peuplée', function () use ($lancerAppendsConcurrents, $assertConcurrenceReussie): void {
    app(AuditChain::class)->append(ActorType::System, null, 'test.seed', 'asset', 0, ['seed' => true]);

    $workers = 20;
    $results = $lancerAppendsConcurrents($workers);

    $assertConcurrenceReussie($results, $workers + 1);
})->group('concurrency');

it('ne perd aucune entrée et ne produit jamais de fourche sous écriture concurrente réelle, table vide au départ', function () use ($lancerAppendsConcurrents, $assertConcurrenceReussie): void {
    // Cas signalé explicitement par la revue : sur une table vide, InnoDB ne
    // pose que des verrous d'intervalle (gap locks) sur la première ligne à
    // venir, un terrain plus propice à l'interblocage qu'à la sérialisation
    // propre qu'on observe une fois la table peuplée — c'est exactement ce
    // scénario que le verrou nommé sérialise désormais explicitement, sans
    // dépendre du comportement de verrouillage d'InnoDB sur une cible mobile.
    $workers = 20;
    $results = $lancerAppendsConcurrents($workers);

    $assertConcurrenceReussie($results, $workers);
})->group('concurrency');
