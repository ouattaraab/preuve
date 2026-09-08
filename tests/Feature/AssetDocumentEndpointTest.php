<?php

declare(strict_types=1);

use App\Enums\DocumentReviewStatus;
use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * ST-0207 (renforcement après coup) et ST-0208 (revue par un agent).
 *
 * N'utilise pas RefreshDatabase : la revue écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('asset_documents')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerDocuments();
    Storage::fake('s3');
});
afterEach(fn () => nettoyerDocuments());

function nettoyerDocuments(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'notifications', 'asset_documents', 'asset_status_history',
        'assets', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function proprietaireKyc(bool $verifie = true): User
{
    $utilisateur = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);

    if ($verifie) {
        $utilisateur->forceFill(['kyc_status' => 'verified', 'kyc_verified_at' => now()])->save();
    }

    return $utilisateur;
}

function bienDocumentable(User $proprietaire): Asset
{
    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP0'.random_int(10000, 99999),
        'identifier_normalized' => '1M8GDM9AXKP0'.random_int(10000, 99999),
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonth(),
    ]);
}

function agent(): User
{
    $utilisateur = User::create(['phone' => '+2250799'.random_int(100000, 999999)]);
    $utilisateur->forceFill(['role' => UserRole::Agent])->save();

    return $utilisateur;
}

it('exige une authentification pour déposer un justificatif', function (): void {
    $bien = bienDocumentable(proprietaireKyc());

    $this->postJson("/api/v1/assets/{$bien->id}/documents", [
        'doc_type' => 'invoice',
        'file' => UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf'),
    ])->assertStatus(401);
});

it('dépose un justificatif sur son propre bien', function (): void {
    $proprietaire = proprietaireKyc();
    $bien = bienDocumentable($proprietaire);
    Sanctum::actingAs($proprietaire);

    $this->postJson("/api/v1/assets/{$bien->id}/documents", [
        'doc_type' => 'invoice',
        'file' => UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf'),
    ])->assertStatus(201)->assertJsonPath('document.review_status', 'pending');

    $document = AssetDocument::sole();

    expect($document->asset_id)->toBe($bien->id)
        ->and($document->file_sha256)->toHaveLength(64);

    Storage::disk('s3')->assertExists($document->file_ref);
});

it('ne laisse pas déposer sur le bien d\'un autre', function (): void {
    // 404 plutôt que 403 : confirmer l'existence d'un bien par son identifiant
    // interne donnerait un moyen de balayage.
    $bien = bienDocumentable(proprietaireKyc());
    Sanctum::actingAs(proprietaireKyc());

    $this->postJson("/api/v1/assets/{$bien->id}/documents", [
        'doc_type' => 'invoice',
        'file' => UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf'),
    ])->assertStatus(404);

    expect(AssetDocument::count())->toBe(0);
});

it('ne fait pas monter le niveau au seul dépôt', function (): void {
    // Sinon il suffirait de téléverser n'importe quoi pour paraître documenté
    // le temps d'une vente.
    $proprietaire = proprietaireKyc();
    $bien = bienDocumentable($proprietaire);
    Sanctum::actingAs($proprietaire);

    $this->postJson("/api/v1/assets/{$bien->id}/documents", [
        'doc_type' => 'invoice',
        'file' => UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf'),
    ])->assertStatus(201)->assertJsonPath('trust.current', 'F1');

    expect($bien->fresh()?->trust_level)->toBe(TrustLevel::Declared);
});

it('refuse un fichier trop lourd ou d\'un type inattendu', function (): void {
    $proprietaire = proprietaireKyc();
    $bien = bienDocumentable($proprietaire);
    Sanctum::actingAs($proprietaire);

    $this->postJson("/api/v1/assets/{$bien->id}/documents", [
        'doc_type' => 'invoice',
        'file' => UploadedFile::fake()->create('enorme.pdf', 20000, 'application/pdf'),
    ])->assertStatus(422);

    $this->postJson("/api/v1/assets/{$bien->id}/documents", [
        'doc_type' => 'invoice',
        'file' => UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream'),
    ])->assertStatus(422);
});

