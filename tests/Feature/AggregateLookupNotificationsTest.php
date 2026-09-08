<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\Lookup;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** ST-1002 : job horaire d'agrégation, jamais d'identité ni d'IP. */
function bienDe(User $proprietaire, string $identifiant = '1M8GDM9AXKP042788'): Asset
{
    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => $identifiant,
        'identifier_normalized' => $identifiant,
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonths(2),
    ]);
}

/**
 * Horodatée une minute plus tôt par défaut : le job agrège une période RÉVOLUE
 * et exclut sa borne haute, sans quoi un passage lent recompterait les
 * consultations arrivées pendant son propre traitement. Une consultation créée
 * dans la même seconde que le passage relèverait donc de la période suivante —
 * ce qui est correct, mais ne teste rien.
 */
function consultation(Asset $bien, ?User $consultant = null, ?string $quand = null): void
{
    Lookup::create([
        'identifier_normalized' => $bien->identifier_normalized,
        'found_asset_id' => $bien->id,
        'ip_hash' => str_repeat('c', 64),
        'user_id' => $consultant?->id,
        'source' => 'app',
        'created_at' => $quand ?? now()->subMinute()->format('Y-m-d H:i:s'),
    ]);
}

it('émet une seule notification portant le compte de la période', function (): void {
    $proprietaire = User::create(['phone' => '+2250700000001']);
    $bien = bienDe($proprietaire);

    foreach (range(1, 5) as $visite) {
        consultation($bien);
    }

    $this->artisan('preuve:aggregate-lookups')->assertSuccessful();

    $notification = Notification::sole();

    expect($notification->user_id)->toBe($proprietaire->id)
        ->and($notification->payload['count'] ?? null)->toBe(5)
        ->and($notification->body)->toContain('5');
});

it('ne compte pas les consultations du propriétaire sur son propre bien', function (): void {
    // « Votre bien a été consulté 3 fois » alors qu'il s'agit de lui-même
    // détruirait la confiance dans le signal.
    $proprietaire = User::create(['phone' => '+2250700000001']);
    $bien = bienDe($proprietaire);

    consultation($bien, $proprietaire);
    consultation($bien, $proprietaire);
    consultation($bien);

    $this->artisan('preuve:aggregate-lookups')->assertSuccessful();

    expect(Notification::sole()->payload['count'])->toBe(1);
});

it('ne notifie pas un propriétaire qui ne s\'est fait consulter que par lui-même', function (): void {
    $proprietaire = User::create(['phone' => '+2250700000001']);
    $bien = bienDe($proprietaire);

    consultation($bien, $proprietaire);

    $this->artisan('preuve:aggregate-lookups')->assertSuccessful();

    expect(Notification::count())->toBe(0);
});

it('ne recompte jamais deux fois la même consultation', function (): void {
    // Sans curseur, chaque passage renotifierait tout l'historique.
    $proprietaire = User::create(['phone' => '+2250700000001']);
    $bien = bienDe($proprietaire);

    consultation($bien);

    $this->artisan('preuve:aggregate-lookups')->assertSuccessful();
    $this->artisan('preuve:aggregate-lookups')->assertSuccessful();

    expect(Notification::count())->toBe(1);
});

it('reprend à partir du dernier passage', function (): void {
    $proprietaire = User::create(['phone' => '+2250700000001']);
    $bien = bienDe($proprietaire);

    consultation($bien);
    $this->artisan('preuve:aggregate-lookups')->assertSuccessful();

    $this->travel(30)->minutes();
    consultation($bien);
    consultation($bien);
    $this->artisan('preuve:aggregate-lookups')->assertSuccessful();

    $notifications = Notification::orderBy('id')->get();

    expect($notifications)->toHaveCount(2)
        ->and($notifications[0]->payload['count'])->toBe(1)
        ->and($notifications[1]->payload['count'])->toBe(2);
});

it('sépare les biens et leurs propriétaires', function (): void {
    $premier = User::create(['phone' => '+2250700000001']);
    $second = User::create(['phone' => '+2250700000002']);
    $bienA = bienDe($premier, '1M8GDM9AXKP042788');
    $bienB = bienDe($second, 'JH2PC35061M200001');

    consultation($bienA);
    consultation($bienB);
    consultation($bienB);

    $this->artisan('preuve:aggregate-lookups')->assertSuccessful();

    expect(Notification::where('user_id', $premier->id)->sole()->payload['count'])->toBe(1)
        ->and(Notification::where('user_id', $second->id)->sole()->payload['count'])->toBe(2);
});

it('ignore les consultations d\'identifiants inconnus', function (): void {
    // Elles n'ont aucun propriétaire à prévenir : elles ne servent qu'à
    // repérer un balayage.
    Lookup::create([
        'identifier_normalized' => 'JH2PC35061M200001',
        'found_asset_id' => null,
        'ip_hash' => str_repeat('d', 64),
        'source' => 'app',
        'created_at' => now()->format('Y-m-d H:i:s'),
    ]);

    $this->artisan('preuve:aggregate-lookups')->assertSuccessful();

    expect(Notification::count())->toBe(0);
});

it('n\'écrit jamais d\'empreinte d\'adresse dans la notification', function (): void {
    $proprietaire = User::create(['phone' => '+2250700000001']);
    $bien = bienDe($proprietaire);
    consultation($bien);

    $this->artisan('preuve:aggregate-lookups')->assertSuccessful();

    $notification = Notification::sole();

    expect(json_encode($notification->payload))->not->toContain(str_repeat('c', 64))
        ->and($notification->body)->not->toContain('IP');
});

it('avance le curseur même sans aucune consultation', function (): void {
    // Sinon la période du passage suivant s'allongerait indéfiniment, jusqu'à
    // rattraper des consultations déjà notifiées.
    $proprietaire = User::create(['phone' => '+2250700000001']);
    $bien = bienDe($proprietaire);

    $this->artisan('preuve:aggregate-lookups')->assertSuccessful();

    $this->travel(3)->hours();
    consultation($bien);
    $this->artisan('preuve:aggregate-lookups')->assertSuccessful();

    expect(Notification::sole()->payload['count'])->toBe(1);
});
