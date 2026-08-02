<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Exceptions\DoublonActifException;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatusHistory;
use App\Models\AuditLog;
use App\Models\CategoryField;
use App\Models\User;
use App\Services\AssetRegistrationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ST-0201 (enregistrement en 4 gestes), ST-0203 (normalisation) et ST-0204
 * (unicité en temps réel). Vérifie surtout la règle métier absolue n° 3 :
 * un identifiant = un enregistrement actif, une collision ne crée JAMAIS de
 * bien.
 *
 * N'utilise pas RefreshDatabase : l'enregistrement écrit dans la chaîne
 * d'audit, qui refuse toute transaction englobante.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('asset_status_history')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerEnregistrement();
    categorieMoto();
});
afterEach(fn () => nettoyerEnregistrement());

function nettoyerEnregistrement(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'asset_status_history', 'assets', 'users', 'category_fields', 'asset_categories'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function categorieMoto(): AssetCategory
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

    return $categorie;
}

function proprietaire(): User
{
    return User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
}

function enregistrer(User $owner, string $chassis = '1M8GDM9AXKP042788'): Asset
{
    return app(AssetRegistrationService::class)->register(
        $owner,
        'moto',
        ['chassis' => $chassis, 'brand_model' => 'Yamaha Crux'],
    );
}

it('crée le bien en Déclaré non vérifié et Enregistrement récent', function (): void {
    // Aucun KYC n'est exigé à ce stade : la friction doit rester nulle pour
    // que l'enregistrement tienne en 90 secondes (CT-02, CT-06).
    $bien = enregistrer(proprietaire());

    expect($bien->trust_level)->toBe(TrustLevel::Declared)
        ->and($bien->life_status)->toBe(LifeStatus::Provisional)
        ->and($bien->active_flag)->toBe(1)
        ->and($bien->registered_at)->not->toBeNull();
});

it('ouvre une fenêtre de contestation de trente jours', function (): void {
    $bien = enregistrer(proprietaire());

    expect((int) $bien->registered_at->diffInDays($bien->provisional_until, true))->toBe(30);
});

it('attribue une référence publique opaque et unique', function (): void {
    // La référence publique circule sur le web SEO : elle ne doit rien dire de
    // l'identifiant réel du bien ni de son propriétaire.
    $premier = enregistrer(proprietaire(), '1M8GDM9AXKP042788');
    $second = enregistrer(proprietaire(), 'JH2PC35061M200001');

    expect($premier->public_ref)->toStartWith('PRV-')
        ->and($premier->public_ref)->not->toBe($second->public_ref)
        ->and($premier->public_ref)->not->toContain('042788');
});

it('normalise l\'identifiant avant tout contrôle', function (): void {
    // Sans normalisation préalable, « 1m8gdm9axkp042788 » et
    // « 1M8GDM9AXKP-042788 » seraient deux biens actifs pour un seul châssis.
    $bien = enregistrer(proprietaire(), '1m8gdm9axkp-042788');

    expect($bien->identifier_normalized)->toBe('1M8GDM9AXKP042788')
        ->and($bien->identifier_raw)->toBe('1m8gdm9axkp-042788');
});

it('reconnaît le type d\'identifiant sans le demander à l\'utilisateur', function (): void {
    $vin = enregistrer(proprietaire(), '1M8GDM9AXKP042788');
    $imei = enregistrer(proprietaire(), '490154203237518');

    expect($vin->identifier_type)->toBe('vin')
        ->and($imei->identifier_type)->toBe('imei');
});

it('refuse un second enregistrement actif pour le même identifiant', function (): void {
    $premier = enregistrer(proprietaire(), '1M8GDM9AXKP042788');

    expect(fn () => enregistrer(proprietaire(), '1M8GDM9AXKP042788'))
        ->toThrow(
            fn (DoublonActifException $e) => expect($e->existant->id)->toBe($premier->id)
        );
});

it('ne crée jamais rien lors d\'une tentative de doublon', function (): void {
    // « Tentative de doublon : jamais de création » — la fiche existante et le
    // parcours de réclamation sont la seule issue.
    enregistrer(proprietaire(), '1M8GDM9AXKP042788');

    try {
        enregistrer(proprietaire(), '1m8gdm9axkp 042788');
    } catch (DoublonActifException) {
        // Attendu.
    }

    expect(Asset::count())->toBe(1)
        ->and(AssetStatusHistory::count())->toBe(1);
});

it('journalise la tentative de doublon sur le bien visé', function (): void {
    // ST-0205 : la tentative doit être traçable pour détecter les fraudes tôt,
    // même avant que la notification au détenteur n'existe.
    $premier = enregistrer(proprietaire(), '1M8GDM9AXKP042788');
    $fraudeur = proprietaire();

    try {
        enregistrer($fraudeur, '1M8GDM9AXKP042788');
    } catch (DoublonActifException) {
        // Attendu.
    }

    $tentative = AuditLog::where('action', 'asset.duplicate_attempt')->sole();

    expect($tentative->entity_id)->toBe($premier->id)
        ->and($tentative->actor_id)->toBe($fraudeur->id)
        // L'identifiant en clair n'a rien à faire dans un journal inaltérable :
        // il suffit de savoir quel bien a été visé.
        ->and(json_encode($tentative->payload))->not->toContain('1M8GDM9AXKP042788');
});

