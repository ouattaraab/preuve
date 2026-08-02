<?php

declare(strict_types=1);

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Models\KycSubmission;
use App\Models\User;
use App\Services\Kyc\IdentityExtraction;
use App\Services\Kyc\IdentityReader;
use App\Services\Kyc\MindeeIdentityReader;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/** ST-0103 : parcours utilisateur et file de revue. */
beforeEach(function (): void {
    if (! Schema::hasTable('kyc_submissions')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerKycApi();
    Storage::fake('s3');

    $this->app->instance(IdentityReader::class, new LecteurApiFactice);
});
afterEach(fn () => nettoyerKycApi());

function nettoyerKycApi(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'notifications', 'kyc_submissions', 'app_settings',
        'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function connecteKyc(): User
{
    $utilisateur = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
    Sanctum::actingAs($utilisateur);

    return $utilisateur;
}

function agentConnecte(): User
{
    $utilisateur = User::create(['phone' => '+2250799'.random_int(100000, 999999)]);
    $utilisateur->forceFill(['role' => UserRole::Agent])->save();
    Sanctum::actingAs($utilisateur);

    return $utilisateur;
}

function corpsKyc(): array
{
    return [
        'id_front' => UploadedFile::fake()->image('recto.jpg'),
        'id_back' => UploadedFile::fake()->image('verso.jpg'),
        'selfie' => UploadedFile::fake()->image('selfie.jpg'),
    ];
}

it('exige une authentification', function (): void {
    $this->getJson('/api/v1/kyc')->assertStatus(401);
    $this->postJson('/api/v1/kyc', corpsKyc())->assertStatus(401);
});

it('annonce l\'état et ce que la vérification débloque', function (): void {
    connecteKyc();

    $this->getJson('/api/v1/kyc')
        ->assertOk()
        ->assertJsonPath('status', 'none')
        ->assertJsonPath('can_submit', true)
        ->assertJsonStructure(['unlocks']);
});

it('accepte un dossier complet', function (): void {
    $utilisateur = connecteKyc();

    $this->postJson('/api/v1/kyc', corpsKyc())
        ->assertStatus(201)
        ->assertJsonPath('status', 'pending');

    expect(KycSubmission::count())->toBe(1)
        ->and($utilisateur->fresh()?->kycStatus())->toBe(KycStatus::Pending);
});

it('refuse un dossier incomplet', function (): void {
    connecteKyc();

    $this->postJson('/api/v1/kyc', ['id_front' => UploadedFile::fake()->image('recto.jpg')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['id_back', 'selfie']);
});

it('refuse un selfie qui serait un document', function (): void {
    // Accepter un PDF permettrait de soumettre une photo de photo au lieu
    // d'une prise de vue.
    connecteKyc();

    $this->postJson('/api/v1/kyc', [
        ...corpsKyc(),
        'selfie' => UploadedFile::fake()->create('sel.pdf', 50, 'application/pdf'),
    ])->assertStatus(422)->assertJsonValidationErrors('selfie');
});

it('refuse une seconde soumission tant que la première est en cours', function (): void {
    connecteKyc();
    $this->postJson('/api/v1/kyc', corpsKyc())->assertStatus(201);

    $this->postJson('/api/v1/kyc', corpsKyc())->assertStatus(422);

    expect(KycSubmission::count())->toBe(1);
});

it('réserve la file de vérification au back-office', function (): void {
    connecteKyc();

    $this->getJson('/api/v1/admin/kyc')->assertStatus(403);
});

it('sert la file des dossiers en attente à un agent', function (): void {
    $utilisateur = connecteKyc();
    $this->postJson('/api/v1/kyc', corpsKyc())->assertStatus(201);

    agentConnecte();

    $this->getJson('/api/v1/admin/kyc')
        ->assertOk()
        ->assertJsonCount(1, 'submissions')
        ->assertJsonPath('submissions.0.user_id', $utilisateur->id);
});

it('ne montre jamais le numéro de pièce à l\'agent', function (): void {
    // Il n'en a pas besoin pour apprécier la concordance, et le lui montrer le
    // ferait exister ailleurs que dans son empreinte (règle n° 8).
    connecteKyc();
    $this->postJson('/api/v1/kyc', corpsKyc())->assertStatus(201);

    agentConnecte();

    $reponse = $this->getJson('/api/v1/admin/kyc')->assertOk();

    expect($reponse->content())->not->toContain('CI00987654')
        ->and($reponse->json('submissions.0.extraction'))->toHaveKey('full_name');
});

it('vérifie l\'identité sur décision de l\'agent', function (): void {
    $utilisateur = connecteKyc();
    $this->postJson('/api/v1/kyc', corpsKyc())->assertStatus(201);
    $dossier = KycSubmission::sole();

    agentConnecte();

    $this->postJson("/api/v1/admin/kyc/{$dossier->id}/review", ['verified' => true])
        ->assertOk()
        ->assertJsonPath('submission.status', 'verified');

    expect($utilisateur->fresh()?->hasVerifiedIdentity())->toBeTrue();
});

it('exige un motif au refus', function (): void {
    connecteKyc();
    $this->postJson('/api/v1/kyc', corpsKyc())->assertStatus(201);
    $dossier = KycSubmission::sole();

    agentConnecte();

    $this->postJson("/api/v1/admin/kyc/{$dossier->id}/review", ['verified' => false])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

it('rend le motif de refus au déposant', function (): void {
    $utilisateur = connecteKyc();
    $this->postJson('/api/v1/kyc', corpsKyc())->assertStatus(201);
    $dossier = KycSubmission::sole();

    agentConnecte();
    $this->postJson("/api/v1/admin/kyc/{$dossier->id}/review", [
        'verified' => false,
        'reason' => 'Le selfie ne correspond pas à la photo de la pièce.',
    ])->assertOk();

    // `fresh()` : actingAs réinjecte l'instance telle qu'elle était en
    // mémoire, alors qu'une vraie requête relit le compte depuis son jeton.
    Sanctum::actingAs($utilisateur->fresh() ?? $utilisateur);

    $this->getJson('/api/v1/kyc')
        ->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('can_submit', true)
        ->assertJsonPath('last_submission.review_reason', 'Le selfie ne correspond pas à la photo de la pièce.');
});

it('réserve la configuration du fournisseur aux administrateurs', function (): void {
    agentConnecte();

    $this->getJson('/api/v1/admin/kyc-provider')->assertStatus(403);
});

it('configure la clé Mindee sans jamais la renvoyer', function (): void {
    $admin = User::create(['phone' => '+2250788000001']);
    $admin->forceFill(['role' => UserRole::Admin])->save();
    Sanctum::actingAs($admin);

    $this->putJson('/api/v1/admin/kyc-provider', ['api_key' => 'cle-mindee-secrete'])->assertOk();

    $reponse = $this->getJson('/api/v1/admin/kyc-provider')->assertOk();

    expect($reponse->content())->not->toContain('cle-mindee-secrete')
        ->and($reponse->json('configured'))->toBeTrue()
        ->and($reponse->json('api_key'))->toBe('••••••••')
        ->and(app(SettingsRepository::class)->fresh()->get(MindeeIdentityReader::API_KEY_SETTING))
        ->toBe('cle-mindee-secrete');

    $brut = DB::table('app_settings')->pluck('value')->implode('|');
    expect($brut)->not->toContain('cle-mindee-secrete');
});

/** Lecteur factice pour les tests d'API. */
class LecteurApiFactice implements IdentityReader
{
    public function read(UploadedFile $recto, UploadedFile $verso): IdentityExtraction
    {
        return new IdentityExtraction(
            documentNumber: 'CI00987654',
            fullName: 'Awa Koné',
            birthDate: '1990-04-12',
            confidence: 88,
        );
    }
}
