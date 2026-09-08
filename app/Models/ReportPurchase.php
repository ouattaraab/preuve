<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Accès acheté à un rapport détaillé.
 *
 * @property int $id
 * @property int $asset_id
 * @property int $payment_id
 * @property int|null $user_id
 * @property string $access_token
 * @property Carbon $expires_at
 * @property Carbon|null $first_access_at
 * @property int $access_count
 */
class ReportPurchase extends Model
{
    protected $fillable = [
        'asset_id', 'payment_id', 'user_id', 'access_token',
        'expires_at', 'first_access_at', 'access_count',
    ];

    /**
     * Le jeton ne sort que dans la réponse qui le crée : il ouvre l'accès au
     * rapport, une sérialisation ultérieure le ferait fuir dans des journaux
     * ou des réponses de liste.
     */
    protected $hidden = ['access_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'first_access_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function isUsable(): bool
    {
        return $this->expires_at->isFuture();
    }
}
