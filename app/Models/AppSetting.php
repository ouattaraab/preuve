<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property bool $is_secret
 * @property int|null $updated_by
 * @property Carbon|null $updated_at
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value', 'is_secret', 'updated_by'];

    /**
     * `value` n'est volontairement pas casté : une valeur secrète y est
     * stockée chiffrée, une valeur ordinaire en JSON. C'est SettingsRepository
     * qui tranche, à un seul endroit — un cast automatique tenterait de
     * décoder un chiffré comme du JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }
}
