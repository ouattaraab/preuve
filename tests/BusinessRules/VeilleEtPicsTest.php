<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\NotificationType;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\Lookup;
use App\Models\Notification;
use App\Models\User;
use App\Models\WatchAlert;
use App\Services\WatchAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * ST-0403 (veille) et ST-0405 (pics de consultation).
 *
 * L'enjeu central : une veille libre permettrait de surveiller le bien
 * d'autrui — savoir quand il est consulté, donc quand il est mis en vente.
 * Ce serait retourner contre les utilisateurs le dispositif censé les
 * protéger, et violer l'anonymat symétrique (règle métier absolue n° 4).
 */
function bienDe2(User $proprietaire, string $identifiant = '1M8GDM9AXKP042788', ?int $actif = 1): Asset
{
    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => $identifiant,
        'identifier_normalized' => $identifiant,
        'active_flag' => $actif,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonths(2),
    ]);
}

function utilisateur2(): User
{
    return User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
}

function consulter(Asset $bien, int $fois, ?User $par = null): void
{
    foreach (range(1, $fois) as $index) {
        Lookup::create([
            'identifier_normalized' => $bien->identifier_normalized,
            'found_asset_id' => $bien->id,
            'ip_hash' => str_repeat((string) ($index % 10), 64),
            'user_id' => $par?->id,
            'source' => 'app',
            'created_at' => now()->subMinutes($index)->format('Y-m-d H:i:s'),
        ]);
    }
}

it('autorise la veille sur un identifiant que l\'on a enregistré', function (): void {
    $proprietaire = utilisateur2();
    bienDe2($proprietaire);

    $veille = app(WatchAlertService::class)->watch($proprietaire, '1M8GDM9AXKP042788');

    expect($veille->is_active)->toBeTrue()
        ->and($veille->identifier_normalized)->toBe('1M8GDM9AXKP042788');
});

it('refuse la veille sur le bien d\'un tiers', function (): void {
    // Sinon il suffirait de connaître un numéro de châssis — lisible sur le
    // véhicule — pour savoir quand son propriétaire cherche à le vendre.
    bienDe2(utilisateur2());

    expect(fn () => app(WatchAlertService::class)->watch(utilisateur2(), '1M8GDM9AXKP042788'))
        ->toThrow(DomainException::class);
});

it('refuse la veille sur un identifiant inconnu', function (): void {
    // Sans cette borne, veiller sur un identifiant au hasard préviendrait du
    // jour où quelqu'un l'enregistre.
    expect(fn () => app(WatchAlertService::class)->watch(utilisateur2(), 'JH2PC35061M200001'))
        ->toThrow(DomainException::class);
});

it('laisse veiller une victime dépossédée de son enregistrement', function (): void {
    // Cas décisif : le bien a été réenregistré par un tiers, la victime n'a
    // plus de ligne active — et c'est elle qui a le plus besoin d'être alertée.
    $victime = utilisateur2();
    bienDe2($victime, actif: null);

    $veille = app(WatchAlertService::class)->watch($victime, '1M8GDM9AXKP042788');

    expect($veille->is_active)->toBeTrue();
});

it('réactive une veille existante au lieu d\'en empiler une seconde', function (): void {
    $proprietaire = utilisateur2();
    bienDe2($proprietaire);
    $service = app(WatchAlertService::class);

    $service->watch($proprietaire, '1M8GDM9AXKP042788');
    $service->unwatch($proprietaire, '1M8GDM9AXKP042788');
    $service->watch($proprietaire, '1M8GDM9AXKP042788', 'sms');

    expect(WatchAlert::count())->toBe(1)
        ->and(WatchAlert::sole()->is_active)->toBeTrue()
        ->and(WatchAlert::sole()->channel)->toBe('sms');
});

it('normalise l\'identifiant surveillé', function (): void {
    $proprietaire = utilisateur2();
    bienDe2($proprietaire);

    app(WatchAlertService::class)->watch($proprietaire, '1m8gdm9axkp-042788');

    expect(WatchAlert::sole()->identifier_normalized)->toBe('1M8GDM9AXKP042788');
});

it('alerte le détenteur quand son bien est anormalement consulté', function (): void {
    $proprietaire = utilisateur2();
    $bien = bienDe2($proprietaire);
    consulter($bien, 6);

    $this->artisan('preuve:detect-lookup-spikes')->assertSuccessful();

    $alerte = Notification::where('user_id', $proprietaire->id)->sole();

    expect($alerte->type)->toBe(NotificationType::LookupSpike)
        ->and($alerte->payload['count'] ?? null)->toBe(6);
});

