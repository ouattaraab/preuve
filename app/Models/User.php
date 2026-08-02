<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Pas de mot de passe au MVP : l'authentification repose entièrement sur un
 * code OTP envoyé au téléphone (voir OtpService), échangé contre un jeton
 * Sanctum.
 *
 * @property int $id
 * @property string $phone
 * @property Carbon|null $phone_verified_at
 * @property string|null $email
 * @property string|null $full_name
 * @property string $account_type
 * @property UserRole $role
 * @property string $kyc_status
 * @property string $locale
 * @property string $status
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    /**
     * `role` est délibérément absent : une élévation de privilège ne doit
     * jamais pouvoir passer par une assignation de masse depuis une requête.
     * Elle se fait explicitement, par la commande dédiée.
     */
    protected $fillable = [
        'phone', 'email', 'full_name', 'account_type', 'locale',
    ];

    protected $hidden = [
        'kyc_id_number_hash', 'kyc_ocr_payload',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'kyc_verified_at' => 'datetime',
            'kyc_ocr_payload' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }
}
