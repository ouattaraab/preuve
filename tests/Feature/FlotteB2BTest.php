<?php

declare(strict_types=1);

use App\Enums\KycStatus;
use App\Enums\LifeStatus;
use App\Enums\NotificationType;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\CategoryField;
use App\Models\Company;
use App\Models\Lookup;
use App\Models\Notification;
use App\Models\User;
use App\Services\CategoryRegistry;
use App\Services\FleetService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * EP-07 (ST-0701 à ST-0703).
 *
 * L'enjeu de l'import : il doit ABOUTIR PARTIELLEMENT. Un loueur qui importe
 * 80 véhicules et voit tout échouer sur une plaque mal saisie abandonnera, et
 * son parc restera dehors.
 *
 * N'utilise pas RefreshDatabase : l'enregistrement écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('payments')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerFlotte();
    categorieVoiture();
});
afterEach(fn () => nettoyerFlotte());

function nettoyerFlotte(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'notifications', 'lookups', 'asset_status_history', 'assets',
        'companies', 'category_fields', 'asset_categories', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function categorieVoiture(): void
{
    $categorie = AssetCategory::create([
        'key' => 'voiture', 'name' => 'Voiture', 'icon' => '🚗', 'position' => 1, 'is_active' => true,
    ]);

    CategoryField::create([
        'asset_category_id' => $categorie->id, 'key' => 'plate', 'label' => 'Plaque',
        'type' => 'identifier', 'is_required' => true, 'is_canonical_identifier' => true, 'position' => 1,
    ]);

    CategoryField::create([
        'asset_category_id' => $categorie->id, 'key' => 'brand_model', 'label' => 'Marque & modèle',
        'type' => 'text', 'is_required' => false, 'is_canonical_identifier' => false, 'position' => 2,
    ]);

    app(CategoryRegistry::class)->publish();
}

function loueurConnecte(): array
{
    $loueur = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
    $loueur->forceFill(['kyc_status' => KycStatus::Verified->value])->save();

    $societe = Company::create([
        'owner_user_id' => $loueur->id,
        'legal_name' => 'Abidjan Location',
        'rccm_number' => 'CI-ABJ-'.random_int(10000, 99999),
        'business_type' => 'car_rental',
        'validation_status' => 'validated',
        'free_fleet_quota' => 3,
    ]);

    Sanctum::actingAs($loueur);

    return [$loueur, $societe];
}

function csv(string $contenu): UploadedFile
{
    return UploadedFile::fake()->createWithContent('flotte.csv', $contenu);
}

function vehiculeDeFlotte(User $loueur, Company $societe, ?LifeStatus $statut = null): Asset
{
    $plaque = strtoupper(bin2hex(random_bytes(3)));

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $loueur->id,
        'company_id' => $societe->id,
        'asset_category_key' => 'voiture',
        'identifier_type' => 'plate',
        'identifier_raw' => $plaque,
        'identifier_normalized' => $plaque,
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => $statut ?? LifeStatus::Active,
        'registered_at' => now()->subMonth(),
    ]);
}

it('importe une flotte depuis un fichier CSV', function (): void {
    [, $societe] = loueurConnecte();

    $reponse = $this->post("/api/v1/fleet/{$societe->id}/import", [
        'file' => csv("category,identifier,brand_model\nvoiture,1234AB01,Toyota Corolla\nvoiture,5678CD02,Hyundai i10\n"),
    ]);

    $reponse->assertOk()->assertJsonPath('report.imported', 2);

    expect(Asset::where('company_id', $societe->id)->count())->toBe(2);
});

it('aboutit partiellement malgré des lignes fautives', function (): void {
    // Un loueur qui voit tout échouer sur une ligne abandonnera, et son parc
    // restera dehors.
    [, $societe] = loueurConnecte();

    $reponse = $this->post("/api/v1/fleet/{$societe->id}/import", [
        'file' => csv("category,identifier,brand_model\nvoiture,1234AB01,Toyota\nvoiture,,Sans plaque\nvoiture,5678CD02,Hyundai\n"),
    ]);

    $reponse->assertOk()
        ->assertJsonPath('report.imported', 2)
        ->assertJsonPath('report.failed', 1)
        ->assertJsonPath('report.errors.0.line', 3);

    expect(Asset::count())->toBe(2);
});

