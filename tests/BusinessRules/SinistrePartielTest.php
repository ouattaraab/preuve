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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * ST-0904 : sinistre partiel, base et bucket divergents.
 *
 * Le cas ordinaire. Une perte totale se voit et se traite ; ce qui arrive
 * vraiment, c'est une base remontée à J-1 pendant que le bucket est resté à J,
 * ou l'inverse. Chaque moitié paraît saine prise isolément.
 *
 * Le sens que rien d'autre ne voit est celui des ORPHELINS : la sauvegarde
 * comme le remontage énumèrent depuis la base, donc une pièce que plus rien ne
 * référence leur est invisible par construction.
 *
 * N'utilise pas RefreshDatabase : les tables touchées portent des déclencheurs.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('kyc_submissions')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerSinistre();

    Storage::fake('bucket');
    Storage::fake('sauvegardes');
    Config::set('preuve.documents.disk', 'bucket');
    Config::set('preuve.backup.disk', 'sauvegardes');
});
afterEach(fn () => nettoyerSinistre());

function nettoyerSinistre(): void
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

function bienSinistre(): Asset
{
    $proprietaire = User::create(['phone' => '+22507'.random_int(10000000, 99999999)]);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'voiture',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => 'BENCH'.strtoupper(bin2hex(random_bytes(6))),
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now(),
    ]);
}

