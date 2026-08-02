<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LifeStatus;
use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $asset_id
 * @property int $from_user_id
 * @property int|null $to_user_id
 * @property string $to_phone
 * @property TransferStatus $status
 * @property LifeStatus $previous_life_status
 * @property Carbon|null $seller_otp_at
 * @property Carbon|null $buyer_otp_at
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 * @property int|null $created_asset_id
 */
class Transfer extends Model
{
    protected $fillable = [
        'asset_id', 'from_user_id', 'to_user_id', 'to_phone', 'status',
        'previous_life_status', 'seller_otp_at', 'buyer_otp_at', 'expires_at',
        'completed_at', 'created_asset_id',
    ];

    /**
     * Le téléphone de l'acheteur ne sort jamais par une sérialisation
     * automatique : il désigne une personne qui n'a peut-être pas encore de
     * compte, et n'a rien à faire dans une réponse publique.
     */
    protected $hidden = ['to_phone'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'previous_life_status' => LifeStatus::class,
            'seller_otp_at' => 'datetime',
            'buyer_otp_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** Vrai si les deux parties ont confirmé par leur code. */
    public function bothConfirmed(): bool
    {
        return $this->seller_otp_at !== null && $this->buyer_otp_at !== null;
    }
}
