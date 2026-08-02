<?php

declare(strict_types=1);

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\CategoryField;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * ST-0201 : POST /api/v1/assets. Écriture, donc authentification obligatoire
 * (règle métier absolue n° 2).
 */
beforeEach(function (): void {
    if (! Schema::hasTable('asset_status_history')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerEndpointBien();
    categorieMotoEndpoint();
});
afterEach(fn () => nettoyerEndpointBien());

function nettoyerEndpointBien(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        // `notifications` en premier : une tentative de doublon alerte
        // désormais le détenteur (ST-0205), et ces lignes survivraient au test
        // puisque ce fichier ne s'appuie pas sur RefreshDatabase.
        'audit_log', 'notifications', 'asset_status_history', 'assets', 'personal_access_tokens',
        'users', 'category_fields', 'asset_categories',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function categorieMotoEndpoint(): void
{
    $categorie = AssetCategory::create([
        'key' => 'moto', 'name' => 'Moto', 'icon' => '🛵', 'position' => 1, 'is_active' => true,
    ]);

    CategoryField::create([
        'asset_category_id' => $categorie->id, 'key' => 'chassis', 'label' => 'N° de châssis',
        'type' => 'identifier', 'is_required' => true, 'is_canonical_identifier' => true, 'position' => 1,
    ]);

    CategoryField::create([
        'asset_category_id' => $categorie->id, 'key' => 'brand_model', 'label' => 'Marque & modèle',
        'type' => 'text', 'is_required' => true, 'is_canonical_identifier' => false, 'position' => 2,
    ]);
}

function utilisateurConnecte(): User
{
    $utilisateur = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
    Sanctum::actingAs($utilisateur);

    return $utilisateur;
}

function corpsEnregistrement(string $chassis = '1M8GDM9AXKP042788'): array
{
    return [
        'category' => 'moto',
        'attributes' => ['chassis' => $chassis, 'brand_model' => 'Yamaha Crux'],
    ];
}

it('exige une authentification pour enregistrer un bien', function (): void {
    $this->postJson('/api/v1/assets', corpsEnregistrement())->assertStatus(401);

    expect(Asset::count())->toBe(0);
});

it('enregistre le bien et renvoie sa fiche', function (): void {
    utilisateurConnecte();

    $this->postJson('/api/v1/assets', corpsEnregistrement())
        ->assertStatus(201)
        ->assertJsonPath('asset.life_status.code', 'V-PRV')
        ->assertJsonPath('asset.trust_level.code', 'F1')
        ->assertJsonStructure(['asset' => ['public_ref', 'life_status' => ['label', 'color'], 'registered_at']]);

    expect(Asset::count())->toBe(1);
});

it('affiche les statuts en langage courant, jamais les codes seuls (CT-04)', function (): void {
    utilisateurConnecte();

    $this->postJson('/api/v1/assets', corpsEnregistrement())
        ->assertStatus(201)
        ->assertJsonPath('asset.life_status.label', 'Enregistrement récent')
        ->assertJsonPath('asset.trust_level.label', 'Déclaré, non vérifié');
});

it('renvoie la fiche existante et le parcours de réclamation en cas de doublon', function (): void {
    $premier = utilisateurConnecte();
    $this->postJson('/api/v1/assets', corpsEnregistrement())->assertStatus(201);
    $existant = Asset::sole();

    utilisateurConnecte();

    $this->postJson('/api/v1/assets', corpsEnregistrement())
        ->assertStatus(409)
        ->assertJsonPath('asset.public_ref', $existant->public_ref)
        ->assertJsonStructure(['message', 'asset', 'claim_url']);

    expect(Asset::count())->toBe(1)
        ->and($premier->id)->not->toBeNull();
});

it('ne divulgue jamais l\'identité du détenteur sur la fiche d\'un doublon', function (): void {
    // Règle métier absolue n° 4 : celui qui tombe sur un bien déjà enregistré
    // ne doit rien apprendre de son détenteur — c'est le cas d'un acheteur
    // qui découvre que le vendeur n'est pas le propriétaire déclaré.
    // Identifiant volontairement improbable : avec « 1 », l'assertion
    // d'absence ci-dessous passerait sur n'importe quelle date ou couleur.
    DB::table('users')->insert([
        'id' => 987654, 'phone' => '+2250701020304', 'full_name' => 'Awa Koné',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $detenteur = User::findOrFail(987654);
    Sanctum::actingAs($detenteur);

    $this->postJson('/api/v1/assets', corpsEnregistrement())->assertStatus(201);

    utilisateurConnecte();
    $reponse = $this->postJson('/api/v1/assets', corpsEnregistrement())->assertStatus(409);

    expect($reponse->content())->not->toContain('Awa Koné')
        ->and($reponse->content())->not->toContain((string) $detenteur->id)
        ->and($reponse->json('asset'))->not->toHaveKey('owner_user_id');
});

it('ne renvoie pas l\'identifiant complet du bien sur la fiche publique', function (): void {
    utilisateurConnecte();
    $this->postJson('/api/v1/assets', corpsEnregistrement())->assertStatus(201);

    utilisateurConnecte();
    $reponse = $this->postJson('/api/v1/assets', corpsEnregistrement())->assertStatus(409);

    expect($reponse->content())->not->toContain('1M8GDM9AXKP042788');
});

it('refuse un identifiant inexploitable', function (): void {
    utilisateurConnecte();

    $this->postJson('/api/v1/assets', corpsEnregistrement('AB'))->assertStatus(422);

    expect(Asset::count())->toBe(0);
});

it('refuse une catégorie inconnue', function (): void {
    utilisateurConnecte();

    $this->postJson('/api/v1/assets', [
        'category' => 'sous-marin',
        'attributes' => ['chassis' => '1M8GDM9AXKP042788'],
    ])->assertStatus(422);
});

it('exige les champs déclarés obligatoires par la catégorie', function (): void {
    utilisateurConnecte();

    $this->postJson('/api/v1/assets', [
        'category' => 'moto',
        'attributes' => ['chassis' => '1M8GDM9AXKP042788'],
    ])->assertStatus(422)->assertJsonValidationErrors('attributes.brand_model');
});

it('rattache le bien à son propriétaire connecté, jamais à un autre', function (): void {
    $utilisateur = utilisateurConnecte();
    $autre = User::create(['phone' => '+2250799999999']);

    // Même en soufflant un owner_user_id, c'est le porteur du jeton qui compte.
    $this->postJson('/api/v1/assets', [
        ...corpsEnregistrement(),
        'owner_user_id' => $autre->id,
    ])->assertStatus(201);

    expect(Asset::sole()->owner_user_id)->toBe($utilisateur->id);
});

it('ne retient que les champs déclarés par la catégorie', function (): void {
    // La colonne `attributes` est un JSON libre en base : sans filtre, un
    // client pourrait y déposer n'importe quelle charge, stockée à vie aux
    // frais de la plateforme.
    utilisateurConnecte();

    $this->postJson('/api/v1/assets', [
        'category' => 'moto',
        'attributes' => [
            'chassis' => '1M8GDM9AXKP042788',
            'brand_model' => 'Yamaha Crux',
            'charge_arbitraire' => str_repeat('x', 5000),
        ],
    ])->assertStatus(201);

    expect(Asset::sole()->attributes)->toBe([
        'chassis' => '1M8GDM9AXKP042788',
        'brand_model' => 'Yamaha Crux',
    ]);
});

it('accepte le chronomètre client sans le rendre obligatoire', function (): void {
    utilisateurConnecte();

    $this->postJson('/api/v1/assets', [...corpsEnregistrement(), 'client_elapsed_ms' => 47300])
        ->assertStatus(201);

    $entree = DB::table('audit_log')->where('action', 'asset.registered')->first();
    $payload = json_decode((string) ($entree->payload ?? '{}'), true);

    expect($payload['client_elapsed_ms'] ?? null)->toBe(47300);
});
