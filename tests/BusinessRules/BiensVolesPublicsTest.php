<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\PaymentPurpose;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\User;
use App\Services\PaystackGateway;
use App\Services\Settings\SettingsRepository;
use App\Services\StolenListingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * ST-0805 : la liste publique des biens volés, et la mise en avant payante.
 *
 * LA DISTINCTION EST LE CŒUR DU DISPOSITIF. Déclarer un vol rend le bien
 * invendable pour quiconque VÉRIFIE son numéro : c'est la protection, elle est
 * immédiate et gratuite — celui qui déclare vient de se faire dépouiller.
 * Figurer sur la liste que tout le monde parcourt est autre chose : de la
 * VISIBILITÉ, et c'est elle qu'on facture.
 *
 * N'utilise pas RefreshDatabase : la publication écrit dans la chaîne d'audit,
 * qui refuse toute transaction englobante.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('assets')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerListeVolee();
    $this->liste = app(StolenListingService::class);
});
afterEach(fn () => nettoyerListeVolee());

function nettoyerListeVolee(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'payments', 'asset_status_history', 'assets', 'app_settings', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function detenteurVole(): User
{
    return User::create(['phone' => '+22507'.random_int(10000000, 99999999)]);
}

function bienVole(User $proprietaire, ?string $identifiant = null): Asset
{
    $numero = $identifiant ?? 'AA'.random_int(100, 999).'BC';

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'plate',
        'identifier_raw' => $numero,
        'identifier_normalized' => $numero,
        'active_flag' => 1,
        'attributes' => ['brand_model' => 'Yamaha XTZ 125'],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Stolen,
        'stolen_declared_at' => now()->subDays(2),
        'registered_at' => now()->subMonths(3),
    ]);
}

it('NE PUBLIE RIEN TANT QUE PERSONNE NE L\'A DEMANDÉ', function (): void {
    // LE test du dispositif. Déclarer protège ; publier expose un identifiant
    // réel. Publier d'office exposerait ce que personne n'a demandé d'exposer.
    bienVole(detenteurVole());

    test()->getJson('/api/v1/stolen')
        ->assertOk()
        ->assertJsonPath('pagination.total', 0);
});

it('MONTRE LE BIEN UNE FOIS PUBLIÉ, SANS COMPTE', function (): void {
    // Publique et anonyme, comme la consultation : un garagiste à qui l'on
    // apporte une moto n'ouvrira pas un compte pour vérifier une intuition.
    $proprietaire = detenteurVole();
    $bien = bienVole($proprietaire, 'AA123BC');

    test()->liste->publish($bien, $proprietaire);

    $reponse = test()->getJson('/api/v1/stolen')->assertOk();

    expect($reponse->json('pagination.total'))->toBe(1)
        ->and($reponse->json('stolen.0.identifier'))->toBe('AA123BC')
        ->and($reponse->json('stolen.0.brand_model'))->toBe('Yamaha XTZ 125');
});

it('NE NOMME JAMAIS LA VICTIME', function (): void {
    // Un annuaire de victimes serait une liste de gens à démarcher — ou pire,
    // à viser une seconde fois.
    $proprietaire = detenteurVole();
    $proprietaire->forceFill(['full_name' => 'Awa Koné'])->save();
    $bien = bienVole($proprietaire);

    test()->liste->publish($bien, $proprietaire);

    $corps = test()->getJson('/api/v1/stolen')->assertOk()->getContent();

    expect($corps)->not->toContain('Awa Koné')
        ->and($corps)->not->toContain((string) $proprietaire->phone)
        ->and($corps)->not->toContain('owner_user_id');
});

it('REFUSE DE PUBLIER UN BIEN QUI N\'EST PAS VOLÉ', function (): void {
    // Une liste de biens volés qui contiendrait des biens sains ne serait plus
    // consultée, et celui qui aurait payé n'aurait acheté que du bruit.
    $proprietaire = detenteurVole();
    $bien = bienVole($proprietaire);
    $bien->forceFill(['life_status' => LifeStatus::Active])->save();

    expect(fn () => test()->liste->publish($bien->fresh(), $proprietaire))
        ->toThrow(DomainException::class);
});

