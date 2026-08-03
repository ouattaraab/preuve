<?php

declare(strict_types=1);

use App\Enums\DocumentType;
use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\User;
use App\Services\DocumentReviewService;
use App\Services\DocumentVault;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Chiffrement des pièces au repos (Loi 2013-450).
 *
 * La cible est un mutualisé Hostinger partagé avec neuf autres sites, et le
 * stockage objet n'est pas encore en place. Une lecture de fichier depuis chez
 * un voisin doit rendre du chiffré, et non des cartes grises ni des pièces
 * d'identité.
 *
 * Le chiffrement ne remplace pas un bucket privé : il limite les dégâts quand
 * le disque est partagé. La clé vit dans `.env`, hors du document root — la
 * même lecture de fichier ne la donne pas.
 *
 * N'utilise pas RefreshDatabase : le dépôt touche la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('asset_documents')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerChiffrement();

    Storage::fake('bucket');
    Config::set('preuve.documents.disk', 'bucket');
    Config::set('preuve.documents.encrypt_at_rest', true);
});
afterEach(fn () => nettoyerChiffrement());

function nettoyerChiffrement(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'kyc_submissions', 'asset_documents', 'notifications',
        'asset_status_history', 'assets', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function bienChiffre(): Asset
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

function deposerPiece(string $contenu): AssetDocument
{
    $bien = bienChiffre();
    $fichier = UploadedFile::fake()->createWithContent('carte-grise.jpg', $contenu);
    $deposant = User::find($bien->owner_user_id);

    return app(DocumentReviewService::class)->submit(
        $bien,
        $deposant instanceof User ? $deposant : User::create(['phone' => '+2250700000001']),
        DocumentType::RegistrationCard,
        $fichier,
    );
}

it('n\'écrit jamais une pièce en clair sur le disque', function (): void {
    // LE test. Une lecture de fichier depuis un site voisin du mutualisé doit
    // rendre du chiffré, pas le nom et l'adresse du titulaire.
    $contenu = 'CARTE GRISE — OUATTARA, Abidjan Cocody, VIN 1M8GDM9AXKP042788';
    $document = deposerPiece($contenu);

    $brut = (string) Storage::disk('bucket')->get($document->file_ref);

    expect($brut)->not->toContain('OUATTARA')
        ->and($brut)->not->toContain('Cocody')
        ->and($brut)->not->toContain('1M8GDM9AXKP042788');
});

it('inscrit le chiffrement dans le nom du fichier', function (): void {
    // Un drapeau de configuration rendrait illisibles toutes les pièces déjà
    // déposées le jour où quelqu'un le bascule. Le suffixe voyage avec le
    // fichier et survit à un changement de disque comme à une restauration.
    $document = deposerPiece('contenu quelconque');

    expect($document->file_ref)->toEndWith('.enc');
});

it('garde l\'empreinte sur le CLAIR', function (): void {
    // `file_sha256` atteste du document déposé et revu : sauvegarde, remontage
    // et réconciliation s'y réfèrent tous. La calculer sur le chiffré la
    // rendrait dépendante d'un détail de stockage — et le chiffrement étant
    // salé, deux dépôts du même document donneraient des empreintes
    // différentes.
    $contenu = 'contenu dont on connaît l\'empreinte';
    $document = deposerPiece($contenu);

    expect($document->file_sha256)->toBe(hash('sha256', $contenu));
});

it('rend le clair à la lecture', function (): void {
    $contenu = 'CARTE GRISE lisible par un agent';
    $document = deposerPiece($contenu);

    expect(app(DocumentReviewService::class)->readable($document))->toBe($contenu);
});

it('sert la pièce déchiffrée à un agent, sans cache', function (): void {
    $contenu = 'CARTE GRISE servie à la revue';
    $document = deposerPiece($contenu);

    $agent = User::create(['phone' => '+2250700000042']);
    $agent->forceFill(['role' => UserRole::Admin])->save();
    Sanctum::actingAs($agent);

    $reponse = test()->get('/api/v1/admin/documents/'.$document->id.'/file')->assertOk();

    expect($reponse->getContent())->toBe($contenu)
        // Ni navigateur, ni relais : une pièce nominative ne se met pas en cache.
        ->and($reponse->headers->get('Cache-Control'))->toContain('no-store');
});

it('refuse de servir une pièce à qui n\'est pas agent', function (): void {
    $document = deposerPiece('contenu confidentiel');

    Sanctum::actingAs(User::create(['phone' => '+2250700000077']));

    test()->getJson('/api/v1/admin/documents/'.$document->id.'/file')->assertStatus(403);
});

it('n\'expose plus de lien signé au porteur', function (): void {
    // Un lien signé est une capacité : recopié, il ouvre la pièce à qui n'est
    // pas agent, et il survit à la fin de la session de revue.
    $document = deposerPiece('contenu');

    $agent = User::create(['phone' => '+2250700000043']);
    $agent->forceFill(['role' => UserRole::Admin])->save();
    Sanctum::actingAs($agent);

    $reponse = test()->getJson('/api/v1/admin/documents')->assertOk();

    expect($reponse->json('documents.0.file_url'))
        ->toBe('/api/v1/admin/documents/'.$document->id.'/file')
        ->and($reponse->json('documents.0.file_url'))->not->toContain('signature');
});

it('dit qu\'une pièce est indéchiffrable plutôt que de rendre du charabia', function (): void {
    // Le cas le plus probable est une rotation d'APP_KEY sans reprise des
    // pièces. Le dire vaut mieux que rendre un contenu illisible qu'un agent
    // prendrait pour un document corrompu.
    $document = deposerPiece('contenu');

    Storage::disk('bucket')->put($document->file_ref, 'ceci n\'est pas un chiffré Laravel');

    expect(fn () => app(DocumentVault::class)->get($document->file_ref))
        ->toThrow(RuntimeException::class, 'indéchiffrable');
});

it('laisse la sauvegarde comparer des empreintes qui concordent', function (): void {
    // Si la sauvegarde lisait le fichier brut, elle comparerait du chiffré à
    // une empreinte de clair et rapporterait TOUT le parc comme altéré.
    Storage::fake('sauvegardes');
    Config::set('preuve.backup.disk', 'sauvegardes');

    deposerPiece('pièce à sauvegarder');

    expect(Artisan::call('preuve:backup-documents'))->toBe(0)
        ->and(Artisan::call('preuve:backup-documents', ['--verify' => true]))->toBe(0);
});

it('remonte une pièce sous sa forme chiffrée', function (): void {
    // La référence attendue par la base doit être reproduite à l'identique,
    // chiffrement compris : sinon la pièce remontée serait illisible.
    Storage::fake('sauvegardes');
    Config::set('preuve.backup.disk', 'sauvegardes');

    $contenu = 'pièce perdue puis remontée';
    $document = deposerPiece($contenu);

    Artisan::call('preuve:backup-documents');
    Storage::disk('bucket')->delete($document->file_ref);

    expect(Artisan::call('preuve:restore-documents'))->toBe(0);

    // Chiffrée sur le disque…
    expect((string) Storage::disk('bucket')->get($document->file_ref))->not->toContain('remontée');
    // …et lisible par le coffre.
    expect(app(DocumentVault::class)->get($document->file_ref))->toBe($contenu);
});
