<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Services\FleetService;
use App\Services\SubscriptionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * ST-0805 : relances puis suspension DOUCE.
 *
 * Le point sur lequel ce parcours ne transige jamais : une flotte en retard ne
 * peut plus AJOUTER de véhicules, mais ceux qu'elle a déjà enregistrés restent
 * protégés. Couper la protection punirait les véhicules, pas le débiteur.
 *
 * N'utilise pas RefreshDatabase : les changements d'état écrivent dans la
 * chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('companies')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerAbonnements();
    Storage::fake('s3');
    $this->abonnements = app(SubscriptionService::class);
});
afterEach(fn () => nettoyerAbonnements());

function nettoyerAbonnements(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    // `lookups` compris : un test vérifie qu'un véhicule suspendu reste
    // consultable, et la ligne qu'il journalise survivrait au test.
    foreach (['audit_log', 'notifications', 'lookups', 'assets', 'companies', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function flotteEnRetard(int $joursDeRetard, int $vehicules = 8): Company
{
    $loueur = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);

    $societe = Company::create([
        'owner_user_id' => $loueur->id,
        'legal_name' => 'Abidjan Location',
        'rccm_number' => 'CI-ABJ-'.random_int(10000, 99999),
        'business_type' => 'car_rental',
        'validation_status' => 'validated',
        'free_fleet_quota' => 3,
        'subscription_status' => SubscriptionStatus::Active,
        'subscription_due_at' => now()->subDays($joursDeRetard),
    ]);

    foreach (range(1, $vehicules) as $index) {
        $plaque = 'FLOTTE'.random_int(100000, 999999);

        Asset::create([
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
            'life_status' => LifeStatus::Active,
            'registered_at' => now()->subMonths(2),
        ]);
    }

    return $societe;
}

it('ne relance pas une flotte à jour', function (): void {
    $societe = flotteEnRetard(-5);

    expect($this->abonnements->advance($societe))->toBeFalse()
        ->and(Notification::count())->toBe(0);
});

it('relance après quelques jours de retard', function (): void {
    // Une facture peut se perdre, un responsable changer : suspendre au
    // premier jour ferait perdre un client sur un malentendu administratif.
    $societe = flotteEnRetard(4);

    expect($this->abonnements->advance($societe))->toBeTrue()
        ->and($societe->fresh()?->subscription_status)->toBe(SubscriptionStatus::PastDue);

    $relance = Notification::sole();

    expect($relance->body)->toContain('restent protégés');
});

it('n\'envoie pas deux fois la même relance', function (): void {
    $societe = flotteEnRetard(4);

    $this->abonnements->advance($societe);
    $this->abonnements->advance($societe->fresh() ?? $societe);

    expect(Notification::count())->toBe(1);
});

it('échelonne trois relances avant la suspension', function (): void {
    $societe = flotteEnRetard(4);
    $this->abonnements->advance($societe);

    $societe->forceFill(['subscription_due_at' => now()->subDays(11)])->save();
    $this->abonnements->advance($societe->fresh() ?? $societe);

    $societe->forceFill(['subscription_due_at' => now()->subDays(21)])->save();
    $this->abonnements->advance($societe->fresh() ?? $societe);

    expect(Notification::count())->toBe(3)
        ->and($societe->fresh()?->subscription_status)->toBe(SubscriptionStatus::PastDue);
});

it('bascule en lecture seule au terme, sans toucher aux véhicules', function (): void {
    // LE test de la suspension douce : la protection acquise ne se retire pas.
    $societe = flotteEnRetard(35);

    $this->abonnements->advance($societe);

    $etat = $societe->fresh()?->subscription_status;

    expect($etat)->toBe(SubscriptionStatus::ReadOnly)
        ->and($etat?->allowsNewAssets())->toBeFalse()
        ->and($etat?->keepsExistingProtection())->toBeTrue()
        ->and(Asset::where('company_id', $societe->id)->whereNotNull('active_flag')->count())->toBe(8);
});

it('laisse les véhicules d\'une flotte suspendue consultables', function (): void {
    // Un acheteur consultant un véhicule volé ne doit pas recevoir « bien
    // inconnu » parce que son propriétaire a du retard de paiement.
    $societe = flotteEnRetard(35);
    $this->abonnements->advance($societe);

    $vehicule = Asset::where('company_id', $societe->id)->firstOrFail();

    $this->getJson('/api/v1/lookup/'.$vehicule->identifier_normalized)
        ->assertOk()
        ->assertJsonPath('found', true);
});

it('explique la suspension sans menacer', function (): void {
    $societe = flotteEnRetard(35);
    $this->abonnements->advance($societe);

    $avis = Notification::orderByDesc('id')->first();

    expect($avis?->body)->toContain('restent protégés')
        ->and($avis?->body)->toContain('nous ne coupons jamais');
});

it('rétablit la flotte après règlement', function (): void {
    $societe = flotteEnRetard(35);
    $this->abonnements->advance($societe);

    $retablie = $this->abonnements->markPaid($societe->fresh() ?? $societe);

    expect($retablie->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($retablie->subscription_status->allowsNewAssets())->toBeTrue()
        ->and($retablie->dunning_count)->toBe(0)
        ->and($retablie->subscription_due_at?->isFuture())->toBeTrue();
});

it('produit un décompte qui ne se fait pas passer pour une facture', function (): void {
    // Les mentions fiscales exigibles doivent être validées par un comptable :
    // laisser croire à une facture opposable serait une faute.
    $societe = flotteEnRetard(0);

    $decompte = $this->abonnements->statement($societe);

    expect($decompte)->toContain('Facturés              : 5')
        ->and($decompte)->toContain('TOTAL                 : 2500 FCFA')
        ->and($decompte)->toContain("n'est pas une facture fiscale");
});

it('refuse l\'import de flotte en lecture seule', function (): void {
    $societe = flotteEnRetard(35);
    $this->abonnements->advance($societe);

    $loueur = $societe->owner;

    expect(fn () => app(FleetService::class)->import(
        $societe->fresh() ?? $societe,
        $loueur,
        UploadedFile::fake()->createWithContent('f.csv', "category,identifier\nvoiture,ABC123\n"),
    ))->toThrow(DomainException::class);
});

it('traite les flottes en retard par le passage planifié', function (): void {
    flotteEnRetard(35);
    flotteEnRetard(4);
    flotteEnRetard(-2);

    $this->artisan('preuve:subscription-dunning')->assertSuccessful();

    expect(Company::where('subscription_status', SubscriptionStatus::ReadOnly->value)->count())->toBe(1)
        ->and(Company::where('subscription_status', SubscriptionStatus::PastDue->value)->count())->toBe(1)
        ->and(Company::where('subscription_status', SubscriptionStatus::Active->value)->count())->toBe(1);
});
