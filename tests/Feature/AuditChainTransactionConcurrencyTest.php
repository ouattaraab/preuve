<?php

declare(strict_types=1);

use App\Services\AuditChain;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/**
 * Reproduit le scénario précisément démontré par une revue (round 3 de
 * correction) : plusieurs actions métier concurrentes, chacune ouvrant une
 * transaction, lisant, exécutant un travail métier, attendant, puis
 * committant — le tout accompagné d'une écriture d'audit dans la même
 * transaction. Avant correction, append() prenait le verrou nommé, l'insérait
 * dans une transaction imbriquée, puis le relâchait — le COMMIT de
 * l'appelant avait donc lieu après la libération du verrou, ce qui vidait la
 * sérialisation de son effet et perdait des entrées d'audit sous écriture
 * concurrente réelle (voir task-5-report.md pour la reproduction exacte de
 * ce défaut, avant et après correction).
 *
 * AuditChain::transaction() (App\Console\Commands\AuditChainBusinessTransactionOnce
 * dans ce test) prend le verrou avant d'ouvrir sa transaction unique, qui
 * englobe le travail métier simulé (lecture + attente) et l'écriture
 * d'audit, et ne le relâche qu'après le COMMIT. Ce fichier lance de
 * véritables processus PHP concurrents pour le vérifier : aucune
 * simulation en un seul processus ne peut mettre ce verrouillage à
 * l'épreuve.
 *
 * Comme AuditChainConcurrencyTest.php, ce fichier ne peut pas s'appuyer sur
 * RefreshDatabase (les processus enfants committent réellement, hors de
 * toute transaction de test) : la table est réinitialisée par TRUNCATE, la
 * migration n'est déclenchée que si nécessaire.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('audit_log')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    DB::statement('TRUNCATE TABLE audit_log');
});
afterEach(fn () => DB::statement('TRUNCATE TABLE audit_log'));

it('ne perd aucune entrée quand travail métier et audit partagent une transaction sous écriture concurrente réelle', function (): void {
    $workers = 16;

    $results = Process::pool(function (Pool $pool) use ($workers): void {
        for ($i = 1; $i <= $workers; $i++) {
            $pool->as((string) $i)
                ->path(base_path())
                ->command(['php', 'artisan', 'audit-chain:business-transaction-once', (string) $i]);
        }
    })->wait();

    $results->collect()->each(function ($result, $key): void {
        expect($result->successful())
            ->toBeTrue("le worker {$key} a échoué :\n".$result->errorOutput());
    });

    $actualCount = DB::table('audit_log')->count();
    expect($actualCount)->toBe(
        $workers,
        "nombre de lignes écrites ({$actualCount}) différent du nombre de processus lancés ({$workers}) : au moins une entrée d'audit a été perdue."
    );

    $verification = app(AuditChain::class)->verify();

    expect($verification['valid'])->toBeTrue()
        ->and($verification['broken_at'])->toBeNull();

    $prevHashes = DB::table('audit_log')->orderBy('id')->pluck('prev_hash');
    expect($prevHashes->count())->toBe($prevHashes->unique()->count());
});
