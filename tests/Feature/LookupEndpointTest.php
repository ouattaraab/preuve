<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\Lookup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * ST-0301 à ST-0306 : GET /api/v1/lookup/{identifier}, la seule route d'écriture
 * de journal accessible sans compte — et la seule qui doive le rester
 * (règle métier absolue n° 1).
 */
function bienPublie(string $identifiant = '1M8GDM9AXKP042788', ?LifeStatus $statut = null): Asset
{
    $proprietaire = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => $identifiant,
        'identifier_normalized' => $identifiant,
        'active_flag' => 1,
        'attributes' => ['brand_model' => 'Yamaha Crux'],
        'trust_level' => TrustLevel::Declared,
        'life_status' => $statut ?? LifeStatus::Active,
        'registered_at' => now()->subMonths(6),
    ]);
}

it('répond sans aucune authentification', function (): void {
    // La promesse du produit : vérifier un bien au marché, avant de payer,
    // sans rien installer ni créer.
    bienPublie();

    $this->getJson('/api/v1/lookup/1M8GDM9AXKP042788')
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('verdict', 'known');
});

it('sert le verdict en langage courant avec sa couleur (CT-04)', function (): void {
    bienPublie('1M8GDM9AXKP042788', LifeStatus::Stolen);

    $this->getJson('/api/v1/lookup/1M8GDM9AXKP042788')
        ->assertOk()
        ->assertJsonPath('asset.life_status.label', 'Volé déclaré')
        ->assertJsonPath('asset.life_status.warning', true)
        ->assertJsonPath('message', "Volé déclaré — n'achetez pas ce bien.");
});

it('distingue clairement un identifiant inconnu', function (): void {
    $reponse = $this->getJson('/api/v1/lookup/JH2PC35061M200001')
        ->assertOk()
        ->assertJsonPath('found', false)
        ->assertJsonPath('verdict', 'unknown');

    expect($reponse->json('message'))->toContain('ni un bon ni un mauvais signe');
});

it('ne divulgue jamais l\'identité du détenteur', function (): void {
    $bien = bienPublie();
    $bien->owner->update(['full_name' => 'Awa Koné']);

    $reponse = $this->getJson('/api/v1/lookup/1M8GDM9AXKP042788')->assertOk();

    expect($reponse->content())->not->toContain('Awa Koné')
        ->and($reponse->json('asset'))->not->toHaveKey('owner_user_id');
});

it('ne renvoie pas l\'identifiant consulté dans la fiche', function (): void {
    // Publier l'identifiant complet permettrait de constituer par balayage
    // l'annuaire des biens enregistrés.
    bienPublie();

    $reponse = $this->getJson('/api/v1/lookup/1M8GDM9AXKP042788')->assertOk();

    expect($reponse->json('asset.identifier_type'))->toBe('vin')
        ->and($reponse->json('asset'))->not->toHaveKey('identifier_normalized')
        ->and($reponse->json('asset'))->not->toHaveKey('identifier_raw');
});

it('accepte une saisie recopiée telle quelle', function (): void {
    bienPublie();

    $this->getJson('/api/v1/lookup/'.urlencode('1m8gdm9axkp-042788'))
        ->assertOk()
        ->assertJsonPath('found', true);
});

it('répond par la référence publique des pages web (ST-0306)', function (): void {
    $bien = bienPublie();

    $this->getJson('/api/v1/lookup/'.$bien->public_ref)
        ->assertOk()
        ->assertJsonPath('asset.public_ref', $bien->public_ref);
});

it('journalise la consultation sans écrire l\'adresse en clair', function (): void {
    bienPublie();

    $this->withServerVariables(['REMOTE_ADDR' => '41.66.123.45'])
        ->getJson('/api/v1/lookup/1M8GDM9AXKP042788')
        ->assertOk();

    $ligne = Lookup::sole();

    expect($ligne->ip_hash)->not->toContain('41.66.123.45')
        ->and($ligne->user_id)->toBeNull();
});

it('plafonne le visiteur anonyme et réclame un CAPTCHA', function (): void {
    // DES NUMÉROS DISTINCTS : le plafond compte désormais ce qu'on ÉNUMÈRE, et
    // non ce qu'on demande. Rejouer le même numéro dix fois ne consomme plus
    // rien — revérifier la moto qu'on négocie est le geste le plus honnête du
    // parcours, et il ne doit rien coûter.
    bienPublie();

    // Le refus sec n'intervient qu'au plafond dur faute de défi : on l'abaisse
    // ici plutôt que de lancer trois cents requêtes.
    config()->set('preuve.lookup_rate_limit.anonymous_ceiling', 10);

    foreach (range(1, 10) as $n) {
        $this->getJson('/api/v1/lookup/ENUMERE'.$n.'0000000')->assertOk();
    }

    $this->getJson('/api/v1/lookup/1M8GDM9AXKP042788')
        ->assertStatus(429)
        ->assertJsonPath('verdict', 'rate_limited')
        ->assertJsonPath('captcha_required', true)
        ->assertHeader('Retry-After');
});

it('ne plafonne pas un consultant authentifié', function (): void {
    bienPublie();
    Sanctum::actingAs(User::create(['phone' => '+2250700111222']));

    foreach (range(1, 15) as $consultation) {
        $this->getJson('/api/v1/lookup/1M8GDM9AXKP042788')->assertOk();
    }
});

it('refuse une saisie trop courte sans consommer le quota', function (): void {
    $this->getJson('/api/v1/lookup/AB')
        ->assertStatus(422)
        ->assertJsonPath('verdict', 'invalid');

    expect(Lookup::count())->toBe(0);
});

it('autorise la mise en cache courte d\'un verdict public', function (): void {
    // CT-01 impose moins d'une seconde au 95e centile en 3G : une page de
    // statut consultée en rafale doit pouvoir être servie par le cache.
    bienPublie();

    $this->getJson('/api/v1/lookup/1M8GDM9AXKP042788')
        ->assertOk()
        ->assertHeader('Cache-Control');
});

it('marque la source web quand elle est demandée', function (): void {
    bienPublie();

    $this->getJson('/api/v1/lookup/1M8GDM9AXKP042788?source=web')->assertOk();

    expect(Lookup::sole()->source)->toBe('web');
});
