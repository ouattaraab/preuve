<?php

declare(strict_types=1);

use App\Enums\DocumentReviewStatus;
use App\Enums\DocumentType;
use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * ST-0904 : remontage des pièces vers le bucket.
 *
 * Le remontage se lit à l'envers de la sauvegarde : celle-ci déduplique par
 * contenu, celui-ci doit redéployer un même objet vers plusieurs clés. Il
 * parcourt donc la BASE, jamais le dépôt de sauvegarde — seule la base sait
 * quelle empreinte correspond à quelle clé objet.
 *
 * N'utilise pas RefreshDatabase : les tables touchées portent des déclencheurs.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('kyc_submissions')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerRemontage();

    Storage::fake('bucket');
    Storage::fake('sauvegardes');
    Config::set('preuve.documents.disk', 'bucket');
    Config::set('preuve.backup.disk', 'sauvegardes');
});
afterEach(fn () => nettoyerRemontage());

function nettoyerRemontage(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'kyc_submissions', 'claim_evidences', 'claims', 'asset_documents',
        'asset_status_history', 'assets', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function bienARemonter(): Asset
{
    $proprietaire = User::create(['phone' => '+22507'.random_int(10000000, 99999999)]);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'voiture',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        // Réellement unique : l'index (identifier_normalized, active_flag) est
        // la règle métier absolue n° 3, et deux biens de test qui collident
        // feraient échouer le test pour une raison sans rapport avec son objet.
        'identifier_normalized' => 'BENCH'.strtoupper(bin2hex(random_bytes(6))),
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now(),
    ]);
}

/** Référence une pièce en base et la dépose sur le bucket. */
function pieceARemonter(string $contenu, ?Asset $bien = null): AssetDocument
{
    $bien ??= bienARemonter();
    $chemin = 'assets/'.$bien->id.'/'.bin2hex(random_bytes(8)).'.jpg';

    Storage::disk('bucket')->put($chemin, $contenu);

    return AssetDocument::create([
        'asset_id' => $bien->id,
        'uploaded_by' => $bien->owner_user_id,
        'doc_type' => DocumentType::RegistrationCard,
        'file_ref' => $chemin,
        'file_sha256' => hash('sha256', $contenu),
        'review_status' => DocumentReviewStatus::Pending,
    ]);
}

/** Simule le sinistre : le bucket disparaît, la base et la sauvegarde restent. */
function sinistreDuBucket(): void
{
    Storage::fake('bucket');
}

function archiveDe(string $contenu): string
{
    $sha = hash('sha256', $contenu);

    return 'documents/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha.'.enc';
}

it('remonte une pièce identique à l\'originale après un sinistre', function (): void {
    $contenu = 'CARTE GRISE — original';
    $document = pieceARemonter($contenu);

    Artisan::call('preuve:backup-documents');
    sinistreDuBucket();

    expect(Storage::disk('bucket')->exists($document->file_ref))->toBeFalse();

    expect(Artisan::call('preuve:restore-documents'))->toBe(0);

    Storage::disk('bucket')->assertExists($document->file_ref);

    // Octet pour octet : c'est l'empreinte figée au dépôt qui en répond.
    expect((string) Storage::disk('bucket')->get($document->file_ref))->toBe($contenu);
});

it('redéploie une sauvegarde unique vers toutes les clés qui l\'attendent', function (): void {
    // LE point qui distingue le remontage de la sauvegarde. Celle-ci déduplique
    // par contenu : deux pièces identiques n'occupent qu'un objet. Parcourir le
    // dépôt de sauvegarde ne dirait jamais où chacune doit atterrir.
    $contenu = 'pièce strictement identique';
    $premier = pieceARemonter($contenu);
    $second = pieceARemonter($contenu);

    Artisan::call('preuve:backup-documents');

    expect(Storage::disk('sauvegardes')->allFiles('documents'))->toHaveCount(1);

    sinistreDuBucket();
    Artisan::call('preuve:restore-documents');

    Storage::disk('bucket')->assertExists($premier->file_ref);
    Storage::disk('bucket')->assertExists($second->file_ref);
    expect($premier->file_ref)->not->toBe($second->file_ref);
});

