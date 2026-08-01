<?php

declare(strict_types=1);

use App\Enums\ActorType;
use App\Models\AuditLog;
use App\Services\AuditChain;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('chaîne la première entrée sur un hash de genèse', function (): void {
    $entry = app(AuditChain::class)->append(
        ActorType::System, null, 'asset.created', 'asset', 1, ['ref' => 'PRV-TEST0001']
    );

    expect($entry->prev_hash)->toBe(str_repeat('0', 64))
        ->and($entry->chain_hash)->toBe(hash('sha256', $entry->prev_hash.$entry->record_hash));
});

it('chaîne chaque entrée sur le hash de la précédente', function (): void {
    $chain = app(AuditChain::class);

    $first = $chain->append(ActorType::System, null, 'asset.created', 'asset', 1, ['a' => 1]);
    $second = $chain->append(ActorType::System, null, 'asset.updated', 'asset', 1, ['a' => 2]);

    expect($second->prev_hash)->toBe($first->chain_hash);
});

it('produit les mêmes octets de payload quel que soit l\'ordre des clés', function (): void {
    $chain = app(AuditChain::class);

    $a = $chain->append(ActorType::System, null, 'test.a', 'asset', 1, ['x' => 1, 'y' => 2]);
    $b = $chain->append(ActorType::System, null, 'test.a', 'asset', 1, ['y' => 2, 'x' => 1]);

    expect($a->getRawOriginal('payload'))->toBe($b->getRawOriginal('payload'));
});

it('canonicalise récursivement les clés imbriquées', function (): void {
    $chain = app(AuditChain::class);

    $a = $chain->append(ActorType::System, null, 'test.b', 'asset', 1, ['n' => ['b' => 1, 'a' => 2]]);
    $b = $chain->append(ActorType::System, null, 'test.b', 'asset', 1, ['n' => ['a' => 2, 'b' => 1]]);

    expect($a->getRawOriginal('payload'))->toBe($b->getRawOriginal('payload'));
});

it('canonicalise aussi les objets imbriqués, pas seulement les tableaux', function (): void {
    $chain = app(AuditChain::class);

    // La revue a démontré qu'un payload contenant un objet (plutôt qu'un
    // tableau associatif) échappait au tri récursif : sortRecursive() ne
    // descendait que dans is_array(). Deux payloads sémantiquement
    // identiques — un tableau imbriqué et un objet imbriqué équivalent —
    // doivent produire les mêmes octets stockés.
    $a = $chain->append(ActorType::System, null, 'test.objet', 'asset', 1, [
        'n' => (object) ['b' => 1, 'a' => 2],
    ]);
    $b = $chain->append(ActorType::System, null, 'test.objet', 'asset', 1, [
        'n' => ['a' => 2, 'b' => 1],
    ]);

    expect($a->getRawOriginal('payload'))->toBe($b->getRawOriginal('payload'));
});

it('ne rompt jamais la chaîne pour un payload contenant un objet imbriqué', function (): void {
    // Reproduction du faux négatif démontré par la revue : verify()
    // décodait puis ré-encodait le payload, ce qui convertissait un objet en
    // tableau associatif trié et faisait diverger l'empreinte recalculée de
    // celle stockée à l'écriture — une chaîne pourtant intacte était donc
    // déclarée rompue. verify() ne décode plus jamais le payload : il hache
    // les octets tels que stockés, ce qui élimine structurellement ce cas.
    $chain = app(AuditChain::class);
    $chain->append(ActorType::System, null, 'test.objet', 'asset', 1, [
        'details' => (object) ['b' => 1, 'a' => 2],
    ]);

    expect($chain->verify())->toMatchArray(['valid' => true, 'broken_at' => null]);
});

it('valide une chaîne intacte', function (): void {
    $chain = app(AuditChain::class);
    foreach (range(1, 5) as $i) {
        $chain->append(ActorType::System, null, 'test.seq', 'asset', $i, ['i' => $i]);
    }

    expect($chain->verify())->toMatchArray(['valid' => true, 'broken_at' => null]);
});

it('conserve l\'ordre d\'une séquence d\'entrées sans trou dans la chaîne', function (): void {
    // Séquentiel dans un seul processus : ne met pas le verrou à l'épreuve.
    // Voir AuditChainConcurrencyTest.php pour un test avec de véritables
    // processus concurrents.
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
