<?php

declare(strict_types=1);

use App\Enums\ActorType;
use App\Models\AuditLog;
use App\Services\AuditChain;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// MariaDB/InnoDB ne restaure pas le compteur AUTO_INCREMENT lors du rollback
// de transaction utilisé par RefreshDatabase entre chaque test. Sans cette
// remise à zéro, les identifiants des lignes ne partiraient plus de 1 à
// chaque test et le test « détecte une rupture de chaîne » ci-dessous,
// qui cible l'entrée n° 2, viserait la mauvaise ligne.
beforeEach(function (): void {
    DB::statement('ALTER TABLE audit_log AUTO_INCREMENT = 1');
});

it('chaîne la première entrée sur un hash de genèse', function (): void {
    $entry = app(AuditChain::class)->append(
        ActorType::System, null, 'asset.created', 'asset', 1, ['ref' => 'PRV-TEST0001']
    );

    expect($entry->prev_hash)->toBe(str_repeat('0', 64))
        ->and($entry->chain_hash)->toBe(hash('sha256', $entry->prev_hash.$entry->payload_hash));
});

it('chaîne chaque entrée sur le hash de la précédente', function (): void {
    $chain = app(AuditChain::class);

    $first = $chain->append(ActorType::System, null, 'asset.created', 'asset', 1, ['a' => 1]);
    $second = $chain->append(ActorType::System, null, 'asset.updated', 'asset', 1, ['a' => 2]);

    expect($second->prev_hash)->toBe($first->chain_hash);
});

it('produit le même hash quel que soit l\'ordre des clés du payload', function (): void {
    $chain = app(AuditChain::class);

    $a = $chain->append(ActorType::System, null, 'test.a', 'asset', 1, ['x' => 1, 'y' => 2]);
    $b = $chain->append(ActorType::System, null, 'test.a', 'asset', 1, ['y' => 2, 'x' => 1]);

    expect($a->payload_hash)->toBe($b->payload_hash);
});

it('canonicalise récursivement les clés imbriquées', function (): void {
    $chain = app(AuditChain::class);

    $a = $chain->append(ActorType::System, null, 'test.b', 'asset', 1, ['n' => ['b' => 1, 'a' => 2]]);
    $b = $chain->append(ActorType::System, null, 'test.b', 'asset', 1, ['n' => ['a' => 2, 'b' => 1]]);

    expect($a->payload_hash)->toBe($b->payload_hash);
});

it('valide une chaîne intacte', function (): void {
    $chain = app(AuditChain::class);
    foreach (range(1, 5) as $i) {
        $chain->append(ActorType::System, null, 'test.seq', 'asset', $i, ['i' => $i]);
    }

    expect($chain->verify())->toMatchArray(['valid' => true, 'broken_at' => null]);
});

it('détecte une rupture de chaîne provoquée en base', function (): void {
    $chain = app(AuditChain::class);
    foreach (range(1, 3) as $i) {
        $chain->append(ActorType::System, null, 'test.seq', 'asset', $i, ['i' => $i]);
    }

    // Altération directe en base, en contournant le modèle
    DB::table('audit_log')->where('id', 2)->update(['payload' => json_encode(['i' => 999])]);

    $result = $chain->verify();

    expect($result['valid'])->toBeFalse()
        ->and($result['broken_at'])->toBe(2);
});

it('conserve l\'ordre des entrées concurrentes sans trou dans la chaîne', function (): void {
    $chain = app(AuditChain::class);
    foreach (range(1, 20) as $i) {
        $chain->append(ActorType::System, null, 'test.concurrent', 'asset', $i, ['i' => $i]);
    }

    $entries = AuditLog::orderBy('id')->get();

    expect($entries)->toHaveCount(20);
    $entries->skip(1)->each(function (AuditLog $entry) use ($entries): void {
        $previous = $entries->firstWhere('id', $entry->id - 1);
        expect($entry->prev_hash)->toBe($previous->chain_hash);
    });
});
