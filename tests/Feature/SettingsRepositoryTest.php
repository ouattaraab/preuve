<?php

declare(strict_types=1);

use App\Models\AppSetting;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->settings = app(SettingsRepository::class);
});

it('restitue une valeur ordinaire telle qu\'elle a été écrite', function (): void {
    $this->settings->set('sms.provider', 'log');
    $this->settings->set('sms.config', ['url' => 'https://api.exemple.ci/send', 'method' => 'POST']);

    expect($this->settings->get('sms.provider'))->toBe('log')
        ->and($this->settings->get('sms.config'))->toBe([
            'url' => 'https://api.exemple.ci/send',
            'method' => 'POST',
        ]);
});

it('renvoie la valeur par défaut quand le réglage n\'existe pas', function (): void {
    expect($this->settings->get('sms.provider', 'log'))->toBe('log')
        ->and($this->settings->get('inexistant'))->toBeNull();
});

it('chiffre les valeurs secrètes au repos', function (): void {
    // Une clé d'API de fournisseur SMS permet d'envoyer des messages aux frais
    // de la plateforme : en clair dans une table, un dump de base suffirait.
    $this->settings->setSecret('sms.api_key', 'cle-tres-secrete-123');

    $brut = DB::table('app_settings')->where('key', 'sms.api_key')->value('value');

    expect($brut)->not->toContain('cle-tres-secrete-123')
        ->and($this->settings->get('sms.api_key'))->toBe('cle-tres-secrete-123');
});

it('marque explicitement les réglages secrets', function (): void {
    $this->settings->setSecret('sms.api_key', 'cle');
    $this->settings->set('sms.provider', 'log');

    expect(AppSetting::where('key', 'sms.api_key')->sole()->is_secret)->toBeTrue()
        ->and(AppSetting::where('key', 'sms.provider')->sole()->is_secret)->toBeFalse();
});

it('remplace une valeur sans créer de doublon', function (): void {
    $this->settings->set('sms.provider', 'log');
    $this->settings->set('sms.provider', 'http');

    expect(AppSetting::where('key', 'sms.provider')->count())->toBe(1)
        ->and($this->settings->get('sms.provider'))->toBe('http');
});

it('retient l\'auteur de la dernière modification', function (): void {
    $this->settings->set('sms.provider', 'http', updatedBy: 42);

    expect(AppSetting::where('key', 'sms.provider')->sole()->updated_by)->toBe(42);
});

it('sert la valeur depuis un cache mémoire après la première lecture', function (): void {
    // Le fournisseur SMS est relu à chaque envoi de code : sans cache, chaque
    // demande d'OTP coûterait une requête supplémentaire.
    $this->settings->set('sms.provider', 'http');
    $this->settings->get('sms.provider');

    DB::table('app_settings')->where('key', 'sms.provider')->update(['value' => '"log"']);

    expect($this->settings->get('sms.provider'))->toBe('http')
        ->and($this->settings->fresh()->get('sms.provider'))->toBe('log');
});

it('vide son cache dès qu\'une écriture a lieu', function (): void {
    $this->settings->set('sms.provider', 'log');
    expect($this->settings->get('sms.provider'))->toBe('log');

    $this->settings->set('sms.provider', 'http');

    expect($this->settings->get('sms.provider'))->toBe('http');
});

it('supprime un réglage', function (): void {
    $this->settings->set('sms.provider', 'http');
    $this->settings->forget('sms.provider');

    expect($this->settings->get('sms.provider'))->toBeNull()
        ->and(AppSetting::count())->toBe(0);
});

it('ne restitue jamais un secret illisible en cas de rotation de la clé', function (): void {
    // Une APP_KEY tournée rend les secrets indéchiffrables : mieux vaut une
    // valeur nulle — donc un fournisseur qui refuse de partir — qu'une chaîne
    // chiffrée envoyée telle quelle à une API tierce.
    $this->settings->setSecret('sms.api_key', 'cle');

    DB::table('app_settings')->where('key', 'sms.api_key')->update(['value' => 'chiffre-illisible']);

    expect($this->settings->fresh()->get('sms.api_key'))->toBeNull();
});
