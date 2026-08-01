<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

// NB : le trait Laravel\Sanctum\HasApiTokens sera ajouté par la tâche qui
// installe Sanctum (`php artisan install:api`, plan lot 1, étape "Installer
// et configurer Sanctum"). Le paquet n'est pas encore une dépendance du
// projet à ce stade (tâche 4) ; l'ajouter maintenant ferait échouer
// `vendor/bin/phpstan analyse` (trait.notFound, non ignorable).
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

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
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'kyc_verified_at' => 'datetime',
            'kyc_ocr_payload' => 'array',
        ];
    }
}
