<?php

declare(strict_types=1);

namespace App\Services\Kyc;

/**
 * Champs extraits d'une pièce d'identité.
 *
 * Le numéro de pièce est porté ici EN CLAIR, et c'est le seul endroit où il
 * existe sous cette forme : il est haché immédiatement par KycService et n'est
 * jamais écrit en base, ni dans un journal, ni dans une entrée d'audit (règle
 * métier absolue n° 8). Cet objet ne doit donc jamais être sérialisé tel quel.
 */
final class IdentityExtraction
{
    public function __construct(
        public readonly ?string $documentNumber = null,
        public readonly ?string $fullName = null,
        public readonly ?string $birthDate = null,
        public readonly ?string $expiryDate = null,
        /** Confiance rendue par le fournisseur, de 0 à 100. */
        public readonly ?int $confidence = null,
        /** Vrai si l'extraction n'a rien pu lire — l'agent tranchera seul. */
        public readonly bool $failed = false,
    ) {}

    public static function unreadable(): self
    {
        return new self(failed: true);
    }

    /**
     * Forme minimisée, destinée au stockage : le numéro de pièce en est
     * délibérément absent (Loi 2013-450, minimisation). Ne restent que les
     * champs qui permettent à un agent de rapprocher le dossier du compte.
     *
     * @return array<string, mixed>
     */
    public function toMinimizedPayload(): array
    {
        return array_filter([
            'full_name' => $this->fullName,
            'birth_date' => $this->birthDate,
            'expiry_date' => $this->expiryDate,
            'confidence' => $this->confidence,
            'extraction_failed' => $this->failed ?: null,
        ], fn (mixed $valeur): bool => $valeur !== null);
    }
}