it('remonte les trois familles', function (): void {
    $bien = bienARemonter();
    $utilisateur = User::find($bien->owner_user_id);

    $preuve = 'FACTURE versée à la réclamation';
    Storage::disk('bucket')->put('claims/1/preuve.pdf', $preuve);

    $claimId = DB::table('claims')->insertGetId([
        'asset_id' => $bien->id,
        'claimant_user_id' => $bien->owner_user_id,
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('claim_evidences')->insert([
        'claim_id' => $claimId,
        'party' => 'claimant',
        'evidence_type' => 'invoice',
        'file_ref' => 'claims/1/preuve.pdf',
        'file_sha256' => hash('sha256', $preuve),
        'weight_applied' => 0,
        'created_at' => now(),
    ]);

    $recto = 'CNI recto';
    Storage::disk('bucket')->put('kyc/1/recto.jpg', $recto);
    Storage::disk('bucket')->put('kyc/1/verso.jpg', 'CNI verso');
    Storage::disk('bucket')->put('kyc/1/selfie.jpg', 'selfie');

    DB::table('kyc_submissions')->insert([
        'user_id' => $utilisateur?->id,
        'status' => 'pending',
        'id_front_ref' => 'kyc/1/recto.jpg',
        'id_back_ref' => 'kyc/1/verso.jpg',
        'selfie_ref' => 'kyc/1/selfie.jpg',
        'id_front_sha256' => hash('sha256', $recto),
        'id_back_sha256' => hash('sha256', 'CNI verso'),
        'selfie_sha256' => hash('sha256', 'selfie'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $justificatif = pieceARemonter('justificatif du bien', $bien);

    Artisan::call('preuve:backup-documents');
    sinistreDuBucket();

    expect(Artisan::call('preuve:restore-documents'))->toBe(0);

    foreach ([
        'claims/1/preuve.pdf', 'kyc/1/recto.jpg', 'kyc/1/verso.jpg',
        'kyc/1/selfie.jpg', $justificatif->file_ref,
    ] as $cle) {
        Storage::disk('bucket')->assertExists($cle);
    }
});

it('n\'écrase jamais une pièce qui a survécu', function (): void {
    // Un remontage se lance après un sinistre souvent partiel, souvent dans
    // l'urgence : écraser ce qui a survécu par une version plus ancienne
    // transformerait la restauration en seconde perte.
    $document = pieceARemonter('version sauvegardée');
    Artisan::call('preuve:backup-documents');

    // Sur le bucket, la pièce vit et a changé depuis.
    Storage::disk('bucket')->put($document->file_ref, 'version présente sur le bucket');

    Artisan::call('preuve:restore-documents');

    expect((string) Storage::disk('bucket')->get($document->file_ref))
        ->toBe('version présente sur le bucket');
});

it('refuse d\'écrire un contenu qui ne correspond pas à son empreinte', function (): void {
    // Écrire un contenu erroné sous une clé attendue serait pire que de la
    // laisser vide : un agent examinerait un document en croyant que c'est
    // celui qui a été versé, et trancherait dessus.
    $document = pieceARemonter('contenu attendu');
    Artisan::call('preuve:backup-documents');

    // Le dépôt de sauvegarde lui-même est altéré.
    Storage::disk('sauvegardes')->put(
        archiveDe('contenu attendu'),
        Crypt::encryptString('tout autre chose'),
    );

    sinistreDuBucket();

    expect(Artisan::call('preuve:restore-documents'))->toBe(1)
        ->and(Artisan::output())->toContain('non conforme à son empreinte');

    expect(Storage::disk('bucket')->exists($document->file_ref))->toBeFalse();
});

it('signale une archive illisible plutôt que de la déposer', function (): void {
    pieceARemonter('contenu attendu');
    Artisan::call('preuve:backup-documents');

    Storage::disk('sauvegardes')->put(archiveDe('contenu attendu'), 'ceci n\'est pas un chiffré');
    sinistreDuBucket();

    expect(Artisan::call('preuve:restore-documents'))->toBe(1)
        ->and(Artisan::output())->toContain('archive illisible');
});

it('nomme les pièces que la sauvegarde ne contient pas', function (): void {
    // Perte réelle : un bien dont la décision s'appuie sur un document que plus
    // personne ne peut produire. Ils doivent être nommés, pas comptés.
    pieceARemonter('jamais sauvegardée');
    sinistreDuBucket();

    $code = Artisan::call('preuve:restore-documents');
    // Capturé une seule fois : Artisan::output() vide le tampon à la lecture.
    $sortie = Artisan::output();

    expect($code)->toBe(1)
        ->and($sortie)->toContain('absente de la sauvegarde')
        ->and($sortie)->toContain('REMONTAGE INCOMPLET');
});

it('constate sans rien écrire', function (): void {
    $document = pieceARemonter('à constater');
    Artisan::call('preuve:backup-documents');
    sinistreDuBucket();

    Artisan::call('preuve:restore-documents', ['--dry-run' => true]);

    expect(Artisan::output())->toContain('seraient remontées')
        ->and(Storage::disk('bucket')->exists($document->file_ref))->toBeFalse();
});

it('borne chaque passage et dit ce qui reste', function (): void {
    $pieces = [];

    foreach (['une', 'deux', 'trois'] as $contenu) {
        $pieces[] = pieceARemonter($contenu);
    }

    Artisan::call('preuve:backup-documents');
    sinistreDuBucket();

    Artisan::call('preuve:restore-documents', ['--limit' => 2]);

    expect(Artisan::output())->toContain('non traitées')
        ->and(Storage::disk('bucket')->allFiles())->toHaveCount(2);

    Artisan::call('preuve:restore-documents', ['--limit' => 2]);

    expect(Storage::disk('bucket')->allFiles())->toHaveCount(3);
});

it('ne remonte rien quand sauvegarde et bucket sont le même disque', function (): void {
    Config::set('preuve.backup.disk', 'bucket');

    expect(Artisan::call('preuve:restore-documents'))->toBe(1)
        ->and(Artisan::output())->toContain('rien à remonter');
});
