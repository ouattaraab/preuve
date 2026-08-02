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
 * ST-0904 : sauvegarde du bucket de documents.
 *
 * Une base sans ses pièces ne restaure pas la plateforme : le dump rend les
 * lignes, les lignes désignent des clés objet, et sans les objets chaque
 * décision cesse d'être justifiable.
 *
 * N'utilise pas RefreshDatabase : les tables touchées portent des déclencheurs.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('kyc_submissions')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerSauvegardeDocs();

    Storage::fake('bucket');
    Storage::fake('sauvegardes');
    Config::set('preuve.documents.disk', 'bucket');
    Config::set('preuve.backup.disk', 'sauvegardes');
});
afterEach(fn () => nettoyerSauvegardeDocs());

function nettoyerSauvegardeDocs(): void
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

function bienAvecProprietaire(): Asset
{
    $proprietaire = User::create(['phone' => '+22507'.random_int(10000000, 99999999)]);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'voiture',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP0427'.random_int(10, 99),
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now(),
    ]);
}

/** Dépose une pièce sur le bucket feint et la référence en base. */
function pieceDeposee(string $contenu, ?Asset $bien = null): AssetDocument
{
    $bien ??= bienAvecProprietaire();
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

function cheminSauvegarde(string $contenu): string
{
    $sha = hash('sha256', $contenu);

    return 'documents/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha.'.enc';
}

it('sauvegarde une pièce, chiffrée et adressée par son contenu', function (): void {
    $contenu = 'CARTE GRISE — contenu nominatif';
    pieceDeposee($contenu);

    expect(Artisan::call('preuve:backup-documents'))->toBe(0);

    $chemin = cheminSauvegarde($contenu);
    Storage::disk('sauvegardes')->assertExists($chemin);

    $depose = (string) Storage::disk('sauvegardes')->get($chemin);

    // Chiffré : le stockage de sauvegarde est un tiers de plus, et une carte
    // grise porte le nom et l'adresse de son titulaire.
    expect($depose)->not->toContain('nominatif')
        ->and(Crypt::decryptString($depose))->toBe($contenu);
});

it('couvre les pièces de réclamation et les documents d\'identité', function (): void {
    // LE test de cette commande. Le bucket ne porte pas que les justificatifs
    // de biens : n'en sauvegarder qu'une famille produirait le pire des
    // résultats — une sauvegarde qui paraît complète, alors que les pièces sur
    // lesquelles un arbitrage a tranché la propriété de quelqu'un manquent.
    $bien = bienAvecProprietaire();
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
    $verso = 'CNI verso';
    $selfie = 'selfie';

    foreach (['kyc/1/recto.jpg' => $recto, 'kyc/1/verso.jpg' => $verso, 'kyc/1/selfie.jpg' => $selfie] as $cle => $valeur) {
        Storage::disk('bucket')->put($cle, $valeur);
    }

    DB::table('kyc_submissions')->insert([
        'user_id' => $utilisateur?->id,
        'status' => 'pending',
        'id_front_ref' => 'kyc/1/recto.jpg',
        'id_back_ref' => 'kyc/1/verso.jpg',
        'selfie_ref' => 'kyc/1/selfie.jpg',
        'id_front_sha256' => hash('sha256', $recto),
        'id_back_sha256' => hash('sha256', $verso),
        'selfie_sha256' => hash('sha256', $selfie),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    pieceDeposee('justificatif du bien', $bien);

    expect(Artisan::call('preuve:backup-documents'))->toBe(0);

    foreach ([$preuve, $recto, $verso, $selfie, 'justificatif du bien'] as $attendu) {
        Storage::disk('sauvegardes')->assertExists(cheminSauvegarde($attendu));
    }
});

it('ne renvoie pas une pièce déjà sauvegardée', function (): void {
    // L'adressage par contenu ramène « est-ce déjà sauvegardé ? » à une
    // existence de fichier : c'est ce qui rend une sauvegarde quotidienne
    // tenable sur un mutualisé.
    pieceDeposee('une pièce');

    Artisan::call('preuve:backup-documents');
    Artisan::call('preuve:backup-documents');

    expect(Artisan::output())->toContain('Déjà sauvegardées');

    // Une seule copie, malgré deux passages.
    expect(Storage::disk('sauvegardes')->allFiles('documents'))->toHaveCount(1);
});

it('ne stocke qu\'une fois deux pièces identiques', function (): void {
    pieceDeposee('contenu strictement identique');
    pieceDeposee('contenu strictement identique');

    Artisan::call('preuve:backup-documents');

    expect(Storage::disk('sauvegardes')->allFiles('documents'))->toHaveCount(1);
});

it('signale une pièce modifiée depuis son dépôt, sans la perdre', function (): void {
    // C'est exactement ce que `file_sha256` existe pour détecter : une pièce
    // acceptée puis remplacée dans le stockage ferait tenir un niveau de
    // fiabilité sur un document qui n'est plus celui qui a été revu.
    $document = pieceDeposee('contenu d\'origine');

    // Le fichier change sans que la ligne bouge.
    Storage::disk('bucket')->put($document->file_ref, 'contenu substitué');

    expect(Artisan::call('preuve:backup-documents'))->toBe(1)
        ->and(Artisan::output())->toContain('modifiée depuis son dépôt');

    // Sauvegardée quand même, sous son empreinte réelle : refuser laisserait
    // pour seule copie celle, peut-être altérée, du bucket.
    Storage::disk('sauvegardes')->assertExists(cheminSauvegarde('contenu substitué'));
});

it('signale une pièce référencée mais absente du bucket', function (): void {
    // Une décision qui s'appuie sur un document que plus personne ne peut
    // produire.
    $document = pieceDeposee('pièce qui va disparaître');
    Storage::disk('bucket')->delete($document->file_ref);

    expect(Artisan::call('preuve:backup-documents'))->toBe(1)
        ->and(Artisan::output())->toContain('introuvable dans le bucket');
});

it('borne chaque passage et dit ce qui reste', function (): void {
    // Un plafond silencieux se lit comme une couverture complète.
    pieceDeposee('première');
    pieceDeposee('deuxième');
    pieceDeposee('troisième');

    Artisan::call('preuve:backup-documents', ['--limit' => 2]);

    expect(Artisan::output())->toContain('non traitées')
        ->and(Storage::disk('sauvegardes')->allFiles('documents'))->toHaveCount(2);

    // Le passage suivant termine le travail.
    Artisan::call('preuve:backup-documents', ['--limit' => 2]);

    expect(Storage::disk('sauvegardes')->allFiles('documents'))->toHaveCount(3);
});

it('constate la couverture sans rien copier', function (): void {
    pieceDeposee('à constater');

    Artisan::call('preuve:backup-documents', ['--verify' => true]);

    expect(Artisan::output())->toContain('restent à sauvegarder')
        ->and(Storage::disk('sauvegardes')->allFiles('documents'))->toBeEmpty();
});

it('détecte une substitution survenue APRÈS la première sauvegarde', function (): void {
    // LE cas que la sauvegarde incrémentale ne peut pas voir, par construction :
    // elle saute ce qui est déjà sauvegardé sans le relire, donc elle ne touche
    // plus jamais la seule pièce qui aurait changé. C'est pourquoi le contrôle
    // d'intégrité est une opération distincte, et non un effet de bord de la
    // sauvegarde.
    $document = pieceDeposee('contenu d\'origine');

    Artisan::call('preuve:backup-documents');
    Storage::disk('sauvegardes')->assertExists(cheminSauvegarde('contenu d\'origine'));

    // La pièce est substituée dans le stockage, la ligne ne bouge pas.
    Storage::disk('bucket')->put($document->file_ref, 'contenu substitué après coup');

    // La sauvegarde quotidienne ne voit rien, et ne prétend rien voir.
    expect(Artisan::call('preuve:backup-documents'))->toBe(0);

    // Le contrôle d'intégrité, lui, le constate.
    expect(Artisan::call('preuve:backup-documents', ['--verify' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('modifiée depuis son dépôt');
});

it('refuse de sauvegarder sur le disque des documents', function (): void {
    // Une copie qui vit à côté de l'original ne protège ni d'une panne, ni
    // d'un effacement.
    Config::set('preuve.backup.disk', 'bucket');

    expect(Artisan::call('preuve:backup-documents'))->toBe(1)
        ->and(Artisan::output())->toContain('disque de sauvegarde est celui des documents');
});

it('n\'efface jamais une sauvegarde existante', function (): void {
    // Contrairement aux dumps, dont seul le dernier compte, chaque objet est
    // l'unique copie d'une pièce distincte : élaguer par ancienneté effacerait
    // la sauvegarde d'une carte grise de deux ans, qui justifie toujours une
    // propriété aujourd'hui.
    $ancienne = pieceDeposee('pièce ancienne');
    Artisan::call('preuve:backup-documents');

    $cheminAncien = cheminSauvegarde('pièce ancienne');
    Storage::disk('sauvegardes')->assertExists($cheminAncien);

    // Deux ans passent, et cent autres pièces arrivent.
    $ancienne->forceFill(['created_at' => now()->subYears(2)])->save();
    pieceDeposee('pièce récente');

    Artisan::call('preuve:backup-documents');

    Storage::disk('sauvegardes')->assertExists($cheminAncien);
});