it('REFUSE DE PUBLIER LE BIEN D\'UN AUTRE', function (): void {
    $bien = bienVole(detenteurVole());

    expect(fn () => test()->liste->publish($bien, detenteurVole()))
        ->toThrow(DomainException::class);
});

it('PUBLIE SANS PAIEMENT quand le tarif est à zéro', function (): void {
    // Ouvrir une page de paiement pour zéro franc ferait échouer le parcours
    // faute d'opérateur, et pour rien.
    config()->set('preuve.theft_listing_price_fcfa', 0);

    $proprietaire = detenteurVole();
    $bien = bienVole($proprietaire);
    Sanctum::actingAs($proprietaire);

    // 200 et non 201 : rien n'a été créé chez un opérateur, le bien paraît
    // simplement sur la liste. Le 201 est réservé à l'ouverture d'un paiement.
    test()->postJson("/api/v1/assets/{$bien->id}/stolen-listing")
        ->assertOk()
        ->assertJsonPath('listed', true);

    expect($bien->fresh()?->stolen_listed_at)->not->toBeNull();
});

it('NE PUBLIE PAS AVANT PAIEMENT quand le tarif n\'est pas nul', function (): void {
    Http::fake(['api.paystack.co/*' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz', 'reference' => 'ref-1'],
    ])]);
    app(SettingsRepository::class)->set(PaystackGateway::SECRET_SETTING, 'sk_test_x');
    config()->set('preuve.theft_listing_price_fcfa', 1000);

    $proprietaire = detenteurVole();
    $bien = bienVole($proprietaire);
    Sanctum::actingAs($proprietaire);

    test()->postJson("/api/v1/assets/{$bien->id}/stolen-listing")
        ->assertCreated()
        ->assertJsonPath('listed', false)
        ->assertJsonPath('price_fcfa', 1000)
        ->assertJsonPath('checkout_url', 'https://checkout.paystack.com/xyz');

    // LE BIEN N'EST PAS PUBLIÉ SUR UNE INTENTION. Une intention se crée d'un
    // clic : s'en contenter offrirait la visibilité à qui ouvre la page de
    // paiement sans jamais y régler quoi que ce soit.
    expect($bien->fresh()?->stolen_listed_at)->toBeNull();
});

it('REFUSE LISIBLEMENT quand aucune passerelle n\'est configurée', function (): void {
    // C'est l'état de la production aujourd'hui. Une 500 opaque au moment où
    // quelqu'un essaie de payer serait le pire des deux mondes.
    app(SettingsRepository::class)->forget(PaystackGateway::SECRET_SETTING);
    app(SettingsRepository::class)->fresh();
    config()->set('preuve.theft_listing_price_fcfa', 1000);

    $proprietaire = detenteurVole();
    $bien = bienVole($proprietaire);
    Sanctum::actingAs($proprietaire);

    test()->postJson("/api/v1/assets/{$bien->id}/stolen-listing")
        ->assertStatus(422)
        ->assertJsonPath('message', fn (?string $m): bool => str_contains((string) $m, 'pas encore configuré'));

    expect($bien->fresh()?->stolen_listed_at)->toBeNull();
});

it('LE RETRAIT EST GRATUIT ET IMMÉDIAT', function (): void {
    // Un bien retrouvé, une déclaration faite par erreur : laisser une
    // publication survivre à son motif exposerait un identifiant pour rien.
    config()->set('preuve.theft_listing_price_fcfa', 1000);

    $proprietaire = detenteurVole();
    $bien = bienVole($proprietaire);
    test()->liste->publish($bien, $proprietaire);
    Sanctum::actingAs($proprietaire);

    test()->deleteJson("/api/v1/assets/{$bien->id}/stolen-listing")
        ->assertOk()
        ->assertJsonPath('listed', false);

    expect($bien->fresh()?->stolen_listed_at)->toBeNull();
});

it('CHERCHE SUR UN BOUT DE PLAQUE', function (): void {
    // Quelqu'un qui croit reconnaître une moto n'a souvent qu'un fragment.
    $proprietaire = detenteurVole();
    test()->liste->publish(bienVole($proprietaire, 'AA123BC'), $proprietaire);
    test()->liste->publish(bienVole($proprietaire, 'ZZ999XY'), $proprietaire);

    $reponse = test()->getJson('/api/v1/stolen?q=123')->assertOk();

    expect($reponse->json('pagination.total'))->toBe(1)
        ->and($reponse->json('stolen.0.identifier'))->toBe('AA123BC');
});

