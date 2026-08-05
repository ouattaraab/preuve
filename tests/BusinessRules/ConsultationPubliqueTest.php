<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\Lookup;
use App\Models\User;
use App\Services\Captcha\TurnstileVerifier;
use App\Services\LookupService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Règle métier absolue n° 1 : la consultation de statut est gratuite, anonyme
 * et SANS compte. Règle n° 4 : l'identité du déclarant n'est jamais divulguée.
 * Règle n° 8 : l'adresse IP n'est jamais écrite en clair.
 */
function bienConsultable(string $identifiant = '1M8GDM9AXKP042788', ?LifeStatus $statut = null): Asset
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

it('trouve un bien enregistré sans exiger le moindre compte', function (): void {
    $bien = bienConsultable();

    $resultat = app(LookupService::class)->lookup('1M8GDM9AXKP042788', '41.66.0.1');

    expect($resultat->found)->toBeTrue()
        ->and($resultat->asset?->id)->toBe($bien->id);
});

it('normalise l\'identifiant saisi', function (): void {
    // Un acheteur recopie une plaque comme il la lit : avec des espaces, des
    // tirets, en minuscules. Le verdict doit être le même (CT-01, 2 gestes).
    bienConsultable();

    $service = app(LookupService::class);

    expect($service->lookup('1m8gdm9axkp-042788', '41.66.0.1')->found)->toBeTrue()
        ->and($service->lookup('1M8GDM9AXKP 042788', '41.66.0.1')->found)->toBeTrue();
});

it('retrouve un bien par sa référence publique', function (): void {
    // ST-0306 : les pages web indexables portent la référence publique, pas
    // l'identifiant réel du bien.
    $bien = bienConsultable();

    $resultat = app(LookupService::class)->lookup($bien->public_ref, '41.66.0.1');

    expect($resultat->found)->toBeTrue()
        ->and($resultat->asset?->id)->toBe($bien->id);
});

it('ignore les enregistrements archivés', function (): void {
    // Seul l'enregistrement actif fait foi : un ancien détenteur ne doit pas
    // pouvoir faire remonter sa fiche périmée.
    $bien = bienConsultable();
    Asset::whereKey($bien->id)->update(['active_flag' => null]);

    expect(app(LookupService::class)->lookup('1M8GDM9AXKP042788', '41.66.0.1')->found)->toBeFalse();
});

it('distingue « inconnu » de « sans problème »', function (): void {
    // ST-0303 : l'absence n'est ni un bon ni un mauvais signe. La confondre
    // avec un verdict favorable ferait acheter un bien volé non déclaré.
    $resultat = app(LookupService::class)->lookup('JH2PC35061M200001', '41.66.0.1');

    expect($resultat->found)->toBeFalse()
        ->and($resultat->asset)->toBeNull()
        ->and($resultat->message)->not->toContain('Aucun signalement');
});

it('journalise chaque consultation, trouvée ou non', function (): void {
    $bien = bienConsultable();

    app(LookupService::class)->lookup('1M8GDM9AXKP042788', '41.66.0.1');
    app(LookupService::class)->lookup('JH2PC35061M200001', '41.66.0.1');

    $journal = Lookup::orderBy('id')->get();

    expect($journal)->toHaveCount(2)
        ->and($journal[0]->found_asset_id)->toBe($bien->id)
        // L'identifiant inconnu est journalisé lui aussi : c'est ce qui permet
        // de repérer un balayage.
        ->and($journal[1]->found_asset_id)->toBeNull()
        ->and($journal[1]->identifier_normalized)->toBe('JH2PC35061M200001');
});

it('n\'écrit jamais l\'adresse IP en clair', function (): void {
    app(LookupService::class)->lookup('1M8GDM9AXKP042788', '41.66.123.45');

    $ligne = DB::table('lookups')->sole();

    expect($ligne->ip_hash)->not->toContain('41.66.123.45')
        ->and($ligne->ip_hash)->toHaveLength(64)
        // Un SHA-256 nu de l'adresse serait renversable par balayage de
        // l'espace IPv4 : l'empreinte est salée.
        ->and($ligne->ip_hash)->not->toBe(hash('sha256', '41.66.123.45'));
});

it('change l\'empreinte d\'une même adresse d\'un jour à l\'autre', function (): void {
    // Le sel quotidien empêche de recouper les consultations d'un même
    // visiteur sur la durée : c'est ce qui rend le journal inexploitable pour
    // du profilage, y compris depuis l'intérieur.
    app(LookupService::class)->lookup('1M8GDM9AXKP042788', '41.66.123.45');

    $this->travel(1)->day();
    app(LookupService::class)->lookup('1M8GDM9AXKP042788', '41.66.123.45');

    $empreintes = Lookup::pluck('ip_hash');

    expect($empreintes[0])->not->toBe($empreintes[1]);
});

it('garde la même empreinte au sein d\'une même journée', function (): void {
    // Sans cette stabilité, le plafond horaire ne compterait rien.
    app(LookupService::class)->lookup('1M8GDM9AXKP042788', '41.66.123.45');
    $this->travel(30)->minutes();
    app(LookupService::class)->lookup('1M8GDM9AXKP042788', '41.66.123.45');

    $empreintes = Lookup::pluck('ip_hash');

    expect($empreintes[0])->toBe($empreintes[1]);
});

