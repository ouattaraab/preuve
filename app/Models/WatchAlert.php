<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Veille active sur un identifiant.
 *
 * @property int $id
 * @property int $user_id
 * @property string $identifier_normalized
 * @property string $channel
 * @property bool $is_active
 * @property Carbon|null $last_triggered_at
 * @property Carbon|null $created_at
 */
class WatchAlert extends Model
{
    protected $fillable = [
        'user_id', 'identifier_normalized', 'channel', 'is_active', 'last_triggered_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_triggered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
