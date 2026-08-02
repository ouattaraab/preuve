<?php

declare(strict_types=1);

use App\Enums\OtpPurpose;
use App\Services\Otp\ConfigurableOtpSender;
use App\Services\Otp\HttpOtpSender;
use App\Services\Otp\LogOtpSender;
use App\Services\Otp\SmsProviderRegistry;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->settings = app(SettingsRepository::class);
    $this->sender = app(ConfigurableOtpSender::class);
});

function configurerHttp(array $remplacements = []): void
{
    test()->settings->set('sms.provider', 'http');
    test()->settings->set('sms.config', [
        'endpoint_url' => 'https://api.exemple.ci/sms',
        'http_method' => 'POST',
        'payload_template' => '{"to":"{{destination}}","text":"{{message}}","from":"PREUVE"}',
        'message_template' => 'Votre code PREUVE est {{code}}.',
        ...$remplacements,
    ]);
}

it('catalogue les fournisseurs disponibles et leurs champs', function (): void {
    // L'espace administrateur construit son formulaire à partir de ce
    // catalogue : sans lui, chaque nouveau fournisseur imposerait une
    // livraison de l'interface.
    $catalogue = app(SmsProviderRegistry::class)->catalog();

    expect(array_column($catalogue, 'key'))->toContain('log', 'http')
        ->and($catalogue[array_search('http', array_column($catalogue, 'key'), true)]['fields'])
        ->toHaveKey('endpoint_url');
});

it('signale les champs secrets pour que l\'interface ne les réaffiche jamais', function (): void {
    $champs = app(SmsProviderRegistry::class)->fieldsFor('http');

    expect($champs['auth_header']['secret'])->toBeTrue()
        ->and($champs['endpoint_url']['secret'])->toBeFalse();
});

it('achemine par le fournisseur configuré', function (): void {
    Http::fake(['api.exemple.ci/*' => Http::response(['status' => 'ok'], 200)]);
    configurerHttp();

    $this->sender->send('+2250700000001', '123456', OtpPurpose::Login);

    Http::assertSent(fn (Request $requete): bool => $requete->url() === 'https://api.exemple.ci/sms'
        && $requete->method() === 'POST'
        && $requete['to'] === '+2250700000001'
        && $requete['text'] === 'Votre code PREUVE est 123456.');
});

it('bascule de fournisseur sans redéploiement', function (): void {
    Http::fake(['api.exemple.ci/*' => Http::response([], 200)]);

    $this->settings->set('sms.provider', 'log');
    expect($this->sender->resolve())->toBeInstanceOf(LogOtpSender::class);

    configurerHttp();
    expect($this->sender->fresh()->resolve())->toBeInstanceOf(HttpOtpSender::class);
});

it('joint l\'en-tête d\'authentification sans jamais l\'exposer', function (): void {
    Http::fake(['api.exemple.ci/*' => Http::response([], 200)]);
    configurerHttp();
    $this->settings->setSecret('sms.config.auth_header', 'Bearer cle-secrete');

    $this->sender->fresh()->send('+2250700000001', '123456', OtpPurpose::Login);

    Http::assertSent(fn (Request $requete): bool => $requete->hasHeader('Authorization', 'Bearer cle-secrete'));
});

it('refuse une adresse non chiffrée hors développement', function (): void {
    // Le code transiterait en clair sur le réseau : c'est le secret même que
    // l'OTP protège.
    configurerHttp(['endpoint_url' => 'http://api.exemple.ci/sms']);
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => $this->sender->fresh()->send('+2250700000001', '123456', OtpPurpose::Login))
        ->toThrow(RuntimeException::class);
});

it('échoue explicitement quand le fournisseur répond en erreur', function (): void {
    // Un envoi silencieusement perdu laisserait l'utilisateur attendre un SMS
    // qui n'arrivera jamais, sans que personne ne le sache.
    Http::fake(['api.exemple.ci/*' => Http::response(['error' => 'quota'], 402)]);
    configurerHttp();

    expect(fn () => $this->sender->send('+2250700000001', '123456', OtpPurpose::Login))
        ->toThrow(RuntimeException::class);
});

it('ne consigne jamais le code dans les journaux du transporteur', function (): void {
    Http::fake(['api.exemple.ci/*' => Http::response(['error' => 'quota'], 402)]);
    configurerHttp();

    try {
        $this->sender->send('+2250700000001', '123456', OtpPurpose::Login);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('123456');
    }
});

it('refuse de partir quand le fournisseur n\'est pas configuré', function (): void {
    $this->settings->set('sms.provider', 'http');
    $this->settings->set('sms.config', []);

    expect(fn () => $this->sender->fresh()->send('+2250700000001', '123456', OtpPurpose::Login))
        ->toThrow(RuntimeException::class);
});

it('refuse un fournisseur inconnu plutôt que de retomber en silence sur les journaux', function (): void {
    // Un repli silencieux sur LogOtpSender en production écrirait les codes en
    // clair dans un fichier de log.
    $this->settings->set('sms.provider', 'fournisseur-fantome');

    expect(fn () => $this->sender->fresh()->resolve())->toThrow(RuntimeException::class);
});

it('retombe sur les journaux tant qu\'aucun fournisseur n\'a été choisi', function (): void {
    // État initial d'une installation : le développement doit fonctionner sans
    // configuration, et LogOtpSender refuse de lui-même la production.
    expect($this->sender->resolve())->toBeInstanceOf(LogOtpSender::class);
});
