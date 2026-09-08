<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * L'API répond en JSON, toujours.
 *
 * Constaté en production le 03/08/2026 : un `curl` sans en-tête `Accept`
 * recevait une page HTML de 500 au lieu d'un 401. Laravel suivait alors le
 * chemin web par défaut — redirection vers une route `login` inexistante dans
 * une API. Un intégrateur y lit une panne serveur là où il n'a qu'oublié son
 * jeton.
 */
it('refuse en 401 JSON, même sans en-tête Accept', function (): void {
    $reponse = $this->call('PUT', '/api/v1/admin/captcha-provider', [], [], [], [], json_encode([
        'site_key' => '0xTEST',
    ]) ?: '');

    expect($reponse->status())->toBe(401)
        ->and($reponse->headers->get('Content-Type'))->toContain('json')
        ->and($reponse->getContent())->not->toContain('<!DOCTYPE html>');
});

it('refuse en 403 JSON un porteur qui n\'est pas administrateur', function (): void {
    // Numéro improbable et unique : ce fichier ne truque pas la base, et un
    // numéro fixe échouerait au second passage.
    Sanctum::actingAs(User::create(['phone' => '+22507'.random_int(10000000, 99999999)]));

    $reponse = $this->call('GET', '/api/v1/admin/captcha-provider');

    expect($reponse->status())->toBe(403)
        ->and($reponse->headers->get('Content-Type'))->toContain('json');
});

it('rend une route inconnue en JSON', function (): void {
    $reponse = $this->call('GET', '/api/v1/nexistepas');

    expect($reponse->status())->toBe(404)
        ->and($reponse->headers->get('Content-Type'))->toContain('json')
        ->and($reponse->getContent())->not->toContain('<!DOCTYPE html>');
});
