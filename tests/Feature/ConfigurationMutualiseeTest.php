<?php

declare(strict_types=1);

// La contrainte porte sur ce qui est DÉPLOYÉ, pas sur l'environnement de
// test : phpunit.xml surcharge légitimement les drivers (sync/array) pour
// isoler et accélérer les tests. On vérifie donc le modèle de déploiement.
it('déclare des drivers compatibles avec un hébergement mutualisé dans le modèle de déploiement', function (): void {
    $modele = file_get_contents(base_path('.env.example'));

    expect($modele)->toContain('QUEUE_CONNECTION=database')
        ->and($modele)->toContain('CACHE_STORE=database')
        ->and($modele)->toContain('SESSION_DRIVER=database')
        ->and($modele)->toContain('DB_CONNECTION=mariadb')
        ->and($modele)->toContain('FILESYSTEM_DISK=r2');
});

it('ne déclare aucune connexion Redis dans le modèle de déploiement', function (): void {
    expect(file_get_contents(base_path('.env.example')))->not->toContain('=redis');
});

// Verrouille l'intention : retirer ces surcharges ferait écrire chaque test
// en base et casserait l'exécution synchrone des travaux en file.
it('conserve les surcharges de test qui isolent la suite de la configuration de production', function (): void {
    $phpunit = file_get_contents(base_path('phpunit.xml'));

    expect($phpunit)->toContain('QUEUE_CONNECTION')
        ->and($phpunit)->toContain('CACHE_STORE')
        ->and($phpunit)->toContain('SESSION_DRIVER');
});

it('expose les paramètres métier de PREUVE', function (): void {
    expect(config('preuve.lookup_rate_limit.anonymous_per_hour'))->toBe(10)
        ->and(config('preuve.provisional_days'))->toBe(30)
        ->and(config('preuve.theft_consolidation_days'))->toBe(15)
        ->and(config('preuve.otp.length'))->toBe(6)
        ->and(config('preuve.otp.ttl_minutes'))->toBe(5)
        ->and(config('preuve.otp.max_attempts'))->toBe(3);
});

it('déclare le disque R2 pour les documents', function (): void {
    expect(config('filesystems.disks.r2.driver'))->toBe('s3');
});
