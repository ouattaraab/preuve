<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de comptes.
 *
 * Reprise du squelette Laravel, qui produisait des `name`, `password` et
 * `remember_token` — trois colonnes qui n'existent pas ici : PREUVE
 * s'authentifie par téléphone et code OTP, sans mot de passe au MVP. La
 * fabrique d'origine échouait donc à la première utilisation.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Format ivoirien à dix chiffres, préfixé E.164 comme le fait
            // OtpService à la normalisation.
            'phone' => '+2250'.fake()->numerify('#########'),
            'phone_verified_at' => now(),
            'full_name' => fake()->name(),
            'account_type' => 'individual',
            'role' => UserRole::User,
            'kyc_status' => KycStatus::None->value,
            'locale' => 'fr',
            'status' => 'active',
        ];
    }

    /** Compte dont l'identité a été vérifiée : débloque F2, réclamation et transfert. */
    public function kycVerified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kyc_status' => KycStatus::Verified->value,
            'kyc_verified_at' => now()->subMonths(2),
        ]);
    }

    public function agent(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => UserRole::Agent]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => UserRole::Admin]);
    }
}
