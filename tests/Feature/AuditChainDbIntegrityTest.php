<?php

declare(strict_types=1);

use App\Enums\ActorType;
use App\Services\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * Ce fichier vérifie la défense en profondeur au niveau base (§4.6 de la
 * spec) : les déclencheurs MariaDB `audit_log_interdit_update` et
 * `audit_log_interdit_delete` (voir la migration
 * 2026_08_01_173500_add_immutability_triggers_to_audit_log_table) bloquent
 * tout UPDATE/DELETE sur audit_log, quel que soit le chemin de code. Ces
 * mêmes déclencheurs empêchent le test « détecte une rupture de chaîne » qui
 * existait avant leur introduction (il altérait une ligne par UPDATE) : il
 * est remplacé par les deux scénarios ci-dessous.
 *
 * Le second scénario doit désactiver temporairement le déclencheur UPDATE
 * pour simuler une altération directe en base — DROP/CREATE TRIGGER sont des
 * DDL qui provoquent un commit implicite sur MariaDB. Ce fichier ne peut
 * donc pas s'appuyer sur RefreshDatabase (dont l'isolation par rollback de
 * transaction serait rompue par ce commit implicite, exactement le défaut
 * relevé sur l'ancien contournement d'AUTO_INCREMENT). Chaque test
 * réinitialise donc explicitement la table par TRUNCATE.
 */
beforeEach(fn () => DB::statement('TRUNCATE TABLE audit_log'));
afterEach(fn () => DB::statement('TRUNCATE TABLE audit_log'));

it('détecte une entrée forgée insérée directement en base', function (): void {
    $chain = app(AuditChain::class);
    $chain->append(ActorType::System, null, 'test.seq', 'asset', 1, ['i' => 1]);

    // Les déclencheurs ne bloquent pas les INSERT : une entrée dont le
    // chaînage est incohérent avec la tête réelle de la chaîne peut donc
    // être insérée directement (simulation d'un accès direct en base).
    $forgedId = DB::table('audit_log')->insertGetId([
        'actor_type' => 'system',
        'actor_id' => null,
        'action' => 'test.forge',
        'entity_type' => 'asset',
        'entity_id' => 2,
        'payload' => '{"i":2}',
        'record_hash' => str_repeat('f', 64),
        'prev_hash' => str_repeat('f', 64), // ne correspond à aucun chain_hash réel
        'chain_hash' => str_repeat('e', 64),
        'created_at' => now()->format('Y-m-d H:i:s'),
    ]);

    $result = $chain->verify();

    expect($result['valid'])->toBeFalse()
        ->and($result['broken_at'])->toBe($forgedId);
});

it('détecte une altération d\'octets d\'une ligne existante, déclencheur temporairement désactivé', function (): void {
    $chain = app(AuditChain::class);
    $chain->append(ActorType::System, null, 'test.seq', 'asset', 1, ['a' => 1, 'b' => 2]);
    $second = $chain->append(ActorType::System, null, 'test.seq', 'asset', 1, ['a' => 1, 'b' => 2]);

    expect($chain->verify())->toMatchArray(['valid' => true, 'broken_at' => null]);

    // Ce test documente la protection : sans la désactivation ci-dessous,
    // cette altération échouerait avec une erreur SQL (voir
    // tests/BusinessRules/ChaineAuditInalterableTest.php).
    DB::statement('DROP TRIGGER audit_log_interdit_update');

    try {
        // Mêmes données logiques que le payload stocké ({"a":1,"b":2}), mais
        // des octets différents (clés réordonnées, espace superflu) — le
        // défaut démontré par la revue faisait passer cette altération
        // inaperçue car verify() décodait puis ré-encodait le payload avant
        // de le hacher, au lieu de hacher les octets réellement stockés.
        DB::table('audit_log')->where('id', $second->id)->update([
            'payload' => '{"b":2,  "a":1}',
        ]);
    } finally {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_interdit_update
            BEFORE UPDATE ON audit_log
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = "La chaîne d'audit est append-only : mise à jour interdite.";
            END
        SQL);
    }

    $result = $chain->verify();

    expect($result['valid'])->toBeFalse()
        ->and($result['broken_at'])->toBe($second->id);
});

it('détecte une falsification de la colonne action, déclencheur temporairement désactivé', function (): void {
    // La revue a démontré qu'AuditLog::query()->update(['action' => ...])
    // passait la vérification sans rien casser, parce que l'ancienne
    // empreinte ne couvrait que le payload. La formule §4.5 couvre
    // désormais toutes les colonnes métier, `action` y compris.
    $chain = app(AuditChain::class);
    $entry = $chain->append(ActorType::System, null, 'test.seq', 'asset', 1, ['a' => 1]);

    DB::statement('DROP TRIGGER audit_log_interdit_update');

    try {
        DB::table('audit_log')->where('id', $entry->id)->update(['action' => 'FALSIFIE']);
    } finally {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_interdit_update
            BEFORE UPDATE ON audit_log
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = "La chaîne d'audit est append-only : mise à jour interdite.";
            END
        SQL);
    }

    $result = $chain->verify();

    expect($result['valid'])->toBeFalse()
        ->and($result['broken_at'])->toBe($entry->id);
});