it('ne dit jamais qui a consulté', function (): void {
    // Une alerte de pic reste agrégée et anonyme, comme toute notification de
    // consultation.
    $proprietaire = utilisateur2();
    $bien = bienDe2($proprietaire);
    $curieux = User::create(['phone' => '+2250777777777', 'full_name' => 'Yao N.']);
    consulter($bien, 6, $curieux);

    $this->artisan('preuve:detect-lookup-spikes')->assertSuccessful();

    $alerte = Notification::where('user_id', $proprietaire->id)->sole();

    expect($alerte->body)->not->toContain('Yao N.')
        ->and(json_encode($alerte->payload))->not->toContain((string) $curieux->id)
        ->and($alerte->body)->toContain('jamais qui consulte');
});

it('n\'alerte pas en deçà du seuil', function (): void {
    $proprietaire = utilisateur2();
    consulter(bienDe2($proprietaire), 3);

    $this->artisan('preuve:detect-lookup-spikes')->assertSuccessful();

    expect(Notification::count())->toBe(0);
});

it('ne compte pas les consultations du propriétaire sur son propre bien', function (): void {
    // Il connaît déjà son bien : ses propres passages ne font pas un pic.
    $proprietaire = utilisateur2();
    $bien = bienDe2($proprietaire);
    consulter($bien, 6, $proprietaire);

    $this->artisan('preuve:detect-lookup-spikes')->assertSuccessful();

    expect(Notification::count())->toBe(0);
});

it('n\'alerte qu\'une fois pour un pic qui dure', function (): void {
    // Une alerte répétée finit ignorée — précisément celle qu'il ne faut pas
    // manquer.
    $proprietaire = utilisateur2();
    consulter(bienDe2($proprietaire), 6);

    $this->artisan('preuve:detect-lookup-spikes')->assertSuccessful();
    $this->artisan('preuve:detect-lookup-spikes')->assertSuccessful();

    expect(Notification::count())->toBe(1);
});

it('réalerte une fois le silence écoulé', function (): void {
    $proprietaire = utilisateur2();
    $bien = bienDe2($proprietaire);
    consulter($bien, 6);

    $this->artisan('preuve:detect-lookup-spikes')->assertSuccessful();

    $this->travel(25)->hours();
    consulter($bien, 6);
    $this->artisan('preuve:detect-lookup-spikes')->assertSuccessful();

    expect(Notification::count())->toBe(2);
});

it('prévient le veilleur dépossédé, pas seulement le détenteur enregistré', function (): void {
    // Les deux ne se recouvrent pas : le détenteur enregistré peut être celui
    // qui a dépossédé le veilleur.
    $victime = utilisateur2();
    bienDe2($victime, actif: null);
    app(WatchAlertService::class)->watch($victime, '1M8GDM9AXKP042788');

    $fraudeur = utilisateur2();
    $bienActif = bienDe2($fraudeur);
    consulter($bienActif, 6);

    $this->artisan('preuve:detect-lookup-spikes')->assertSuccessful();

    expect(Notification::where('user_id', $victime->id)->count())->toBe(1)
        ->and(Notification::where('user_id', $fraudeur->id)->count())->toBe(1);
});

it('ne notifie pas deux fois qui est à la fois détenteur et veilleur', function (): void {
    $proprietaire = utilisateur2();
    $bien = bienDe2($proprietaire);
    app(WatchAlertService::class)->watch($proprietaire, '1M8GDM9AXKP042788');
    consulter($bien, 6);

    $this->artisan('preuve:detect-lookup-spikes')->assertSuccessful();

    expect(Notification::where('user_id', $proprietaire->id)->count())->toBe(1)
        // La veille est tout de même marquée comme déclenchée, pour que son
        // silence coure aussi.
        ->and(WatchAlert::sole()->last_triggered_at)->not->toBeNull();
});

it('ne déclenche pas une veille désactivée', function (): void {
    $victime = utilisateur2();
    bienDe2($victime, actif: null);
    $service = app(WatchAlertService::class);
    $service->watch($victime, '1M8GDM9AXKP042788');
    $service->unwatch($victime, '1M8GDM9AXKP042788');

    consulter(bienDe2(utilisateur2()), 6);

    $this->artisan('preuve:detect-lookup-spikes')->assertSuccessful();

    expect(Notification::where('user_id', $victime->id)->count())->toBe(0);
});

it('accepte un seuil passé en argument', function (): void {
    $proprietaire = utilisateur2();
    consulter(bienDe2($proprietaire), 3);

    $this->artisan('preuve:detect-lookup-spikes', ['--threshold' => 2])->assertSuccessful();

    expect(Notification::count())->toBe(1);
});

it('ignore les consultations hors de la fenêtre', function (): void {
    $proprietaire = utilisateur2();
    $bien = bienDe2($proprietaire);

    consulter($bien, 6);
    $this->travel(48)->hours();

    $this->artisan('preuve:detect-lookup-spikes')->assertSuccessful();

    expect(Notification::count())->toBe(0);
});