it('autorise un nouvel enregistrement une fois le précédent archivé', function (): void {
    $premier = enregistrer(proprietaire(), '1M8GDM9AXKP042788');
    Asset::whereKey($premier->id)->update(['active_flag' => null]);

    $second = enregistrer(proprietaire(), '1M8GDM9AXKP042788');

    expect($second->id)->not->toBe($premier->id)
        ->and(Asset::whereNotNull('active_flag')->count())->toBe(1);
});

it('ouvre l\'historique des statuts par une ligne sans statut d\'origine', function (): void {
    $bien = enregistrer(proprietaire());

    $ligne = AssetStatusHistory::where('asset_id', $bien->id)->sole();

    expect($ligne->from_status)->toBeNull()
        ->and($ligne->to_status)->toBe(LifeStatus::Provisional);
});

it('journalise l\'enregistrement dans la chaîne d\'audit', function (): void {
    $owner = proprietaire();
    $bien = enregistrer($owner);

    $entree = AuditLog::where('action', 'asset.registered')->sole();

    expect($entree->entity_type)->toBe('asset')
        ->and($entree->entity_id)->toBe($bien->id)
        ->and($entree->actor_id)->toBe($owner->id)
        ->and(json_encode($entree->payload))->not->toContain('1M8GDM9AXKP042788');
});

it('conserve le chronomètre client pour la télémétrie CT-02', function (): void {
    // CT-02 impose de mesurer le temps réel d'enregistrement : sans relevé, le
    // critère des 90 secondes ne serait qu'une intention.
    $bien = app(AssetRegistrationService::class)->register(
        proprietaire(),
        'moto',
        ['chassis' => '1M8GDM9AXKP042788', 'brand_model' => 'Yamaha Crux'],
        clientElapsedMs: 47_300,
    );

    $entree = AuditLog::where('action', 'asset.registered')->sole();

    expect($entree->payload['client_elapsed_ms'] ?? null)->toBe(47300)
        ->and($bien->id)->not->toBeNull();
});

it('refuse une catégorie inconnue ou dépubliée', function (): void {
    AssetCategory::where('key', 'moto')->update(['is_active' => false]);

    expect(fn () => enregistrer(proprietaire()))->toThrow(RuntimeException::class);
});

it('refuse un identifiant vide ou inexploitable', function (): void {
    expect(fn () => enregistrer(proprietaire(), '   '))->toThrow(RuntimeException::class)
        ->and(fn () => enregistrer(proprietaire(), 'AB'))->toThrow(RuntimeException::class);
});

it('laisse l\'index unique trancher la course entre deux enregistrements simultanés', function (): void {
    // La lecture préalable ne sérialise rien : deux requêtes concurrentes la
    // passent toutes les deux. C'est l'index unique de la base qui tranche, et
    // le perdant doit repartir sur la fiche existante, jamais sur une erreur
    // technique.
    //
    // Le concurrent écrit par une SECONDE connexion, donc hors de la
    // transaction en cours : c'est la seule façon de reproduire un COMMIT
    // concurrent réel dans un test à processus unique.
    config()->set('database.connections.concurrent', config('database.connections.'.config('database.default')));

    // Le propriétaire concurrent est créé AVANT, donc committé : créé depuis
    // le listener, il resterait invisible pour l'autre connexion, dont la clé
    // étrangère attendrait cette ligne jusqu'au délai d'attente d'InnoDB.
    $autreProprietaire = proprietaire();
    $concurrentAAgi = false;

    Asset::creating(function () use (&$concurrentAAgi, $autreProprietaire): void {
        if ($concurrentAAgi) {
            return;
        }

        $concurrentAAgi = true;

        DB::connection('concurrent')->table('assets')->insert([
            'public_ref' => 'PRV-CONCUR01',
            'owner_user_id' => $autreProprietaire->id,
            'asset_category_key' => 'moto',
            'identifier_type' => 'vin',
            'identifier_raw' => '1M8GDM9AXKP042788',
            'identifier_normalized' => '1M8GDM9AXKP042788',
            'active_flag' => 1,
            'attributes' => json_encode(['brand_model' => 'Yamaha Crux']),
            'trust_level' => 'F1',
            'life_status' => 'V-PRV',
            'registered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        expect(fn () => enregistrer(proprietaire(), '1M8GDM9AXKP042788'))
            ->toThrow(DoublonActifException::class);
    } finally {
        Asset::flushEventListeners();
        DB::purge('concurrent');
    }

    expect(Asset::whereNotNull('active_flag')->count())->toBe(1);
});

it('conserve les champs propres à la catégorie', function (): void {
    $bien = enregistrer(proprietaire());

    expect($bien->attributes)->toBe([
        'chassis' => '1M8GDM9AXKP042788',
        'brand_model' => 'Yamaha Crux',
    ]);
});
