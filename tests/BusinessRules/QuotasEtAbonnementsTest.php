<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Payment;
use App\Models\User;
use App\Services\QuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * ST-0804 (quota particulier, upsell NON BLOQUANT) et ST-0805 (paliers flotte).
 *
 * La décision structurante : le quota n'empêche jamais d'enregistrer. Un bien
 * non enregistré est un bien non protégé, et refuser le quatrième
 * enregistrement d'une famille reviendrait à laisser une moto dehors.
 */
function proprietaireQuota(int $quotaGratuit = 3): User
{
    $utilisateur = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
    $utilisateur->forceFill(['free_assets_quota' => $quotaGratuit])->save();

    return $utilisateur;
}

function bienDeQuota(User $proprietaire, ?int $societeId = null, ?int $actif = 1): Asset
{
    $identifiant = 'QUOTA'.random_int(100000, 999999);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'company_id' => $societeId,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => $identifiant,
        'identifier_normalized' => $identifiant,
        'active_flag' => $actif,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now(),
    ]);
}

it('compte les places gratuites restantes', function (): void {
    $proprietaire = proprietaireQuota();
    bienDeQuota($proprietaire);

    $etat = app(QuotaService::class)->forUser($proprietaire);

    expect($etat['free_quota'])->toBe(3)
        ->and($etat['used'])->toBe(1)
        ->and($etat['remaining'])->toBe(2)
        ->and($etat['over_quota'])->toBeFalse();
});

it('dit le prix et ce qu\'il ouvre plutôt que de se contenter d\'un refus', function (): void {
    // Un refus qui n'indique pas la sortie ne laisse que l'abandon — et
    // l'abandon signifie ici un bien qui reste non enregistré, donc non
    // protégé. Le message doit porter le montant et rassurer sur l'existant.
    $proprietaire = proprietaireQuota();

    foreach (range(1, 4) as $bien) {
        bienDeQuota($proprietaire);
    }

    $etat = app(QuotaService::class)->forUser($proprietaire);

    expect($etat['over_quota'])->toBeTrue()
        ->and($etat['remaining'])->toBe(0)
        ->and($etat['message'])->toContain('restent protégés');
});

it('ne compte pas les enregistrements archivés', function (): void {
    // Un bien cédé ne pèse plus sur le quota de son ancien détenteur, qui n'en
    // tire plus aucun service.
    $proprietaire = proprietaireQuota();
    bienDeQuota($proprietaire);
    bienDeQuota($proprietaire, actif: null);

    expect(app(QuotaService::class)->forUser($proprietaire)['used'])->toBe(1);
});

it('ne compte pas les biens de flotte dans le quota personnel', function (): void {
    $proprietaire = proprietaireQuota();
    $societe = Company::create([
        'owner_user_id' => $proprietaire->id,
        'legal_name' => 'Loueur SARL',
        'rccm_number' => 'CI-ABJ-2024-B-99999',
        'business_type' => 'car_rental',
        'validation_status' => 'validated',
    ]);

    bienDeQuota($proprietaire);
    bienDeQuota($proprietaire, societeId: $societe->id);

    expect(app(QuotaService::class)->forUser($proprietaire)['used'])->toBe(1);
});

it('crédite une place achetée', function (): void {
    $proprietaire = proprietaireQuota();

    foreach (range(1, 3) as $bien) {
        bienDeQuota($proprietaire);
    }

    Payment::create([
        'user_id' => $proprietaire->id,
        'purpose' => 'asset_slot',
        'amount_fcfa' => 500,
        'provider' => PaymentProvider::PawapayWave,
        'provider_ref' => 'SLOT-1',
        'status' => PaymentStatus::Succeeded,
        'paid_at' => now(),
    ]);

    $etat = app(QuotaService::class)->forUser($proprietaire);

    expect($etat['paid_slots'])->toBe(1)
        ->and($etat['remaining'])->toBe(1)
        ->and($etat['over_quota'])->toBeFalse();
});

it('ne compte plus une place achetée il y a plus d\'un an', function (): void {
    // 500 FCFA/bien/AN : la place se renouvelle, elle ne s'acquiert pas.
    $proprietaire = proprietaireQuota();

    Payment::create([
        'user_id' => $proprietaire->id,
        'purpose' => 'asset_slot',
        'amount_fcfa' => 500,
        'provider' => PaymentProvider::PawapayWave,
        'provider_ref' => 'SLOT-VIEUX',
        'status' => PaymentStatus::Succeeded,
        'paid_at' => now()->subMonths(13),
    ]);

    expect(app(QuotaService::class)->forUser($proprietaire)['paid_slots'])->toBe(0);
});

it('ignore un paiement de place non abouti', function (): void {
    $proprietaire = proprietaireQuota();

    Payment::create([
        'user_id' => $proprietaire->id,
        'purpose' => 'asset_slot',
        'amount_fcfa' => 500,
        'provider' => PaymentProvider::PawapayWave,
        'provider_ref' => 'SLOT-EN-ATTENTE',
        'status' => PaymentStatus::Pending,
    ]);

    expect(app(QuotaService::class)->forUser($proprietaire)['paid_slots'])->toBe(0);
});

it('laisse les premiers véhicules d\'une flotte gratuits', function (): void {
    // Un loueur doit pouvoir éprouver le service sur une partie de son parc
    // avant d'engager un abonnement.
    $proprietaire = proprietaireQuota();
    $societe = Company::create([
        'owner_user_id' => $proprietaire->id,
        'legal_name' => 'Loueur SARL',
        'rccm_number' => 'CI-ABJ-2024-B-11111',
        'business_type' => 'car_rental',
        'validation_status' => 'validated',
        'free_fleet_quota' => 3,
    ]);

    foreach (range(1, 3) as $vehicule) {
        bienDeQuota($proprietaire, societeId: $societe->id);
    }

    $etat = app(QuotaService::class)->forCompany($societe);

    expect($etat['billable'])->toBe(0)
        ->and($etat['monthly_fcfa'])->toBe(0);
});

it('facture les véhicules au-delà du quota de flotte', function (): void {
    $proprietaire = proprietaireQuota();
    $societe = Company::create([
        'owner_user_id' => $proprietaire->id,
        'legal_name' => 'Loueur SARL',
        'rccm_number' => 'CI-ABJ-2024-B-22222',
        'business_type' => 'car_rental',
        'validation_status' => 'validated',
        'free_fleet_quota' => 3,
    ]);

    foreach (range(1, 8) as $vehicule) {
        bienDeQuota($proprietaire, societeId: $societe->id);
    }

    $etat = app(QuotaService::class)->forCompany($societe);

    expect($etat['fleet_size'])->toBe(8)
        ->and($etat['billable'])->toBe(5)
        ->and($etat['unit_price_fcfa'])->toBe(500)
        ->and($etat['monthly_fcfa'])->toBe(2500);
});

it('fait baisser le prix unitaire avec la taille du parc', function (): void {
    // Sans paliers, un loueur de cent véhicules paierait cent fois le prix
    // d'un particulier — et n'aurait aucune raison de tout déclarer, alors que
    // c'est la couverture complète du parc qui fait la valeur du registre.
    $service = app(QuotaService::class);

    expect($service->fleetUnitPrice(5))->toBe(500)
        ->and($service->fleetUnitPrice(10))->toBe(400)
        ->and($service->fleetUnitPrice(60))->toBe(300)
        ->and($service->fleetUnitPrice(250))->toBe(200);
});
