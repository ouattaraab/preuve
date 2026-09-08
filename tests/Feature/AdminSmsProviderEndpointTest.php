<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Otp\ConfigurableOtpSender;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Configuration du fournisseur SMS depuis l'espace administrateur.
 *
 * N'utilise pas RefreshDatabase : toute modification de configuration est une
 * action sensible, donc journalisée dans la chaîne d'audit, qui refuse une
 * transaction englobante.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('app_settings')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerAdmin();
    $this->settings = app(SettingsRepository::class)->fresh();
});
afterEach(fn () => nettoyerAdmin());

function nettoyerAdmin(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'app_settings', 'personal_access_tokens', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function administrateur(): User
{
    $utilisateur = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
    $utilisateur->forceFill(['role' => UserRole::Admin])->save();
    Sanctum::actingAs($utilisateur);

    return $utilisateur;
}

function simpleUtilisateur(): User
{
    $utilisateur = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
    Sanctum::actingAs($utilisateur);

    return $utilisateur;
}

function configurationHttpValide(): array
{
    return [
        'provider' => 'http',
        'config' => [
            'endpoint_url' => 'https://api.exemple.ci/sms',
            'payload_template' => '{"to":"{{destination}}","text":"{{message}}"}',
            'auth_header' => 'Bearer cle-secrete-123',
        ],
    ];
}

it('refuse l\'accès à un visiteur non authentifié', function (): void {
    $this->getJson('/api/v1/admin/sms-provider')->assertStatus(401);
    $this->putJson('/api/v1/admin/sms-provider', configurationHttpValide())->assertStatus(401);
});

it('refuse l\'accès à un utilisateur ordinaire', function (): void {
    // Un compte créé par le parcours public ne doit jamais pouvoir rerouter
    // les SMS de la plateforme vers une passerelle qu'il contrôle.
    simpleUtilisateur();

    $this->getJson('/api/v1/admin/sms-provider')->assertStatus(403);
    $this->putJson('/api/v1/admin/sms-provider', configurationHttpValide())->assertStatus(403);
});

it('expose le fournisseur actif et le catalogue des fournisseurs', function (): void {
    administrateur();

    $this->getJson('/api/v1/admin/sms-provider')
        ->assertOk()
        ->assertJsonPath('active.provider', 'log')
        ->assertJsonStructure(['active' => ['provider', 'config'], 'available' => [['key', 'label', 'fields']]]);
});

it('enregistre le fournisseur choisi et sa configuration', function (): void {
    administrateur();

    $this->putJson('/api/v1/admin/sms-provider', configurationHttpValide())->assertOk();

    $envoyeur = app(ConfigurableOtpSender::class)->fresh();

    expect($envoyeur->activeProviderKey())->toBe('http')
        ->and($envoyeur->activeConfig()['endpoint_url'])->toBe('https://api.exemple.ci/sms')
        ->and($envoyeur->activeConfig()['auth_header'])->toBe('Bearer cle-secrete-123');
});

it('chiffre les champs secrets au repos', function (): void {
    administrateur();

    $this->putJson('/api/v1/admin/sms-provider', configurationHttpValide())->assertOk();

    $brut = DB::table('app_settings')->pluck('value')->implode('|');

    expect($brut)->not->toContain('Bearer cle-secrete-123')
        // L'adresse, elle, n'est pas un secret et reste lisible.
        ->and($brut)->toContain('api.exemple.ci');
});

it('ne renvoie jamais un secret déjà enregistré', function (): void {
    // Réafficher une clé d'API dans un formulaire l'expose à toute personne
    // ayant accès à l'écran, et la fait fuir dans les journaux du navigateur.
    administrateur();
    $this->putJson('/api/v1/admin/sms-provider', configurationHttpValide())->assertOk();

    $reponse = $this->getJson('/api/v1/admin/sms-provider')->assertOk();

    expect($reponse->content())->not->toContain('cle-secrete-123')
        ->and($reponse->json('active.config.auth_header'))->toBe('••••••••')
        ->and($reponse->json('active.config.endpoint_url'))->toBe('https://api.exemple.ci/sms');
});

it('conserve un secret déjà enregistré quand il n\'est pas resoumis', function (): void {
    // Puisque l'interface ne réaffiche pas le secret, un enregistrement du
    // formulaire ne doit pas l'effacer.
    administrateur();
    $this->putJson('/api/v1/admin/sms-provider', configurationHttpValide())->assertOk();

    $this->putJson('/api/v1/admin/sms-provider', [
        'provider' => 'http',
        'config' => [
            'endpoint_url' => 'https://api.autre.ci/sms',
            'payload_template' => '{"to":"{{destination}}","text":"{{message}}"}',
        ],
    ])->assertOk();

    $config = app(ConfigurableOtpSender::class)->fresh()->activeConfig();

    expect($config['auth_header'])->toBe('Bearer cle-secrete-123')
        ->and($config['endpoint_url'])->toBe('https://api.autre.ci/sms');
});

it('permet d\'effacer explicitement un secret', function (): void {
    administrateur();
    $this->putJson('/api/v1/admin/sms-provider', configurationHttpValide())->assertOk();

    $this->putJson('/api/v1/admin/sms-provider', [
        'provider' => 'http',
        'config' => [
            'endpoint_url' => 'https://api.exemple.ci/sms',
            'payload_template' => '{"to":"{{destination}}","text":"{{message}}"}',
            'auth_header' => '',
        ],
    ])->assertOk();

    expect(app(ConfigurableOtpSender::class)->fresh()->activeConfig())->not->toHaveKey('auth_header');
});

it('refuse un fournisseur inconnu', function (): void {
    administrateur();

    $this->putJson('/api/v1/admin/sms-provider', ['provider' => 'fantome', 'config' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('provider');
});

it('refuse une configuration à laquelle il manque un champ obligatoire', function (): void {
    // Enregistrer une configuration incomplète casserait l'authentification de
    // toute la plateforme au premier utilisateur suivant.
    administrateur();

    $this->putJson('/api/v1/admin/sms-provider', [
        'provider' => 'http',
        'config' => ['endpoint_url' => 'https://api.exemple.ci/sms'],
    ])->assertStatus(422)->assertJsonValidationErrors('config.payload_template');
});

it('refuse une adresse de passerelle non chiffrée', function (): void {
    administrateur();

    $this->putJson('/api/v1/admin/sms-provider', [
        'provider' => 'http',
        'config' => [
            'endpoint_url' => 'http://api.exemple.ci/sms',
            'payload_template' => '{"to":"{{destination}}"}',
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('config.endpoint_url');
});

it('journalise tout changement de configuration sans écrire le secret', function (): void {
    $admin = administrateur();

    $this->putJson('/api/v1/admin/sms-provider', configurationHttpValide())->assertOk();

    $entree = AuditLog::where('action', 'admin.sms_provider_changed')->sole();

    expect($entree->actor_id)->toBe($admin->id)
        ->and($entree->payload['provider'] ?? null)->toBe('http')
        ->and(json_encode($entree->payload))->not->toContain('cle-secrete-123');
});

it('envoie un message d\'essai vers un numéro choisi', function (): void {
    // Sans essai, un administrateur ne découvrirait une mauvaise configuration
    // qu'au moment où un utilisateur réel ne reçoit pas son code.
    Http::fake(['api.exemple.ci/*' => Http::response(['status' => 'ok'], 200)]);
    administrateur();
    $this->putJson('/api/v1/admin/sms-provider', configurationHttpValide())->assertOk();

    $this->postJson('/api/v1/admin/sms-provider/test', ['phone' => '0700000001'])
        ->assertOk()
        ->assertJsonPath('delivered', true);

    Http::assertSentCount(1);
});

it('rapporte l\'échec d\'un essai sans exposer le code envoyé', function (): void {
    Http::fake(['api.exemple.ci/*' => Http::response(['error' => 'quota'], 402)]);
    administrateur();
    $this->putJson('/api/v1/admin/sms-provider', configurationHttpValide())->assertOk();

    $reponse = $this->postJson('/api/v1/admin/sms-provider/test', ['phone' => '0700000001'])
        ->assertStatus(502)
        ->assertJsonPath('delivered', false);

    expect($reponse->json('message'))->toContain('402');
});

it('refuse un essai vers un numéro inexploitable', function (): void {
    administrateur();

    $this->postJson('/api/v1/admin/sms-provider/test', ['phone' => 'pas-un-numero'])
        ->assertStatus(422);
});

it('REFUSE une passerelle qui vise le réseau interne (anti-SSRF)', function (): void {
    // La passerelle reçoit le secret d'authentification à chaque envoi : la
    // pointer vers une IP interne l'exfiltrerait. Refusé à la saisie.
    administrateur();

    $this->putJson('/api/v1/admin/sms-provider', [
        'provider' => 'http',
        'config' => [
            'endpoint_url' => 'https://169.254.169.254/latest/meta-data/',
            'payload_template' => '{"to":"{{destination}}","text":"{{message}}"}',
            'auth_header' => 'Bearer cle-secrete-123',
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('config.endpoint_url');
});
