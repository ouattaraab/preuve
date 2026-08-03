<?php

declare(strict_types=1);

use App\Console\Commands\BackupDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * ST-0904 : exercice de restauration.
 *
 * Une sauvegarde jamais restaurée est une hypothèse. Ces tests portent sur les
 * GARDE-FOUS de l'exercice, pas sur la restauration elle-même : celle-ci exige
 * de créer et de supprimer des bases, un droit que le compte applicatif ne doit
 * justement pas avoir. La restauration complète se mène à la main, avec des
 * identifiants d'exploitation, et son constat est consigné dans
 * `docs/infrastructure/exploitation.md`.
 *
 * Ce qui est vérifié ici est ce qui rend l'outil sûr à lancer : le jour d'une
 * panne, sous pression, c'est de travers qu'on le lancera.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('audit_log')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    Storage::fake('sauvegardes');
    Config::set('preuve.backup.disk', 'sauvegardes');
});

it('refuse de restaurer par-dessus la base en service', function (): void {
    // LA garde. Sans elle, une erreur de configuration ferait de cet exercice
    // l'outil qui écrase la base qu'il prétend protéger — et c'est un jour de
    // panne qu'on s'en apercevrait.
    Storage::disk('sauvegardes')->put(
        'backups/preuve-2026-01-01-000000.sql.enc',
        Crypt::encryptString('-- peu importe le contenu'),
    );

    $code = Artisan::call('preuve:restore-drill', [
        '--database' => DB::connection()->getDatabaseName(),
    ]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('base en service');

    // Et la base en service n'a pas bougé.
    expect(Schema::hasTable('audit_log'))->toBeTrue();
});

it('échoue plutôt que d\'écrire quand la base d\'exercice est inatteignable', function (): void {
    Storage::disk('sauvegardes')->put(
        'backups/preuve-2026-01-01-000000.sql.enc',
        Crypt::encryptString('-- peu importe le contenu'),
    );

    $code = Artisan::call('preuve:restore-drill', [
        '--database' => 'preuve_exercice_inexistant_'.bin2hex(random_bytes(4)),
    ]);

    expect($code)->toBe(1);

    // La garde « base non vide » ne peut pas être éprouvée ici : elle demande
    // une SECONDE base réelle, donc le droit d'en créer une — celui que le
    // compte applicatif ne doit précisément pas avoir. Elle est couverte par
    // l'exercice manuel décrit dans docs/infrastructure/exploitation.md.
});

it('signale une archive indéchiffrable plutôt que de la charger', function (): void {
    // Le pire des cas : une archive qui occupe la place d'une sauvegarde sans
    // en être une. Le plus souvent, APP_KEY a tourné sans que les anciennes
    // archives soient reprises.
    Storage::disk('sauvegardes')->put(
        'backups/preuve-2026-01-01-000000.sql.enc',
        'ceci n\'est pas un chiffré Laravel',
    );

    $code = Artisan::call('preuve:restore-drill');

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('APP_KEY');
});

it('refuse de s\'exécuter sans disque de sauvegarde', function (): void {
    Config::set('preuve.backup.disk', null);

    expect(Artisan::call('preuve:restore-drill'))->toBe(1);
});

it('dit clairement qu\'il n\'y a rien à éprouver', function (): void {
    // Un disque configuré mais vide n'est pas une réussite : c'est l'absence
    // de sauvegarde, et elle doit se voir.
    $code = Artisan::call('preuve:restore-drill');

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('Aucune sauvegarde');
});

it('s\'exécute en production quand la base d\'exercice est fournie', function (): void {
    // Le refus de la production portait sur la création et la suppression de
    // bases, pas sur l'exercice. Avec une base préparée par l'exploitant,
    // l'outil n'en crée ni n'en supprime aucune — et maintenir le refus aurait
    // rendu la dette « restauration jamais éprouvée sur la cible »
    // définitivement inclosable.
    Storage::disk('sauvegardes')->put(
        'backups/preuve-2026-01-01-000000.sql.enc',
        Crypt::encryptString('-- peu importe'),
    );

    app()->detectEnvironment(fn (): string => 'production');

    // Échoue parce que la base n'existe pas, PAS parce qu'on est en production.
    $code = Artisan::call('preuve:restore-drill', [
        '--database' => 'preuve_exercice_inexistant_'.bin2hex(random_bytes(4)),
    ]);
    $sortie = Artisan::output();

    app()->detectEnvironment(fn (): string => 'testing');

    expect($code)->toBe(1)
        ->and($sortie)->not->toContain('base préparée à l\'avance');
});

it('refuse en production sans base fournie', function (): void {
    Storage::disk('sauvegardes')->put(
        'backups/preuve-2026-01-01-000000.sql.enc',
        Crypt::encryptString('-- peu importe'),
    );

    app()->detectEnvironment(fn (): string => 'production');
    $code = Artisan::call('preuve:restore-drill');
    $sortie = Artisan::output();
    app()->detectEnvironment(fn (): string => 'testing');

    expect($code)->toBe(1)
        ->and($sortie)->toContain('base préparée à l\'avance');
});

it('produit une sauvegarde restaurable par un AUTRE compte', function (): void {
    // Constaté au premier exercice réel sur l'hébergement cible : mysqldump fige
    // dans chaque déclencheur le compte qui l'a créé. Les recréer sous un autre
    // compte exige le privilège SET USER, qu'un mutualisé n'accorde jamais — la
    // sauvegarde n'était donc restaurable que par le compte d'origine, c'est-à-dire
    // par celui qui a disparu avec le serveur.
    $dump = <<<'SQL'
    /*!50003 CREATE*/ /*!50017 DEFINER=`u726808002_napster010826`@`127.0.0.1`*/ /*!50003 TRIGGER audit_log_interdit_update
    BEFORE UPDATE ON audit_log FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000'; END */;
    CREATE DEFINER=`autre`@`localhost` PROCEDURE `x`() BEGIN END;
    SQL;

    $propre = app(BackupDatabase::class)->withoutDefiners($dump);

    expect($propre)->not->toContain('DEFINER')
        // Le déclencheur lui-même survit : c'est lui qui rend le journal
        // inaltérable, et une restauration sans lui serait pire que rien.
        ->and($propre)->toContain('TRIGGER audit_log_interdit_update')
        ->and($propre)->toContain('SIGNAL SQLSTATE');
});
