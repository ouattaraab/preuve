<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $owner_user_id
 * @property string $legal_name
 * @property string $rccm_number
 * @property string $validation_status
 * @property int $free_fleet_quota
 * @property SubscriptionStatus $subscription_status
 * @property Carbon|null $subscription_due_at
 * @property Carbon|null $last_dunning_at
 * @property int $dunning_count
 */
class Company extends Model
{
    protected $fillable = [
        'owner_user_id', 'legal_name', 'rccm_number', 'business_type',
        'validation_status', 'validated_at', 'free_fleet_quota',
        'subscription_status', 'subscription_due_at', 'last_dunning_at', 'dunning_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
            'subscription_status' => SubscriptionStatus::class,
            'subscription_due_at' => 'datetime',
            'last_dunning_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
