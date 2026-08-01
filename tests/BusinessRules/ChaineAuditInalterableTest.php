<?php

declare(strict_types=1);

use App\Enums\ActorType;
use App\Services\AuditChain;
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

it('n\'expose aucune méthode publique d\'écriture hors append', function (): void {
    $methods = collect((new ReflectionClass(AuditChain::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $m): string => $m->getName())
        ->reject(fn (string $name): bool => str_starts_with($name, '__'));

    expect($methods->all())->toEqualCanonicalizing(['append', 'verify']);
});