it('ne recrée pas les véhicules déjà importés', function (): void {
    // Reprise après interruption : le second import doit être sans effet.
    [, $societe] = loueurConnecte();
    $fichier = "category,identifier,brand_model\nvoiture,1234AB01,Toyota\n";

    $this->post("/api/v1/fleet/{$societe->id}/import", ['file' => csv($fichier)])->assertOk();
    $this->post("/api/v1/fleet/{$societe->id}/import", ['file' => csv($fichier)])
        ->assertOk()
        ->assertJsonPath('report.imported', 0)
        ->assertJsonPath('report.skipped', 1)
        ->assertJsonPath('report.failed', 0);

    expect(Asset::count())->toBe(1);
});

it('signale un véhicule enregistré par un tiers', function (): void {
    [$loueur, $societe] = loueurConnecte();

    $autre = User::create(['phone' => '+2250799999999']);
    Asset::create([
        'public_ref' => 'PRV-AUTRE001',
        'owner_user_id' => $autre->id,
        'asset_category_key' => 'voiture',
        'identifier_type' => 'plate',
        'identifier_raw' => '1234AB01',
        'identifier_normalized' => '1234AB01',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now(),
    ]);

    Sanctum::actingAs($loueur);

    $reponse = $this->post("/api/v1/fleet/{$societe->id}/import", [
        'file' => csv("category,identifier,brand_model\nvoiture,1234AB01,Toyota\n"),
    ])->assertOk();

    expect($reponse->json('report.errors.0.reason'))->toContain('réclamation');
});

it('met un gros fichier en file plutôt que de le tronquer', function (): void {
    // Le fichier était auparavant TRONQUÉ à 200 lignes, le loueur devant
    // découper et réimporter lui-même. Un import traité d'un bloc rejetterait
    // les actions de tous les autres utilisateurs pendant sa durée — chaque
    // enregistrement prend le verrou de la chaîne d'audit. Découpé en tranches
    // par la file, il rend le verrou entre chacune.
    //
    // Les lignes sont volontairement vides d'identifiant : elles sont rejetées
    // avant tout enregistrement, ce qui éprouve le chemin sans prendre le
    // verrou deux cents fois.
    [, $societe] = loueurConnecte();

    $lignes = "category,identifier,brand_model\n";

    foreach (range(1, FleetService::MAX_ROWS + 5) as $index) {
        $lignes .= "voiture,,Toyota\n";
    }

    $reponse = $this->post("/api/v1/fleet/{$societe->id}/import", ['file' => csv($lignes)])
        // 202 : accepté, pas terminé. Le client doit suivre, pas afficher un
        // résultat qui n'existe pas encore.
        ->assertStatus(202);

    expect($reponse->json('import.total_rows'))->toBe(FleetService::MAX_ROWS + 5)
        ->and($reponse->json('message'))->toContain('arrière-plan');
});

it('marque une sélection de véhicules en location', function (): void {
    [$loueur, $societe] = loueurConnecte();
    $premier = vehiculeDeFlotte($loueur, $societe);
    $second = vehiculeDeFlotte($loueur, $societe);

    $this->postJson("/api/v1/fleet/{$societe->id}/rented", [
        'asset_ids' => [$premier->id, $second->id],
        'rented' => true,
    ])->assertOk()->assertJsonPath('report.updated', 2);

    expect($premier->fresh()?->life_status)->toBe(LifeStatus::Rented)
        ->and($second->fresh()?->life_status)->toBe(LifeStatus::Rented);
});

it('démarque au retour de location', function (): void {
    [$loueur, $societe] = loueurConnecte();
    $vehicule = vehiculeDeFlotte($loueur, $societe, LifeStatus::Rented);

    $this->postJson("/api/v1/fleet/{$societe->id}/rented", [
        'asset_ids' => [$vehicule->id],
        'rented' => false,
    ])->assertOk()->assertJsonPath('report.updated', 1);

    expect($vehicule->fresh()?->life_status)->toBe(LifeStatus::Active);
});

it('ignore les véhicules déjà dans l\'état voulu', function (): void {
    // Une sélection large ne doit pas échouer parce qu'elle englobe des
    // véhicules déjà marqués.
    [$loueur, $societe] = loueurConnecte();
    $deja = vehiculeDeFlotte($loueur, $societe, LifeStatus::Rented);
    $autre = vehiculeDeFlotte($loueur, $societe);

    $this->postJson("/api/v1/fleet/{$societe->id}/rented", [
        'asset_ids' => [$deja->id, $autre->id],
        'rented' => true,
    ])->assertOk()->assertJsonPath('report.updated', 1)->assertJsonPath('report.failed', 0);
});

