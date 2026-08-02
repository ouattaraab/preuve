<?php

declare(strict_types=1);

use App\Enums\ActorType;
use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Lookup;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\TelemetryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * EP-09 (ST-0901 à ST-0903).
 *
 * CT-01 et CT-02 sont les deux promesses que le produit fait à ses
 * utilisateurs. Non mesurées, elles ne sont que des intentions — et leur
 * dérive passerait inaperçue jusqu'au jour où un loueur pilote renoncerait
 * sans dire pourquoi.
 *
 * N'utilise pas RefreshDatabase : la télémétrie lit la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('report_purchases')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerObservabilite();
});
afterEach(fn () => nettoyerObservabilite());

function nettoyerObservabilite(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'lookups', 'report_purchases', 'payments', 'notifications',
        'asset_status_history', 'assets', 'companies', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function adminObs(): User
{
    $utilisateur = User::create(['phone' => '+2250799'.random_int(100000, 999999)]);
    $utilisateur->forceFill(['role' => UserRole::Admin])->save();
    Sanctum::actingAs($utilisateur);

    return $utilisateur;
}

function consultationMesuree(int $dureeMs): void
{
    Lookup::create([
        'identifier_normalized' => 'MESURE'.random_int(1000, 9999),
        'found_asset_id' => null,
        'ip_hash' => str_repeat('f', 64),
        'source' => 'app',
        'duration_ms' => $dureeMs,
        'created_at' => now()->subMinutes(random_int(1, 60))->format('Y-m-d H:i:s'),
    ]);
}

it('mesure la latence de consultation au 95e centile', function (): void {
    // Une moyenne de 400 ms peut cacher un utilisateur sur vingt qui attend
    // quatre secondes — et c'est celui-là qui abandonne.
    foreach (range(1, 19) as $rapide) {
        consultationMesuree(100);
    }
    consultationMesuree(4000);

    $mesure = app(TelemetryService::class)->lookupLatency(now()->subDay());

    expect($mesure['samples'])->toBe(20)
        ->and($mesure['p50_ms'])->toBe(100)
        ->and($mesure['p95_ms'])->toBe(100)
        ->and($mesure['meets_target'])->toBeTrue();
});

it('signale le dépassement du seuil CT-01', function (): void {
    foreach (range(1, 10) as $lent) {
        consultationMesuree(1500);
    }

    $mesure = app(TelemetryService::class)->lookupLatency(now()->subDay());

    expect($mesure['p95_ms'])->toBe(1500)
        ->and($mesure['meets_target'])->toBeFalse();
});

it('ne prétend pas tenir un objectif sans mesure', function (): void {
    // Dire « objectif tenu » sans échantillon serait pire que de ne rien dire.
    $mesure = app(TelemetryService::class)->lookupLatency(now()->subDay());

    expect($mesure['samples'])->toBe(0)
        ->and($mesure['p95_ms'])->toBeNull()
        ->and($mesure['meets_target'])->toBeNull();
});

it('mesure la durée d\'enregistrement depuis le chronomètre client', function (): void {
    $chaine = app(AuditChain::class);

    foreach ([30_000, 45_000, 60_000] as $duree) {
        $chaine->append(ActorType::User, 1, 'asset.registered', 'asset', 1, [
            'client_elapsed_ms' => $duree,
        ]);
    }

    $mesure = app(TelemetryService::class)->registrationDuration(now()->subDay());

    expect($mesure['samples'])->toBe(3)
        ->and($mesure['median_ms'])->toBe(45_000)
        ->and($mesure['meets_target'])->toBeTrue();
});

it('signale le dépassement du seuil CT-02', function (): void {
    $chaine = app(AuditChain::class);

    foreach ([120_000, 150_000] as $duree) {
        $chaine->append(ActorType::User, 1, 'asset.registered', 'asset', 1, [
            'client_elapsed_ms' => $duree,
        ]);
    }

    expect(app(TelemetryService::class)->registrationDuration(now()->subDay())['meets_target'])->toBeFalse();
});

it('ignore les enregistrements sans chronomètre', function (): void {
    app(AuditChain::class)->append(ActorType::User, 1, 'asset.registered', 'asset', 1, ['category' => 'moto']);

    expect(app(TelemetryService::class)->registrationDuration(now()->subDay())['samples'])->toBe(0);
});

it('rapporte le taux de conversion en rapports', function (): void {
    foreach (range(1, 10) as $consultation) {
        consultationMesuree(100);
    }

    $mesure = app(TelemetryService::class)->reportConversion(now()->subDay());

    expect($mesure['lookups'])->toBe(10)
        ->and($mesure['reports_purchased'])->toBe(0)
        ->and($mesure['rate'])->toBe(0.0);
});

it('réserve la télémétrie au back-office', function (): void {
    Sanctum::actingAs(User::create(['phone' => '+2250700000001']));

    $this->getJson('/api/v1/admin/telemetry')->assertStatus(403);
});

it('sert le tableau de télémétrie à un administrateur', function (): void {
    adminObs();
    consultationMesuree(250);

    $this->getJson('/api/v1/admin/telemetry')
        ->assertOk()
        ->assertJsonPath('ct01_lookup.p95_ms', 250)
        ->assertJsonStructure(['window', 'ct01_lookup', 'ct02_registration', 'report_conversion']);
});

it('remonte les comptes qui répètent les tentatives de doublon', function (): void {
    // La répétition est le seul critère : elle distingue une erreur d'un
    // procédé. Un acheteur de bonne foi ne doit pas y figurer.
    adminObs();
    $chaine = app(AuditChain::class);

    $recidiviste = User::create(['phone' => '+2250711111111']);
    $occasionnel = User::create(['phone' => '+2250722222222']);

    foreach (range(1, 3) as $tentative) {
        $chaine->append(ActorType::User, $recidiviste->id, 'asset.duplicate_attempt', 'asset', 1, []);
    }

    $chaine->append(ActorType::User, $occasionnel->id, 'asset.duplicate_attempt', 'asset', 2, []);

    $reponse = $this->getJson('/api/v1/admin/fraud-signals')->assertOk();

    expect($reponse->json('duplicate_attempts.total'))->toBe(4)
        ->and($reponse->json('suspicious_accounts'))->toHaveCount(1)
        ->and($reponse->json('suspicious_accounts.0.user_id'))->toBe($recidiviste->id);
});

it('compte les biens gelés en attente de décision', function (): void {
    $admin = adminObs();

    Asset::create([
        'public_ref' => 'PRV-LITIGE01',
        'owner_user_id' => $admin->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => 'LITIGE123456',
        'identifier_normalized' => 'LITIGE123456',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Disputed,
        'registered_at' => now(),
    ]);

    expect($this->getJson('/api/v1/admin/fraud-signals')->assertOk()->json('disputed_assets'))->toBe(1);
});

it('valide un compte entreprise et le trace', function (): void {
    // ST-0901 : seul contrôle humain entre un RCCM saisi à la main et un tarif
    // professionnel.
    $admin = adminObs();

    $societe = Company::create([
        'owner_user_id' => $admin->id,
        'legal_name' => 'Abidjan Location',
        'rccm_number' => 'CI-ABJ-2024-B-55555',
        'business_type' => 'car_rental',
        'validation_status' => 'pending',
    ]);

    $this->getJson('/api/v1/admin/companies')->assertOk()->assertJsonCount(1, 'companies');

    $this->postJson("/api/v1/admin/companies/{$societe->id}/validate", ['status' => 'validated'])
        ->assertOk()
        ->assertJsonPath('company.validation_status', 'validated');

    expect($societe->fresh()?->validated_at)->not->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'company.validated')->count())->toBe(1);
});

