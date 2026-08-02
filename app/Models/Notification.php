<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property NotificationType $type
 * @property int|null $asset_id
 * @property int|null $claim_id
 * @property string $title
 * @property string $body
 * @property array<string, mixed>|null $payload
 * @property string $channel
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
class Notification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'type', 'asset_id', 'claim_id', 'title', 'body',
        'payload', 'channel', 'read_at', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'payload' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