it('plafonne les consultations anonymes à dix par heure', function (): void {
    // §8 anti-profilage : sans plafond, un concessionnaire peut cartographier
    // le parc entier en balayant les identifiants.
    bienConsultable();
    $service = app(LookupService::class);

    foreach (range(1, 10) as $consultation) {
        expect($service->lookup('1M8GDM9AXKP042788', '41.66.0.1')->rateLimited)->toBeFalse();
    }

    $onzieme = $service->lookup('1M8GDM9AXKP042788', '41.66.0.1');

    expect($onzieme->rateLimited)->toBeTrue()
        ->and($onzieme->asset)->toBeNull();
});

it('N\'ANNONCE PAS UNE ATTENTE quand un défi permet de passer tout de suite', function (): void {
    // La phrase du refus est LUE AVANT LE BOUTON. « Réessayez dans un moment »
    // au-dessus d'un défi qui rouvre le passage immédiatement ferait renoncer
    // quelqu'un qui pouvait continuer — sur le seul parcours que le produit
    // promet gratuit et sans compte.
    app(SettingsRepository::class)->set(TurnstileVerifier::SITE_KEY_SETTING, '0x4AAA');
    app(SettingsRepository::class)->set(TurnstileVerifier::SECRET_SETTING, '0x2BBB');

    bienConsultable();
    $service = app(LookupService::class);

    foreach (range(1, 10) as $consultation) {
        $service->lookup('1M8GDM9AXKP042788', '41.66.0.9');
    }

    $refus = $service->lookup('1M8GDM9AXKP042788', '41.66.0.9');

    expect($refus->rateLimited)->toBeTrue()
        ->and($refus->message)->not->toContain('Réessayez dans un moment')
        ->and($refus->message)->toContain('tout de suite');
});

it('annonce bien une attente quand AUCUN défi n\'est configuré', function (): void {
    // Là, l'attente est réellement la seule issue : la dire est honnête.
    bienConsultable();
    $service = app(LookupService::class);

    foreach (range(1, 10) as $consultation) {
        $service->lookup('1M8GDM9AXKP042788', '41.66.0.8');
    }

    $refus = $service->lookup('1M8GDM9AXKP042788', '41.66.0.8');

    expect($refus->message)->toContain('Réessayez dans un moment');
});

it('ne compte le plafond que sur l\'heure écoulée', function (): void {
    bienConsultable();
    $service = app(LookupService::class);

    foreach (range(1, 10) as $consultation) {
        $service->lookup('1M8GDM9AXKP042788', '41.66.0.1');
    }

    $this->travel(61)->minutes();

    expect($service->lookup('1M8GDM9AXKP042788', '41.66.0.1')->rateLimited)->toBeFalse();
});

it('ne plafonne pas un utilisateur authentifié', function (): void {
    $consultant = User::create(['phone' => '+2250700111222']);
    bienConsultable();
    $service = app(LookupService::class);

    foreach (range(1, 15) as $consultation) {
        $resultat = $service->lookup('1M8GDM9AXKP042788', '41.66.0.1', $consultant);
        expect($resultat->rateLimited)->toBeFalse();
    }
});

it('ne fait pas payer à une adresse le dépassement d\'une autre', function (): void {
    bienConsultable();
    $service = app(LookupService::class);

    foreach (range(1, 11) as $consultation) {
        $service->lookup('1M8GDM9AXKP042788', '41.66.0.1');
    }

    expect($service->lookup('1M8GDM9AXKP042788', '41.66.0.2')->rateLimited)->toBeFalse();
});

it('n\'expose jamais l\'identité du détenteur dans le résultat', function (): void {
    // Règle métier absolue n° 4, versant consultant : celui qui consulte ne
    // doit rien apprendre du déclarant.
    $bien = bienConsultable();

    // Identifiant volontairement improbable : avec un petit entier,
    // l'assertion d'absence passerait sur n'importe quelle date ou couleur.
    DB::table('users')->insert([
        'id' => 987654, 'phone' => '+2250701020304', 'full_name' => 'Awa Koné',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('assets')->where('id', $bien->id)->update(['owner_user_id' => 987654]);

    $resultat = app(LookupService::class)->lookup('1M8GDM9AXKP042788', '41.66.0.1');

    expect(json_encode($resultat->toPublicArray()))
        ->not->toContain('987654')
        ->and(json_encode($resultat->toPublicArray()))->not->toContain('Awa Koné')
        ->and($resultat->toPublicArray())->not->toHaveKey('owner_user_id');
});

it('rattache la consultation au compte quand il y en a un', function (): void {
    $consultant = User::create(['phone' => '+2250700111222']);
    bienConsultable();

    app(LookupService::class)->lookup('1M8GDM9AXKP042788', '41.66.0.1', $consultant);

    expect(Lookup::sole()->user_id)->toBe($consultant->id);
});

it('refuse une saisie qui ne peut désigner aucun bien', function (): void {
    $resultat = app(LookupService::class)->lookup('AB', '41.66.0.1');

    expect($resultat->found)->toBeFalse()
        ->and($resultat->invalidIdentifier)->toBeTrue()
        // Une saisie trop courte ne consomme pas le quota : c'est une faute de
        // frappe, pas une tentative de balayage.
        ->and(Lookup::count())->toBe(0);
});

it('rapporte le verdict complet d\'un bien volé', function (): void {
    bienConsultable('1M8GDM9AXKP042788', LifeStatus::Stolen);

    $public = app(LookupService::class)->lookup('1M8GDM9AXKP042788', '41.66.0.1')->toPublicArray();

    expect($public['asset']['life_status']['label'])->toBe('Volé déclaré')
        ->and($public['asset']['life_status']['warning'])->toBeTrue()
        ->and($public['asset']['life_status']['color'])->toBe('#C62F21');
});
