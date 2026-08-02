<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LifeStatus;
use App\Enums\TriggerType;
use App\Enums\TrustLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Une ligne par transition de statut d'un bien. Écrite exclusivement par
 * StatusTransitionService, dans la même transaction que l'entrée de chaîne
 * d'audit correspondante.
 *
 * Append-only, comme la chaîne d'audit : l'historique d'un bien est le
 * fondement du rapport détaillé payant et des exports d'arbitrage. Le rendre
 * modifiable permettrait de réécrire après coup la chronologie d'un vol ou
 * d'un transfert.
 */
class AssetStatusHistory extends Model
{
    protected $table = 'asset_status_history';

    public $timestamps = false;

    protected $fillable = [
        'asset_id', 'from_status', 'to_status', 'from_trust', 'to_trust',
        'trigger_type', 'actor_user_id', 'reason', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => LifeStatus::class,
            'to_status' => LifeStatus::class,
            'from_trust' => TrustLevel::class,
            'to_trust' => TrustLevel::class,
            'trigger_type' => TriggerType::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('L\'historique des statuts est append-only : mise à jour interdite.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('L\'historique des statuts est append-only : suppression interdite.');
        });
    }
}
