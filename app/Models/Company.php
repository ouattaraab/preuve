<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $owner_user_id
 * @property string $legal_name
 * @property string $rccm_number
 * @property string $validation_status
 * @property int $free_fleet_quota
 */
class Company extends Model
{
    protected $fillable = [
        'owner_user_id', 'legal_name', 'rccm_number', 'business_type',
        'validation_status', 'validated_at', 'free_fleet_quota',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['validated_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