it('ne touche pas aux véhicules d\'une autre flotte', function (): void {
    [$loueur, $societe] = loueurConnecte();
    $autreLoueur = User::create(['phone' => '+2250788888888']);
    $autreSociete = Company::create([
        'owner_user_id' => $autreLoueur->id,
        'legal_name' => 'Concurrent',
        'rccm_number' => 'CI-ABJ-77777',
        'business_type' => 'car_rental',
        'validation_status' => 'validated',
    ]);
    $vehiculeTiers = vehiculeDeFlotte($autreLoueur, $autreSociete);

    Sanctum::actingAs($loueur);

    $this->postJson("/api/v1/fleet/{$societe->id}/rented", [
        'asset_ids' => [$vehiculeTiers->id],
        'rented' => true,
    ])->assertOk()->assertJsonPath('report.updated', 0);

    expect($vehiculeTiers->fresh()?->life_status)->toBe(LifeStatus::Active);
});

it('refuse l\'accès à la flotte d\'un tiers', function (): void {
    [, $societe] = loueurConnecte();

    Sanctum::actingAs(User::create(['phone' => '+2250766666666']));

    $this->getJson("/api/v1/fleet/{$societe->id}/dashboard")->assertStatus(404);
});

it('sert un tableau de bord avec les véhicules à surveiller', function (): void {
    [$loueur, $societe] = loueurConnecte();
    vehiculeDeFlotte($loueur, $societe);
    $vole = vehiculeDeFlotte($loueur, $societe, LifeStatus::Stolen);

    $reponse = $this->getJson("/api/v1/fleet/{$societe->id}/dashboard")->assertOk();

    expect($reponse->json('dashboard.fleet_size'))->toBe(2)
        ->and($reponse->json('dashboard.needs_attention.0.asset_id'))->toBe($vole->id)
        ->and($reponse->json('billing.fleet_size'))->toBe(2);
});

it('agrège les consultations sans dire qui consulte', function (): void {
    // Un loueur n'apprend pas plus qu'un particulier sur qui regarde ses biens.
    [$loueur, $societe] = loueurConnecte();
    $vehicule = vehiculeDeFlotte($loueur, $societe);
    // Identifiant improbable : avec un petit entier, l'assertion d'absence
    // passerait sur n'importe quel compteur du tableau de bord.
    DB::table('users')->insert([
        'id' => 987654, 'phone' => '+2250755555555', 'full_name' => 'Yao N.',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $curieux = User::findOrFail(987654);

    foreach (range(1, 3) as $visite) {
        Lookup::create([
            'identifier_normalized' => $vehicule->identifier_normalized,
            'found_asset_id' => $vehicule->id,
            'ip_hash' => str_repeat('e', 64),
            'user_id' => $curieux->id,
            'source' => 'app',
            'created_at' => now()->subHours($visite)->format('Y-m-d H:i:s'),
        ]);
    }

    $reponse = $this->getJson("/api/v1/fleet/{$societe->id}/dashboard")->assertOk();

    expect($reponse->json('dashboard.lookups_30d.total'))->toBe(3)
        ->and($reponse->content())->not->toContain('Yao N.')
        ->and($reponse->content())->not->toContain((string) $curieux->id);
});

it('remonte les alertes récentes sur la flotte', function (): void {
    // ST-0704 : le loueur doit agir avant la vente frauduleuse.
    [$loueur, $societe] = loueurConnecte();
    $vehicule = vehiculeDeFlotte($loueur, $societe);

    Notification::create([
        'user_id' => $loueur->id,
        'type' => NotificationType::DuplicateAttempt,
        'asset_id' => $vehicule->id,
        'title' => 'Tentative d\'enregistrement',
        'body' => 'Quelqu\'un a tenté d\'enregistrer ce véhicule.',
        'channel' => 'sms',
        'created_at' => now()->format('Y-m-d H:i:s'),
    ]);

    $reponse = $this->getJson("/api/v1/fleet/{$societe->id}/dashboard")->assertOk();

    expect($reponse->json('dashboard.recent_alerts.0.type'))->toBe('duplicate_attempt')
        ->and($reponse->json('dashboard.recent_alerts.0.asset_id'))->toBe($vehicule->id);
});
