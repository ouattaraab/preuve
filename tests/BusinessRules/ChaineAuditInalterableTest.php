<?php

declare(strict_types=1);

use App\Enums\ActorType;
use App\Models\AuditLog;
use App\Services\AuditChain;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * N'utilise pas RefreshDatabase : append() vérifie désormais
 * DB::transactionLevel() pour refuser de s'exécuter depuis une transaction
 * englobante (round 3 de correction) — or RefreshDatabase enveloppe chaque
 * test dans sa propre transaction, ce qui déclencherait ce garde-fou à tort
 * pour les appels directs à append() ci-dessous.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('audit_log')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    DB::statement('TRUNCATE TABLE audit_log');
});
afterEach(fn () => DB::statement('TRUNCATE TABLE audit_log'));

it('interdit toute mise à jour d\'une entrée d\'audit', function (): void {
    $entry = app(AuditChain::class)
        ->append(ActorType::System, null, 'test', 'asset', 1, ['a' => 1]);

    expect(fn () => $entry->update(['action' => 'falsifie']))
        ->toThrow(RuntimeException::class, 'append-only');
});

it('interdit toute suppression d\'une entrée d\'audit', function (): void {
    $entry = app(AuditChain::class)
        ->append(ActorType::System, null, 'test', 'asset', 1, ['a' => 1]);

    expect(fn () => $entry->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('bloque au niveau base une mise à jour qui contourne les événements Eloquent', function (): void {
    // La revue a démontré que AuditLog::query()->update(...) ne déclenche
    // aucun événement ("updating") d'instance : les garde-fous du modèle ne
    // protègent que le chemin ::update()/::delete() sur une instance chargée.
    // La protection descend donc au niveau de la base (déclencheurs MariaDB,
    // §4.6 de la spec), indépendante de tout chemin de code applicatif.
    $entry = app(AuditChain::class)
        ->append(ActorType::System, null, 'test', 'asset', 1, ['a' => 1]);

    expect(fn () => AuditLog::query()->where('id', $entry->id)->update(['action' => 'falsifie']))
        ->toThrow(QueryException::class, 'append-only');
});

it('bloque au niveau base une suppression qui contourne les événements Eloquent', function (): void {
    $entry = app(AuditChain::class)
        ->append(ActorType::System, null, 'test', 'asset', 1, ['a' => 1]);

    expect(fn () => AuditLog::query()->where('id', $entry->id)->delete())
        ->toThrow(QueryException::class, 'append-only');
});

it('n\'expose que append, transaction et verify comme méthodes publiques d\'écriture', function (): void {
    // `transaction()` est la troisième méthode publique délibérément ajoutée
    // (round 3 de correction) : une revue a démontré qu'append(), appelée
    // depuis une transaction métier englobante, relâche son verrou avant le
    // COMMIT de l'appelant — ce qui vide la sérialisation de son effet et
    // perd des entrées d'audit sous écriture concurrente réelle (voir
    // task-5-report.md). transaction() englobe travail métier et écriture
    // d'audit dans une seule transaction sérialisée par le même verrou, pour
    // les cas où l'action métier elle-même doit rester transactionnelle
    // avec son entrée d'audit (règle métier 3 de CLAUDE.md). Aucune autre
    // méthode publique d'écriture ne doit jamais être ajoutée.
    $methods = collect((new ReflectionClass(AuditChain::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $m): string => $m->getName())
        ->reject(fn (string $name): bool => str_starts_with($name, '__'));

    expect($methods->all())->toEqualCanonicalizing(['append', 'transaction', 'verify']);
});
