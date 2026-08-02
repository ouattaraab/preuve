<?php

declare(strict_types=1);

use App\Enums\KycStatus;
use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AuditLog;
use App\Models\KycSubmission;
use App\Models\Notification;
use App\Models\User;
use App\Services\Kyc\IdentityExtraction;
use App\Services\Kyc\IdentityReader;
use App\Services\KycService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * ST-0103 et règle métier absolue n° 8 : le numéro de pièce n'est stocké que
 * haché, jamais en clair — ni en base, ni dans la chaîne d'audit, ni dans les
 * journaux.
 *
 * N'utilise pas RefreshDatabase : la soumission écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('kyc_submissions')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerKyc();
    Storage::fake('s3');

    $this->lecteur = new LecteurIdentiteFactice;
    $this->app->instance(IdentityReader::class, $this->lecteur);
    $this->kyc = app(KycService::class);
});
afterEach(fn () => nettoyerKyc());

function nettoyerKyc(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'notifications', 'kyc_submissions', 'asset_documents',
        'asset_status_history', 'assets', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function compte(): User
{
    return User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
}

function agentKyc(): User
{
    $utilisateur = User::create(['phone' => '+2250799'.random_int(100000, 999999)]);
    $utilisateur->forceFill(['role' => UserRole::Agent])->save();

    return $utilisateur;
}

function piece(): UploadedFile
{
    return UploadedFile::fake()->image('cni.jpg', 800, 500);
}

function deposerKyc(User $utilisateur): KycSubmission
{
    return test()->kyc->submit($utilisateur, piece(), piece(), piece());
}

it('place le compte en vérification sans rien lui accorder', function (): void {
    $utilisateur = compte();

    $dossier = deposerKyc($utilisateur);

    expect($dossier->status)->toBe('pending')
        ->and($utilisateur->fresh()?->kycStatus())->toBe(KycStatus::Pending)
        ->and($utilisateur->fresh()?->kyc_verified_at)->toBeNull();
});

it('ne stocke jamais le numéro de pièce en clair', function (): void {
    // Règle métier absolue n° 8. Le numéro circule dans l'extraction, il ne
    // doit se retrouver nulle part ensuite.
    $utilisateur = compte();

    deposerKyc($utilisateur);

    $enBase = DB::table('users')->where('id', $utilisateur->id)->first();
    $dossier = DB::table('kyc_submissions')->sole();

    expect((string) ($enBase->kyc_id_number_hash ?? ''))->not->toContain('CI00123456')
        ->and((string) ($enBase->kyc_id_number_hash ?? ''))->toHaveLength(64)
        ->and(json_encode($dossier))->not->toContain('CI00123456')
        // Un SHA-256 nu se renverserait par balayage : un numéro de CNI a une
        // forme connue et un espace de valeurs réduit.
        ->and((string) ($enBase->kyc_id_number_hash ?? ''))->not->toBe(hash('sha256', 'CI00123456'));
});

it('n\'écrit ni le numéro ni l\'identité dans la chaîne d\'audit', function (): void {
    // audit_log est inaltérable : ce qui y entre survivrait à tout exercice du
    // droit à l'effacement (Loi 2013-450).
    deposerKyc(compte());

    $entree = AuditLog::where('action', 'kyc.submitted')->sole();

    expect(json_encode($entree->payload))->not->toContain('CI00123456')
        ->and(json_encode($entree->payload))->not->toContain('Awa')
        ->and($entree->payload['has_document_number'] ?? null)->toBeTrue();
});

it('conserve une extraction minimisée, sans le numéro', function (): void {
    deposerKyc(compte());

    $dossier = KycSubmission::sole();

    expect($dossier->ocr_payload)->toHaveKey('full_name')
        ->and($dossier->ocr_payload)->not->toHaveKey('document_number');
});

it('refuse une pièce déjà rattachée à un autre compte', function (): void {
    // Protection la plus directe contre l'usurpation : une même CNI ne peut
    // pas fonder deux identités.
    $premier = compte();
    deposerKyc($premier);
    $this->kyc->review(KycSubmission::sole(), agentKyc(), accepte: true);

    expect(fn () => deposerKyc(compte()))->toThrow(DomainException::class);
});

it('refuse une seconde soumission tant que la première est en cours', function (): void {
    // Deux dossiers concurrents feraient trancher un agent sur une pièce que
    // l'autre a déjà écartée.
    $utilisateur = compte();
    deposerKyc($utilisateur);

    expect(fn () => deposerKyc($utilisateur->fresh() ?? $utilisateur))->toThrow(DomainException::class);
});

it('accepte une nouvelle soumission après un refus', function (): void {
    $utilisateur = compte();
    deposerKyc($utilisateur);
    $this->kyc->review(KycSubmission::sole(), agentKyc(), accepte: false, motif: 'Pièce illisible.');

    $second = deposerKyc($utilisateur->fresh() ?? $utilisateur);

    expect($second->id)->not->toBeNull()
        ->and(KycSubmission::count())->toBe(2);
});

it('ne vérifie une identité que sur décision d\'un agent', function (): void {
    // Une identité validée sur la seule foi d'un OCR ouvrirait la porte à des
    // pièces fabriquées que personne n'aurait regardées.
    $utilisateur = compte();
    deposerKyc($utilisateur);

    expect($utilisateur->fresh()?->hasVerifiedIdentity())->toBeFalse();

    $this->kyc->review(KycSubmission::sole(), agentKyc(), accepte: true);

    expect($utilisateur->fresh()?->hasVerifiedIdentity())->toBeTrue()
        ->and($utilisateur->fresh()?->kyc_verified_at)->not->toBeNull();
});

it('exige un motif pour tout refus', function (): void {
    $utilisateur = compte();
    deposerKyc($utilisateur);

    expect(fn () => $this->kyc->review(KycSubmission::sole(), agentKyc(), accepte: false))
        ->toThrow(DomainException::class);

    expect(KycSubmission::sole()->status)->toBe('pending');
});

it('détache l\'empreinte au refus pour laisser resoumettre', function (): void {
    // La garder empêcherait la personne légitime de redéposer la même pièce
    // corrigée, et bloquerait tout autre compte s'appuyant sur elle.
    $utilisateur = compte();
    deposerKyc($utilisateur);
    $this->kyc->review(KycSubmission::sole(), agentKyc(), accepte: false, motif: 'Photo floue.');

    expect($utilisateur->fresh()?->kyc_id_number_hash)->toBeNull()
        ->and($utilisateur->fresh()?->kycStatus())->toBe(KycStatus::Rejected);
});

it('fait monter les biens déjà documentés dès l\'identité vérifiée', function (): void {
    // Le niveau F2 dépend du KYC autant que du justificatif : un bien déjà
    // appuyé par une pièce acceptée doit monter sans nouvelle démarche.
    $utilisateur = compte();

    $bien = Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $utilisateur->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonth(),
    ]);

    AssetDocument::create([
        'asset_id' => $bien->id, 'uploaded_by' => $utilisateur->id, 'doc_type' => 'registration_card',
        'file_ref' => 'x.pdf', 'file_sha256' => str_repeat('a', 64),
        'review_status' => 'accepted', 'reviewed_at' => now(),
    ]);

    deposerKyc($utilisateur);
    $this->kyc->review(KycSubmission::sole(), agentKyc(), accepte: true);

    expect($bien->fresh()?->trust_level)->toBe(TrustLevel::Documented);
});