it('L\'APERÇU DE L\'ACCUEIL S\'ARRÊTE À DIX, et dit qu\'il y en a plus', function (): void {
    // Une liste sans fin sur un écran d'accueil se fait ignorer ; dix biens et
    // un total donnent une raison d'ouvrir la liste entière.
    $proprietaire = detenteurVole();

    foreach (range(1, 12) as $n) {
        test()->liste->publish(bienVole($proprietaire, 'AA'.(100 + $n).'BC'), $proprietaire);
    }

    $reponse = test()->getJson('/api/v1/stolen/preview')->assertOk();

    expect($reponse->json('stolen'))->toHaveCount(10)
        ->and($reponse->json('total'))->toBe(12)
        ->and($reponse->json('has_more'))->toBeTrue();
});

it('NE FACTURE PAS DEUX FOIS LA MÊME VISIBILITÉ', function (): void {
    config()->set('preuve.theft_listing_price_fcfa', 1000);

    $proprietaire = detenteurVole();
    $bien = bienVole($proprietaire);
    test()->liste->publish($bien, $proprietaire);

    $avant = $bien->fresh()?->stolen_listed_at;
    test()->liste->publish($bien->fresh(), $proprietaire);

    expect($bien->fresh()?->stolen_listed_at?->toIso8601String())
        ->toBe($avant?->toIso8601String());
});

it('LE MOTIF DE PAIEMENT EXISTE et porte son libellé', function (): void {
    // Un motif oublié dans le `match` de `fulfill()` produirait un encaissement
    // sans contrepartie — de l'argent pris pour rien.
    expect(PaymentPurpose::TheftListing->label())->toBe('Mise en avant d\'un bien volé');
    expect(PaymentPurpose::TheftDeclaration->label())->toBe('Déclaration de vol');
});

/*
|--------------------------------------------------------------------------
| La page web
|--------------------------------------------------------------------------
*/

it('LA PAGE PUBLIQUE EST INDEXABLE, contrairement à un résultat de consultation', function (): void {
    // Une page de résultat porte l'identifiant CHERCHÉ et ne doit surtout pas
    // être indexée. Celle-ci ne contient que ce que des détenteurs ont DEMANDÉ
    // à rendre public : la faire trouver par un moteur est le service payé.
    $proprietaire = detenteurVole();
    test()->liste->publish(bienVole($proprietaire, 'AA123BC'), $proprietaire);

    test()->get('/voles')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'index, follow')
        ->assertSee('AA123BC');
});

it('LA PAGE NE RASSURE PAS SUR UNE ABSENCE', function (): void {
    // Un bien qui ne figure pas ici n'est pas un bien sain : son détenteur n'a
    // peut-être pas demandé la publication. Laisser croire l'inverse ferait
    // acheter un bien volé.
    test()->get('/voles')
        ->assertOk()
        ->assertSee('peut très bien être volé', false);
});

it('LA PAGE NE NOMME PERSONNE', function (): void {
    $proprietaire = detenteurVole();
    $proprietaire->forceFill(['full_name' => 'Awa Koné'])->save();
    test()->liste->publish(bienVole($proprietaire), $proprietaire);

    test()->get('/voles')->assertOk()->assertDontSee('Awa Koné');
});

it('L\'ACCUEIL MÈNE À LA LISTE', function (): void {
    // Une page qu'on ne peut atteindre depuis nulle part n'existe pas — et
    // celle-ci est un service payé par des détenteurs.
    $proprietaire = detenteurVole();
    test()->liste->publish(bienVole($proprietaire, 'AA123BC'), $proprietaire);

    test()->get('/')
        ->assertOk()
        ->assertSee('AA123BC')
        ->assertSee('href="/voles"', false);
});

it('LA RECHERCHE WEB ACCEPTE UN PARAMÈTRE RÉPÉTÉ sans tomber', function (): void {
    // `?q=a&q=b` rend un TABLEAU : une page publique reçoit tout ce qu'on veut
    // bien lui envoyer.
    test()->get('/voles?q[]=a&q[]=b')->assertOk();
});