it('sert la jauge de progression au propriétaire', function (): void {
    $proprietaire = proprietaireKyc(verifie: false);
    $bien = bienDocumentable($proprietaire);
    Sanctum::actingAs($proprietaire);

    $this->getJson("/api/v1/assets/{$bien->id}/trust")
        ->assertOk()
        ->assertJsonPath('trust.current', 'F1')
        ->assertJsonPath('trust.next', 'F2')
        ->assertJsonFragment(['missing' => ['proof_of_ownership', 'kyc_verified']]);
});

it('réserve la file de revue au back-office', function (): void {
    Sanctum::actingAs(proprietaireKyc());

    $this->getJson('/api/v1/admin/documents')->assertStatus(403);
});

it('sert la file des pièces en attente à un agent', function (): void {
    $proprietaire = proprietaireKyc();
    $bien = bienDocumentable($proprietaire);
    AssetDocument::create([
        'asset_id' => $bien->id, 'uploaded_by' => $proprietaire->id, 'doc_type' => 'invoice',
        'file_ref' => 'assets/1/x.pdf', 'file_sha256' => str_repeat('a', 64),
        'review_status' => DocumentReviewStatus::Pending,
    ]);

    Sanctum::actingAs(agent());

    $this->getJson('/api/v1/admin/documents')
        ->assertOk()
        ->assertJsonCount(1, 'documents')
        ->assertJsonPath('documents.0.asset_id', $bien->id);
});

it('fait monter le niveau à l\'acceptation d\'une preuve de propriété', function (): void {
    $proprietaire = proprietaireKyc();
    $bien = bienDocumentable($proprietaire);
    $document = AssetDocument::create([
        'asset_id' => $bien->id, 'uploaded_by' => $proprietaire->id, 'doc_type' => 'registration_card',
        'file_ref' => 'assets/1/x.pdf', 'file_sha256' => str_repeat('a', 64),
        'review_status' => DocumentReviewStatus::Pending,
    ]);

    Sanctum::actingAs(agent());

    $this->postJson("/api/v1/admin/documents/{$document->id}/review", ['status' => 'accepted'])
        ->assertOk()
        ->assertJsonPath('trust.current', 'F2');

    expect($bien->fresh()?->trust_level)->toBe(TrustLevel::Documented);
});

