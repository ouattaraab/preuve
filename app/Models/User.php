<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KycStatus;
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
 * @property Carbon|null $kyc_verified_at
 * @property string|null $kyc_id_number_hash
 * @property string $locale
 * @property string $status
 * @property array<string, bool>|null $notification_preferences
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

    /**
     * Reprend le défaut de la colonne : une instance fraîchement créée n'a pas
     * relu la base, et `role` y serait nul en mémoire — un test de rôle sur
     * null échouerait au lieu de refuser proprement l'accès.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'role' => 'user',
        'kyc_status' => 'none',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'notification_preferences' => 'array',
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

    /**
     * `kyc_status` reste une colonne de chaîne pour rester conforme au schéma
     * de référence ; cet accesseur en donne la lecture typée sans imposer un
     * cast qui changerait la valeur écrite.
     */
    public function kycStatus(): KycStatus
    {
        $statut = $this->getAttribute('kyc_status');

        // Défensif : une instance issue d'une requête partielle (select
        // restreint) n'a pas forcément la colonne. Une identité inconnue vaut
        // « non vérifiée », jamais une erreur — sur ce chemin, une exception
        // bloquerait une vérification de droits.
        return is_string($statut) ? (KycStatus::tryFrom($statut) ?? KycStatus::None) : KycStatus::None;
    }

    public function hasVerifiedIdentity(): bool
    {
        return $this->kycStatus() === KycStatus::Verified;
    }
}
