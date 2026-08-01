<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActorType;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Journal inaltérable. Toute écriture passe par AuditChain::append().
 * Les mises à jour et suppressions sont interdites au niveau du modèle.
 */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = [
        'actor_type', 'actor_id', 'action', 'entity_type', 'entity_id',
        'payload', 'payload_hash', 'prev_hash', 'chain_hash', 'created_at',
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
