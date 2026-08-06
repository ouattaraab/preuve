<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Captcha\TurnstileVerifier;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Défi anti-automate sur la consultation (§8).
 *
 * Le plafond horaire empêche un concessionnaire de cartographier le parc en
 * balayant les identifiants. Mais il ne doit pas condamner un visiteur
 * légitime derrière une adresse partagée — cybercafé, partage de connexion
 * mobile : le cas courant en Côte d'Ivoire, où une seule adresse publique sert
 * à des dizaines de personnes.
 *
 * Le défi rouvre le passage à qui le résout. Il ne l'ouvre pas en grand.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('app_settings')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerDefi();
    Cache::flush();
    Http::preventStrayRequests();

    $reglages = app(SettingsRepository::class);
    $reglages->set(TurnstileVerifier::SITE_KEY_SETTING, '0x4AAAAAAA-cle-publique');
    $reglages->setSecret(TurnstileVerifier::SECRET_SETTING, '0x4AAAAAAA-secret');
    $reglages->fresh();
});
afterEach(fn () => nettoyerDefi());

function nettoyerDefi(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'app_settings', 'lookups', 'personal_access_tokens', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * Consomme jusqu'au refus, plutôt qu'un nombre fixe : un test qui a déjà
 * consulté une fois atteindrait sinon le plafond avant la fin de la boucle, et
 * échouerait pour une raison sans rapport avec son objet.
 */
/**
 * Consomme le quota en BALAYANT, comme un automate.
 *
 * DES IDENTIFIANTS DISTINCTS, et c'est tout le sujet : le plafond compte
 * désormais les numéros différents et non les requêtes, parce que revérifier
 * la même moto pendant qu'on négocie est le geste le plus honnête du parcours.
 * Ce harnais rejouait dix fois les dix mêmes numéros — il ne consommait donc
 * plus rien, et n'éprouvait plus le plafond qu'il était censé atteindre.
 */
function epuiserLePlafond(): void
{
    sansJeton();

    $plafond = (int) config('preuve.lookup_rate_limit.anonymous_ceiling');
    $seuil = (int) config('preuve.lookup_rate_limit.anonymous_per_hour');

    foreach (range(1, max($plafond, $seuil) + 1) as $i) {
        if (test()->getJson('/api/v1/lookup/BALAYAGE'.$i.'000000')->status() === 429) {
            return;
        }
    }
}

/**
 * Vide les en-têtes par défaut.
 *
 * `withHeader()` les mémorise pour TOUTE la durée du test : sans cette remise à
 * zéro, une requête censée arriver sans jeton hériterait de celui posé par la
 * précédente, et le test constaterait un comportement que la production n'a
 * jamais. Même piège que le contrôleur mis en cache par le routeur.
 */
function sansJeton(): void
{
    test()->flushHeaders();
}

function turnstileRepond(bool $succes): void
{
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => $succes], 200),
    ]);
}

it('remet la clé publique avec le refus, et seulement là', function (): void {
    // Le client n'a besoin d'afficher un défi qu'à ce moment : rien ne doit
    // l'obliger à charger un script tiers sur le chemin nominal.
    $nominal = test()->getJson('/api/v1/lookup/1M8GDM9AXKP042788')->assertOk();

    expect($nominal->json())->not->toHaveKey('captcha');

    epuiserLePlafond();

    $refus = test()->getJson('/api/v1/lookup/1M8GDM9AXKP042788')->assertStatus(429);

    expect($refus->json('captcha_required'))->toBeTrue()
        ->and($refus->json('captcha.provider'))->toBe('turnstile')
        ->and($refus->json('captcha.site_key'))->toBe('0x4AAAAAAA-cle-publique');
});

it('rouvre le passage à qui résout le défi', function (): void {
    epuiserLePlafond();
    test()->getJson('/api/v1/lookup/1M8GDM9AXKP042788')->assertStatus(429);

    turnstileRepond(true);

    test()->withHeader('X-Captcha-Token', 'jeton-valide')
        ->getJson('/api/v1/lookup/1M8GDM9AXKP042788')
        ->assertOk();
});

it('ne fait PAS du défi un laissez-passer', function (): void {
    // LE test. Sans plafond sur l'octroi, un automate résoudrait un seul défi
    // puis balaierait le registre à loisir — le défi n'aurait fait que
    // retarder d'une minute ce qu'il devait empêcher. Ce qui coûte à
    // l'attaquant, c'est de devoir en résoudre un tous les dix.
    epuiserLePlafond();
    turnstileRepond(true);

    $octroi = (int) config('preuve.captcha.grant_lookups');

    // Le défi rend exactement ce qui est annoncé — et il faut CONTINUER À
    // BALAYER pour le consommer, puisque seuls les numéros distincts comptent.
    foreach (range(1, $octroi) as $i) {
        test()->withHeader('X-Captcha-Token', 'jeton-'.$i)
            ->getJson('/api/v1/lookup/APRESDEFI'.$i.'00000')
            ->assertOk();
    }

    // Puis le plafond reprend, et un NOUVEAU défi est exigé.
    sansJeton();
    test()->getJson('/api/v1/lookup/APRESDEFI99000000')->assertStatus(429);
});

