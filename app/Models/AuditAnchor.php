<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Registre local des ancrages externes. Append-only, comme la chaîne d'audit
 * elle-même.
 *
 * @property int $id
 * @property int|null $head_id
 * @property string $head_chain_hash
 * @property int $entry_count
 * @property array<string, mixed> $channels
 * @property string $status
 * @property Carbon $created_at
 */
class AuditAnchor extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'head_id', 'head_chain_hash', 'entry_count', 'channels', 'status', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException("Le registre d'ancrage est append-only : mise à jour interdite.");
        });

        static::deleting(function (): never {
            throw new RuntimeException("Le registre d'ancrage est append-only : suppression interdite.");
        });
    }
}