it('exige un motif au refus de validation', function (): void {
    // Un refus sans raison laisse le loueur sans rien à corriger, et sans
    // recours.
    $admin = adminObs();

    $societe = Company::create([
        'owner_user_id' => $admin->id,
        'legal_name' => 'Douteux SARL',
        'rccm_number' => 'CI-ABJ-2024-B-66666',
        'business_type' => 'car_rental',
        'validation_status' => 'pending',
    ]);

    $this->postJson("/api/v1/admin/companies/{$societe->id}/validate", ['status' => 'rejected'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect($societe->fresh()?->validation_status)->toBe('pending');
});

it('expose une sonde de supervision sans authentification', function (): void {
    // Une sonde qui exige un jeton ne peut pas être interrogée le jour où
    // l'authentification est en panne — précisément quand on en a besoin.
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database', 'ok');
});

it('signale que la chaîne d\'audit n\'est pas opposable', function (): void {
    // Défaut SILENCIEUX : tout fonctionne, les utilisateurs ne voient rien, et
    // la chaîne cesse d'être opposable sans que personne ne s'en aperçoive.
    $reponse = $this->getJson('/api/v1/health')->assertOk();

    expect($reponse->json('audit_chain_opposable'))->toBeFalse()
        ->and($reponse->json('checks.audit_anchor'))->toBe('stale');
});

it('ne divulgue rien d\'exploitable dans la sonde', function (): void {
    $contenu = $this->getJson('/api/v1/health')->assertOk()->content();

    expect($contenu)->not->toContain('version')
        ->and($contenu)->not->toContain('mariadb')
        ->and($contenu)->not->toContain('preuve.');
});
