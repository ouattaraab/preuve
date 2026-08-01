<?php

declare(strict_types=1);

use App\Enums\ActorType;
use App\Models\AuditLog;
use App\Services\AuditChain;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

it('n\'expose aucune méthode publique d\'écriture hors append', function (): void {
    $methods = collect((new ReflectionClass(AuditChain::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $m): string => $m->getName())
        ->reject(fn (string $name): bool => str_starts_with($name, '__'));

    expect($methods->all())->toEqualCanonicalizing(['append', 'verify']);
});
