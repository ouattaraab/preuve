<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AuditLog;
use App\Models\CategoryField;
use App\Models\User;
use App\Services\CategoryRegistry;
use App\Services\PlatformState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * ST-0904 : dégradation gracieuse.
 *
 * L'objectif de 99,5 % ne se tient pas en ne tombant jamais, mais en
 * choisissant CE QUI tombe. La consultation ne tombe pas : un acheteur au
 * marché doit pouvoir vérifier un bien même pendant une maintenance — sans
 * quoi, ce jour-là, un vol passerait inaperçu.
 *
 * N'utilise pas RefreshDatabase : la bascule écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('app_settings')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerMaintenance();
    categorieMaintenance();
    $this->etat = app(PlatformState::class);
});
afterEach(fn () => nettoyerMaintenance());

function nettoyerMaintenance(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'app_settings', 'lookups', 'notifications', 'asset_status_history',
        'assets', 'category_fields', 'asset_categories', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function categorieMaintenance(): void
{
    $categorie = AssetCategory::create([
        'key' => 'moto', 'name' => 'Moto', 'icon' => '🛵', 'position' => 1, 'is_active' => true,
    ]);

    CategoryField::create([
        'asset_category_id' => $categorie->id, 'key' => 'chassis', 'label' => 'Châssis',
        'type' => 'identifier', 'is_required' => true, 'is_canonical_identifier' => true, 'position' => 1,
    ]);

    app(CategoryRegistry::class)->publish();
}

function bienExistant(): Asset
{
    $proprietaire = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Stolen,
        'registered_at' => now()->subMonth(),
    ]);
}

it('laisse la consultation ouverte pendant la maintenance', function (): void {
    // LE test de la dégradation gracieuse : fermer la consultation pour migrer
    // un schéma reviendrait à ce que, ce jour-là, un vol passe inaperçu.
    $bien = bienExistant();
    $this->etat->setReadOnly(true, null, 'Migration de schéma');

    $this->getJson('/api/v1/lookup/'.$bien->identifier_normalized)
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('asset.life_status.label', 'Volé déclaré');
});

it('refuse les écritures avec un code de réessai', function (): void {
    // 503 et non 403 : ce n'est pas un refus de droit mais une
    // indisponibilité temporaire, qu'un client mobile doit distinguer pour
    // réessayer plutôt qu'abandonner.
    Sanctum::actingAs(User::create(['phone' => '+2250700000001']));
    $this->etat->setReadOnly(true, null, 'Migration de schéma');

    $this->postJson('/api/v1/assets', [
        'category' => 'moto',
        'attributes' => ['chassis' => '1M8GDM9AXKP042788'],
    ])->assertStatus(503);

    expect(Asset::count())->toBe(0);
});

it('dit ce qui reste possible', function (): void {
    // Un utilisateur qui ne peut pas enregistrer doit savoir qu'il peut encore
    // consulter, et que son bien reste protégé.
    $this->etat->setReadOnly(true, null, 'Migration de schéma');

    expect($this->etat->readOnlyMessage())
        ->toContain('Migration de schéma')
        ->toContain('consultation des biens reste ouverte')
        ->toContain('restent protégés');
});

it('laisse les administrateurs écrire pour sortir du mode', function (): void {
    // S'en interdire l'accès ferait de la maintenance un piège sans issue.
    $admin = User::create(['phone' => '+2250700000009']);
    $admin->forceFill(['role' => UserRole::Admin])->save();
    Sanctum::actingAs($admin);

    $this->etat->setReadOnly(true, $admin->id, 'Migration');

    $this->putJson('/api/v1/admin/platform-state', ['read_only' => false])
        ->assertOk()
        ->assertJsonPath('read_only', false);

    expect($this->etat->isReadOnly())->toBeFalse();
});

it('rétablit les écritures à la sortie', function (): void {
    Sanctum::actingAs(User::create(['phone' => '+2250700000002']));

    $this->etat->setReadOnly(true, null, 'Migration');
    $this->etat->setReadOnly(false, null);

    $this->postJson('/api/v1/assets', [
        'category' => 'moto',
        'attributes' => ['chassis' => '1M8GDM9AXKP042788'],
    ])->assertStatus(201);
});

it('journalise chaque bascule', function (): void {
    // Couper les écritures de toute la plateforme est une action majeure :
    // savoir qui l'a décidée et quand fait partie de ce qu'un incident doit
    // pouvoir établir.
    $admin = User::create(['phone' => '+2250700000009']);

    $this->etat->setReadOnly(true, $admin->id, 'Migration de schéma');
    $this->etat->setReadOnly(false, $admin->id);

    $entrees = AuditLog::whereIn('action', [
        'platform.read_only_enabled',
        'platform.read_only_disabled',
    ])->orderBy('id')->get();

    expect($entrees)->toHaveCount(2)
        ->and($entrees[0]->actor_id)->toBe($admin->id)
        ->and($entrees[0]->payload['reason'] ?? null)->toBe('Migration de schéma');
});

it('n\'est pas actif par défaut', function (): void {
    expect($this->etat->isReadOnly())->toBeFalse()
        ->and($this->etat->reason())->toBeNull();
});