it('prévient l\'utilisateur de l\'issue', function (): void {
    $utilisateur = compte();
    deposerKyc($utilisateur);
    $this->kyc->review(KycSubmission::sole(), agentKyc(), accepte: false, motif: 'Selfie non concordant.');

    $notification = Notification::where('user_id', $utilisateur->id)->sole();

    expect($notification->body)->toContain('Selfie non concordant.');
});

it('continue de fonctionner quand l\'extraction échoue', function (): void {
    // Une panne du fournisseur ne doit pas suspendre les vérifications
    // d'identité de toute la plateforme : l'agent tranche sur l'image seule.
    $this->lecteur->echoue = true;
    $utilisateur = compte();

    $dossier = deposerKyc($utilisateur);

    expect($dossier->status)->toBe('pending')
        ->and($dossier->ocr_payload['extraction_failed'] ?? null)->toBeTrue()
        ->and($utilisateur->fresh()?->kyc_id_number_hash)->toBeNull();
});

it('range les pièces sur le stockage chiffré, jamais en base', function (): void {
    $utilisateur = compte();
    $dossier = deposerKyc($utilisateur);

    Storage::disk('s3')->assertExists($dossier->id_front_ref);
    Storage::disk('s3')->assertExists($dossier->selfie_ref);

    expect($dossier->id_front_sha256)->toHaveLength(64);
});

it('ne tranche pas deux fois le même dossier', function (): void {
    $utilisateur = compte();
    deposerKyc($utilisateur);
    $dossier = KycSubmission::sole();

    $this->kyc->review($dossier, agentKyc(), accepte: true);

    expect(fn () => $this->kyc->review($dossier->fresh() ?? $dossier, agentKyc(), accepte: false, motif: 'X'))
        ->toThrow(DomainException::class);
});

/** Lecteur d'identité factice : rend des champs stables, ou échoue à la demande. */
class LecteurIdentiteFactice implements IdentityReader
{
    public bool $echoue = false;

    public function read(UploadedFile $recto, UploadedFile $verso): IdentityExtraction
    {
        if ($this->echoue) {
            return IdentityExtraction::unreadable();
        }

        return new IdentityExtraction(
            documentNumber: 'CI00123456',
            fullName: 'Awa Koné',
            birthDate: '1990-04-12',
            expiryDate: '2030-04-11',
            confidence: 92,
        );
    }
}
