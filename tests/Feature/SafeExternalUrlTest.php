<?php

declare(strict_types=1);

use App\Rules\SafeExternalUrl;

/**
 * Anti-SSRF sur les URLs configurables (audit de sécurité).
 *
 * En environnement de test, la règle applique le schéma et le rejet des IP
 * LITTÉRALES internes/réservées, sans jamais résoudre un nom (les tests ne
 * sortent pas sur le réseau). C'est exactement le vecteur direct — une IP de
 * métadonnées cloud ou de loopback saisie telle quelle.
 */
function refus(string $url): array
{
    $erreurs = [];
    (new SafeExternalUrl)->validate('url', $url, function (string $m) use (&$erreurs): void {
        $erreurs[] = $m;
    });

    return $erreurs;
}

it('REJETTE les métadonnées cloud (169.254.169.254)', function (): void {
    expect(refus('https://169.254.169.254/latest/meta-data/'))->not->toBeEmpty();
});

it('REJETTE le loopback', function (): void {
    expect(refus('https://127.0.0.1/interne'))->not->toBeEmpty()
        ->and(refus('https://[::1]/interne'))->not->toBeEmpty();
});

it('REJETTE les plages privées RFC 1918', function (): void {
    expect(refus('https://10.0.0.5/x'))->not->toBeEmpty()
        ->and(refus('https://192.168.1.1/x'))->not->toBeEmpty()
        ->and(refus('https://172.16.0.1/x'))->not->toBeEmpty();
});

it('REJETTE localhost par son nom', function (): void {
    expect(refus('https://localhost/x'))->not->toBeEmpty();
});

it('REJETTE une adresse non chiffrée', function (): void {
    // En test, http est toléré sur un nom (dev), mais pas sur une IP interne :
    // c'est le schéma https qui est exigé hors dev — on vérifie qu'une URL
    // sans schéma exploitable est refusée.
    expect(refus('ftp://exemple.ci/x'))->not->toBeEmpty();
});

it('LAISSE PASSER un nom d\'hôte public ordinaire', function (): void {
    // Pas de résolution DNS en test : un nom qui n'est pas une IP interne
    // passe. La résolution complète n'a lieu qu'en production.
    expect(refus('https://api.exemple.ci/sms'))->toBeEmpty();
});