it('exige un motif pour tout refus (ST-0208)', function (): void {
    // Un refus sans raison est incontestable, et le déposant ne sait pas quoi
    // corriger.
    $proprietaire = proprietaireKyc();
    $bien = bienDocumentable($proprietaire);
    $document = AssetDocument::create([
        'asset_id' => $bien->id, 'uploaded_by' => $proprietaire->id, 'doc_type' => 'invoice',
        'file_ref' => 'assets/1/x.pdf', 'file_sha256' => str_repeat('a', 64),
        'review_status' => DocumentReviewStatus::Pending,
    ]);

    Sanctum::actingAs(agent());

    $this->postJson("/api/v1/admin/documents/{$document->id}/review", ['status' => 'rejected'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect($document->fresh()?->review_status)->toBe(DocumentReviewStatus::Pending);
});

it('rend le motif de refus au déposant', function (): void {
    $proprietaire = proprietaireKyc();
    $bien = bienDocumentable($proprietaire);
    $document = AssetDocument::create([
        'asset_id' => $bien->id, 'uploaded_by' => $proprietaire->id, 'doc_type' => 'invoice',
        'file_ref' => 'assets/1/x.pdf', 'file_sha256' => str_repeat('a', 64),
        'review_status' => DocumentReviewStatus::Pending,
    ]);

    Sanctum::actingAs(agent());
    $this->postJson("/api/v1/admin/documents/{$document->id}/review", [
        'status' => 'rejected',
        'reason' => 'Facture illisible : le numéro de châssis n\'apparaît pas.',
    ])->assertOk();

    Sanctum::actingAs($proprietaire);

    $this->getJson("/api/v1/assets/{$bien->id}/trust")
        ->assertOk()
        ->assertJsonPath('documents.0.review_reason', 'Facture illisible : le numéro de châssis n\'apparaît pas.');
});

it('journalise la décision de revue avec son motif', function (): void {
    $proprietaire = proprietaireKyc();
    $bien = bienDocumentable($proprietaire);
    $document = AssetDocument::create([
        'asset_id' => $bien->id, 'uploaded_by' => $proprietaire->id, 'doc_type' => 'invoice',
        'file_ref' => 'assets/1/x.pdf', 'file_sha256' => str_repeat('a', 64),
        'review_status' => DocumentReviewStatus::Pending,
    ]);

    $agent = agent();
    Sanctum::actingAs($agent);

    $this->postJson("/api/v1/admin/documents/{$document->id}/review", [
        'status' => 'suspected_forgery',
        'reason' => 'Tampon incohérent avec le millésime.',
    ])->assertOk();

    $entree = AuditLog::where('action', 'asset_document.reviewed')->sole();

    expect($entree->actor_id)->toBe($agent->id)
        ->and($entree->payload['review_status'] ?? null)->toBe('suspected_forgery')
        ->and($entree->payload['reason'] ?? null)->toBe('Tampon incohérent avec le millésime.');
});

it('redescend le niveau et prévient le détenteur quand une pièce est invalidée', function (): void {
    $proprietaire = proprietaireKyc();
    $bien = bienDocumentable($proprietaire);
    $document = AssetDocument::create([
        'asset_id' => $bien->id, 'uploaded_by' => $proprietaire->id, 'doc_type' => 'invoice',
        'file_ref' => 'assets/1/x.pdf', 'file_sha256' => str_repeat('a', 64),
        'review_status' => DocumentReviewStatus::Pending,
    ]);

    Sanctum::actingAs(agent());
    $this->postJson("/api/v1/admin/documents/{$document->id}/review", ['status' => 'accepted'])->assertOk();
    expect($bien->fresh()?->trust_level)->toBe(TrustLevel::Documented);

    $this->postJson("/api/v1/admin/documents/{$document->id}/review", [
        'status' => 'suspected_forgery',
        'reason' => 'Pièce contestée après contrôle croisé.',
    ])->assertOk()->assertJsonPath('trust.current', 'F1');

    expect($bien->fresh()?->trust_level)->toBe(TrustLevel::Declared)
        ->and(Notification::where('user_id', $proprietaire->id)->count())->toBeGreaterThan(0);
});

it('n\'accorde Vérifié que par contrôle croisé du back-office', function (): void {
    $proprietaire = proprietaireKyc();
    $bien = bienDocumentable($proprietaire);
    $document = AssetDocument::create([
        'asset_id' => $bien->id, 'uploaded_by' => $proprietaire->id, 'doc_type' => 'registration_card',
        'file_ref' => 'assets/1/x.pdf', 'file_sha256' => str_repeat('a', 64),
        'review_status' => DocumentReviewStatus::Accepted, 'reviewed_at' => now(),
    ]);

    Sanctum::actingAs(agent());

    $this->postJson("/api/v1/admin/assets/{$bien->id}/verify", ['verified' => true])
        ->assertOk()
        ->assertJsonPath('trust.current', 'F3');

    expect($bien->fresh()?->trust_level)->toBe(TrustLevel::Verified)
        ->and($document->id)->not->toBeNull();
});

it('retire Vérifié quand le contrôle croisé est révoqué', function (): void {
    $proprietaire = proprietaireKyc();
    $bien = bienDocumentable($proprietaire);
    AssetDocument::create([
        'asset_id' => $bien->id, 'uploaded_by' => $proprietaire->id, 'doc_type' => 'acd',
        'file_ref' => 'assets/1/x.pdf', 'file_sha256' => str_repeat('a', 64),
        'review_status' => DocumentReviewStatus::Accepted, 'reviewed_at' => now(),
    ]);

    Sanctum::actingAs(agent());
    $this->postJson("/api/v1/admin/assets/{$bien->id}/verify", ['verified' => true])->assertOk();

    $this->postJson("/api/v1/admin/assets/{$bien->id}/verify", ['verified' => false])
        ->assertOk()
        ->assertJsonPath('trust.current', 'F2');

    expect($bien->fresh()?->trust_level)->toBe(TrustLevel::Documented);
});

it('ne laisse pas un agent fabriquer un Vérifié sans justificatif', function (): void {
    // Un agent confirme ce qui existe, il ne crée pas de preuve.
    $proprietaire = proprietaireKyc(verifie: false);
    $bien = bienDocumentable($proprietaire);

    Sanctum::actingAs(agent());

    $this->postJson("/api/v1/admin/assets/{$bien->id}/verify", ['verified' => true])
        ->assertOk()
        ->assertJsonPath('trust.current', 'F1');

    expect($bien->fresh()?->trust_level)->toBe(TrustLevel::Declared);
});
