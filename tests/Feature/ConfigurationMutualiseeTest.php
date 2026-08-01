<?php

declare(strict_types=1);

it('utilise des drivers compatibles avec un hébergement mutualisé', function (): void {
    expect(config('queue.default'))->toBe('database')
        ->and(config('cache.default'))->toBe('database')
        ->and(config('session.driver'))->toBe('database');
});

it('ne déclare aucune connexion Redis dans les drivers par défaut', function (): void {
    expect(config('queue.default'))->not->toBe('redis')
        ->and(config('cache.default'))->not->toBe('redis');
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
