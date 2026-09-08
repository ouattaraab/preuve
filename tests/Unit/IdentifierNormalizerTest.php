<?php

declare(strict_types=1);

use App\Services\IdentifierNormalizer;

/**
 * @property IdentifierNormalizer $normalizer
 */
beforeEach(function (): void {
    $this->normalizer = new IdentifierNormalizer;
});

it('met en majuscules et retire espaces et tirets', function (): void {
    expect($this->normalizer->normalize(' aa-123 bc '))->toBe('AA123BC')
        ->and($this->normalizer->normalize('1m8-gdm9a_kp042788'))->toBe('1M8GDM9AKP042788');
});

it('valide un VIN dont le chiffre de contrôle est correct', function (): void {
    // VIN de référence publique, chiffre de contrôle X en position 9
    expect($this->normalizer->isValidVin('1M8GDM9AXKP042788'))->toBeTrue();
});

it('rejette un VIN dont le chiffre de contrôle est faux', function (): void {
    expect($this->normalizer->isValidVin('1M8GDM9A0KP042788'))->toBeFalse();
});

it('rejette un VIN de longueur invalide', function (): void {
    expect($this->normalizer->isValidVin('1M8GDM9AXKP04278'))->toBeFalse();
});

it('rejette un VIN contenant les lettres interdites I, O ou Q', function (): void {
    expect($this->normalizer->isValidVin('1M8GDM9AXKP04278I'))->toBeFalse();
});

it('valide un IMEI conforme à Luhn', function (): void {
    expect($this->normalizer->isValidImei('490154203237518'))->toBeTrue();
});

it('rejette un IMEI non conforme à Luhn', function (): void {
    expect($this->normalizer->isValidImei('490154203237519'))->toBeFalse();
});

it('rejette un IMEI qui ne fait pas quinze chiffres', function (): void {
    expect($this->normalizer->isValidImei('49015420323751'))->toBeFalse();
});

it('détecte le type d\'identifiant à partir de sa forme', function (): void {
    expect($this->normalizer->detectType('490154203237518'))->toBe('imei')
        ->and($this->normalizer->detectType('1M8GDM9AXKP042788'))->toBe('vin')
        ->and($this->normalizer->detectType('AA-123-BC'))->toBe('plate')
        ->and($this->normalizer->detectType('1234 AB 01'))->toBe('plate');
});

it('renvoie null quand aucun type ne correspond', function (): void {
    expect($this->normalizer->detectType('?'))->toBeNull();
});

it('normalise avant toute détection de type', function (): void {
    expect($this->normalizer->detectType(' 490 154 203 237 518 '))->toBe('imei');
});
