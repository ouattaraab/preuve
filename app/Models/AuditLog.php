<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Journal inaltérable. Toute écriture passe par AuditChain::append().
 * Les mises à jour et suppressions sont interdites au niveau du modèle.
 *
 * @property int $id
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property string $action
 * @property string $entity_type
 * @property int $entity_id
 * @property array<string, mixed>|null $payload
 * @property string $record_hash
 * @property string|null $prev_hash
 * @property string $chain_hash
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = [
        'actor_type', 'actor_id', 'action', 'entity_type', 'entity_id',
        'payload', 'record_hash', 'prev_hash', 'chain_hash', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException("La chaîne d'audit est append-only : mise à jour interdite.");
        });

        static::deleting(function (): never {
            throw new RuntimeException("La chaîne d'audit est append-only : suppression interdite.");
        });
    }
}