function pieceSinistre(string $contenu, ?Asset $bien = null): AssetDocument
{
    $bien ??= bienSinistre();
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

it('constate la concordance quand rien n\'a divergé', function (): void {
    pieceSinistre('tout va bien');

    expect(Artisan::call('preuve:reconcile-documents'))->toBe(0)
        ->and(Artisan::output())->toContain('concordent');
});

it('voit une pièce que plus rien ne référence', function (): void {
    // LE sens que rien d'autre ne voit. La base a été remontée à J-1 : la ligne
    // qui désignait cette pièce n'existe plus, mais le fichier est resté. Ni la
    // sauvegarde ni le remontage ne peuvent le savoir — tous deux énumèrent
    // depuis la base.
    pieceSinistre('pièce toujours référencée');

    // Une pièce dont la ligne a disparu au remontage de la base.
    Storage::disk('bucket')->put('assets/999/orpheline.jpg', 'CARTE GRISE sans ligne');

    $code = Artisan::call('preuve:reconcile-documents');
    $sortie = Artisan::output();

    expect($code)->toBe(1)
        ->and($sortie)->toContain('que plus rien ne référence')
        ->and($sortie)->toContain('assets/999/orpheline.jpg');
});

it('ne supprime jamais un orphelin', function (): void {
    // Un orphelin est le plus souvent une pièce dont la ligne a été perdue :
    // l'effacer détruirait la seule trace restante, au moment précis où elle
    // est la plus précieuse.
    Storage::disk('bucket')->put('assets/999/orpheline.jpg', 'seule trace restante');

    Artisan::call('preuve:reconcile-documents');

    Storage::disk('bucket')->assertExists('assets/999/orpheline.jpg');
    expect((string) Storage::disk('bucket')->get('assets/999/orpheline.jpg'))
        ->toBe('seule trace restante');
});

it('rappelle qu\'une pièce nominative sans propriétaire ne doit pas rester', function (): void {
    // Une carte grise sans propriétaire déclaré, sans chemin de revue et sans
    // échéance de purge est une donnée personnelle conservée pour rien
    // (Loi 2013-450, minimisation). Ne pas supprimer n'est pas ne rien faire.
    Storage::disk('bucket')->put('kyc/999/cni.jpg', 'pièce d\'identité orpheline');

    Artisan::call('preuve:reconcile-documents');

    expect(Artisan::output())->toContain('2013-450');
});

it('distingue une pièce récupérable d\'une perte définitive', function (): void {
    // La conduite à tenir n'est pas la même, et un rapport qui confondrait les
    // deux ferait chercher longtemps.
    $sauvee = pieceSinistre('celle-ci était sauvegardée');
    $perdue = pieceSinistre('celle-là ne l\'a jamais été');

    // Seule la première est sauvegardée.
    Artisan::call('preuve:backup-documents');
    Storage::disk('sauvegardes')->delete(
        collect(Storage::disk('sauvegardes')->allFiles('documents'))
            ->first(fn (string $f): bool => ! str_contains(
                $f,
                hash('sha256', 'celle-ci était sauvegardée'),
            )) ?? '',
    );

    // Le bucket perd les deux.
    Storage::disk('bucket')->delete($sauvee->file_ref);
    Storage::disk('bucket')->delete($perdue->file_ref);

    $code = Artisan::call('preuve:reconcile-documents');
    $sortie = Artisan::output();

    expect($code)->toBe(1)
        ->and($sortie)->toContain('PRÉSENTE dans la sauvegarde')
        ->and($sortie)->toContain('restore-documents')
        ->and($sortie)->toContain('PERTE DÉFINITIVE');
});

it('reconnaît les pièces des trois familles', function (): void {
    // Sans cela, chaque pièce de réclamation et chaque document d'identité
    // serait rapporté comme orphelin — un rapport noyé sous de faux positifs
    // n'est plus lu.
    $bien = bienSinistre();
    $utilisateur = User::find($bien->owner_user_id);

    $preuve = 'facture de réclamation';
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

    Storage::disk('bucket')->put('kyc/1/recto.jpg', 'recto');
    Storage::disk('bucket')->put('kyc/1/verso.jpg', 'verso');
    Storage::disk('bucket')->put('kyc/1/selfie.jpg', 'selfie');

    DB::table('kyc_submissions')->insert([
        'user_id' => $utilisateur?->id,
        'status' => 'pending',
        'id_front_ref' => 'kyc/1/recto.jpg',
        'id_back_ref' => 'kyc/1/verso.jpg',
        'selfie_ref' => 'kyc/1/selfie.jpg',
        'id_front_sha256' => hash('sha256', 'recto'),
        'id_back_sha256' => hash('sha256', 'verso'),
        'selfie_sha256' => hash('sha256', 'selfie'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    pieceSinistre('justificatif', $bien);

    expect(Artisan::call('preuve:reconcile-documents'))->toBe(0)
        ->and(Artisan::output())->toContain('concordent');
});

it('ne prend pas pour un orphelin ce qui n\'est pas une pièce', function (): void {
    // Défaut constaté en réel : le disque peut porter autre chose que des
    // pièces — ancrages d'audit, marqueurs de dépôt, résidus d'exploitation. En
    // production le bucket est dédié, mais un rapport noyé sous des faux
    // positifs n'est plus lu, et c'est celui-là qui doit l'être après un
    // sinistre.
    pieceSinistre('une vraie pièce');

    Storage::disk('bucket')->put('anchors/ancrage-2026-08-02.txt', 'constat d\'ancrage');
    Storage::disk('bucket')->put('.gitignore', '*');

    expect(Artisan::call('preuve:reconcile-documents'))->toBe(0)
        ->and(Artisan::output())->toContain('concordent');
});

it('refuse un préfixe qui ne porte aucune pièce', function (): void {
    expect(Artisan::call('preuve:reconcile-documents', ['--prefix' => 'anchors']))->toBe(1)
        ->and(Artisan::output())->toContain('Préfixe inconnu');
});

it('dit ce qu\'un balayage partiel n\'a pas examiné', function (): void {
    // Un balayage partiel qui se tairait se lirait comme un balayage complet.
    pieceSinistre('dans assets');
    Storage::disk('bucket')->put('kyc/1/hors-perimetre.jpg', 'hors du préfixe');

    $code = Artisan::call('preuve:reconcile-documents', ['--prefix' => 'kyc']);
    $sortie = Artisan::output();

    // La pièce d'assets n'est pas examinée, et c'est dit.
    expect($sortie)->toContain('hors du préfixe « kyc »')
        // L'objet kyc, lui, est bien vu comme orphelin.
        ->and($sortie)->toContain('hors-perimetre.jpg')
        ->and($code)->toBe(1);
});

it('ne confond pas un remontage complet avec une divergence', function (): void {
    // Le scénario nominal après sinistre : sauvegarde, perte, remontage,
    // réconciliation. Elle doit alors ne rien trouver.
    pieceSinistre('avant le sinistre');
    Artisan::call('preuve:backup-documents');

    Storage::fake('bucket');
    Artisan::call('preuve:restore-documents');

    expect(Artisan::call('preuve:reconcile-documents'))->toBe(0);
});