it('refuse un jeton que Cloudflare rejette', function (): void {
    epuiserLePlafond();
    turnstileRepond(false);

    test()->withHeader('X-Captcha-Token', 'jeton-forge')
        ->getJson('/api/v1/lookup/1M8GDM9AXKP042788')
        ->assertStatus(429);
});

it('échoue fermé quand Cloudflare est injoignable', function (): void {
    // L'inverse ferait du défi un obstacle qu'il suffit de faire tomber. Les
    // visiteurs concernés sont de toute façon déjà au-delà du plafond : échouer
    // fermé ne dégrade rien pour eux.
    epuiserLePlafond();

    Http::fake([
        'challenges.cloudflare.com/*' => fn () => throw new RuntimeException('réseau coupé'),
    ]);

    test()->withHeader('X-Captcha-Token', 'jeton-valide')
        ->getJson('/api/v1/lookup/1M8GDM9AXKP042788')
        ->assertStatus(429);
});

it('ne promet aucun défi quand rien n\'est configuré', function (): void {
    app(SettingsRepository::class)->forget(TurnstileVerifier::SITE_KEY_SETTING);
    app(SettingsRepository::class)->forget(TurnstileVerifier::SECRET_SETTING);
    app(SettingsRepository::class)->fresh();

    // SANS DÉFI, le refus n'intervient qu'au plafond dur — un mur bas fermerait
    // le produit à tout un quartier derrière une même adresse. On l'abaisse ici
    // pour éprouver le refus sans lancer trois cents requêtes.
    config()->set('preuve.lookup_rate_limit.anonymous_ceiling', 10);

    epuiserLePlafond();

    $refus = test()->getJson('/api/v1/lookup/1M8GDM9AXKP042788')->assertStatus(429);

    // Le refus tient, mais on n'annonce pas une échappatoire inexistante.
    expect($refus->json('captcha'))->toBeNull();
});

it('ne consulte jamais Cloudflare sur le chemin nominal', function (): void {
    // CT-01 promet moins d'une seconde : la consultation ordinaire ne doit
    // payer aucun aller-retour vers un tiers. Et un jeton Turnstile étant à
    // usage unique, le brûler sans nécessité serait absurde.
    test()->withHeader('X-Captcha-Token', 'jeton-inutile')
        ->getJson('/api/v1/lookup/1M8GDM9AXKP042788')
        ->assertOk();

    Http::assertNothingSent();
});

it('n\'ouvre le passage que pour l\'adresse qui a résolu le défi', function (): void {
    // L'octroi est porté par l'empreinte de l'adresse : celle d'un autre
    // visiteur ne doit pas en profiter.
    epuiserLePlafond();
    turnstileRepond(true);

    test()->withHeader('X-Captcha-Token', 'jeton-valide')
        ->getJson('/api/v1/lookup/1M8GDM9AXKP042788')
        ->assertOk();

    // Une autre adresse, sans jeton, épuise son propre plafond et reste refusée.
    sansJeton();
    test()->withServerVariables(['REMOTE_ADDR' => '41.66.77.88']);

    foreach (range(1, (int) config('preuve.lookup_rate_limit.anonymous_per_hour') + 1) as $i) {
        $statut = test()->getJson('/api/v1/lookup/1M8GDM9AXKP04278'.($i % 10))->status();

        if ($statut === 429) {
            expect($statut)->toBe(429);

            return;
        }
    }

    expect(false)->toBeTrue('la seconde adresse aurait dû être refusée');
});

it('journalise un changement de configuration du défi', function (): void {
    // Désactiver le défi rouvre le balayage du registre : cela ne doit pas
    // pouvoir se faire sans trace.
    $admin = User::create(['phone' => '+2250700000091']);
    $admin->forceFill(['role' => UserRole::Admin])->save();
    Sanctum::actingAs($admin);

    test()->putJson('/api/v1/admin/captcha-provider', [
        'site_key' => '0xNOUVELLE',
        'secret_key' => '0xSECRET',
    ])->assertOk();

    $entree = AuditLog::where('action', 'admin.captcha_provider_changed')->first();

    expect($entree)->not->toBeNull()
        // Jamais les clés elles-mêmes.
        ->and($entree?->payload)->not->toContain('0xSECRET');

    // La clé publique est rendue en clair, le secret jamais.
    test()->getJson('/api/v1/admin/captcha-provider')
        ->assertOk()
        ->assertJsonPath('site_key', '0xNOUVELLE')
        ->assertJsonPath('secret_key', '••••••••');
});
