<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Normalisation et validation des identifiants de biens.
 *
 * La normalisation précède TOUT contrôle d'unicité : sans elle,
 * « AA-123-BC » et « aa123bc » désigneraient deux biens distincts et la
 * règle « un identifiant = un enregistrement actif » serait contournable.
 */
final class IdentifierNormalizer
{
    /** Lettres interdites dans un VIN car confondables avec 1 et 0. */
    private const VIN_FORBIDDEN_LETTERS = ['I', 'O', 'Q'];

    /** Poids appliqués aux 17 positions d'un VIN pour le chiffre de contrôle. */
    private const VIN_WEIGHTS = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];

    /** Valeur numérique de chaque lettre pour le calcul du chiffre de contrôle. */
    private const VIN_LETTER_VALUES = [
        'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'F' => 6, 'G' => 7, 'H' => 8,
        'J' => 1, 'K' => 2, 'L' => 3, 'M' => 4, 'N' => 5, 'P' => 7, 'R' => 9,
        'S' => 2, 'T' => 3, 'U' => 4, 'V' => 5, 'W' => 6, 'X' => 7, 'Y' => 8, 'Z' => 9,
    ];

    /** Majuscules, sans espace ni séparateur. */
    public function normalize(string $raw): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($raw))) ?? '';
    }

    public function isValidVin(string $vin): bool
    {
        $vin = $this->normalize($vin);

        if (mb_strlen($vin) !== 17) {
            return false;
        }

        foreach (self::VIN_FORBIDDEN_LETTERS as $letter) {
            if (str_contains($vin, $letter)) {
                return false;
            }
        }

        $sum = 0;
        foreach (str_split($vin) as $position => $character) {
            $value = is_numeric($character)
                ? (int) $character
                : (self::VIN_LETTER_VALUES[$character] ?? null);

            if ($value === null) {
                return false;
            }

            $sum += $value * self::VIN_WEIGHTS[$position];
        }

        $remainder = $sum % 11;
        $expected = $remainder === 10 ? 'X' : (string) $remainder;

        return $vin[8] === $expected;
    }

    /** Validation Luhn sur 15 chiffres. */
    public function isValidImei(string $imei): bool
    {
        $imei = $this->normalize($imei);

        if (! preg_match('/^\d{15}$/', $imei)) {
            return false;
        }

        $sum = 0;
        foreach (str_split($imei) as $position => $digit) {
            $digit = (int) $digit;

            if ($position % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    /** @return 'vin'|'imei'|'plate'|'serial'|'lot_number'|null */
    public function detectType(string $raw): ?string
    {
        $value = $this->normalize($raw);

        if ($value === '') {
            return null;
        }

        if ($this->isValidImei($value)) {
            return 'imei';
        }

        if ($this->isValidVin($value)) {
            return 'vin';
        }

        // Plaques ivoiriennes : ancien format 1234AB01, nouveau format AA123BC
        if (preg_match('/^(\d{3,4}[A-Z]{2}\d{2}|[A-Z]{2}\d{3}[A-Z]{2})$/', $value) === 1) {
            return 'plate';
        }

        if (preg_match('/^[A-Z]{1,4}\d{3,10}$/', $value) === 1) {
            return 'lot_number';
        }

        if (mb_strlen($value) >= 6) {
            return 'serial';
        }

        return null;
    }
}
