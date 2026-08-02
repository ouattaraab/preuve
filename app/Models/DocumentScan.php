<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScanOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Trace de mesure d'un scan de carte grise ou de facture (ST-0202).
 *
 * @property int $id
 * @property int $user_id
 * @property string $doc_type
 * @property string $provider
 * @property string|null $proposed_hash
 * @property string|null $proposed_type
 * @property int|null $confidence
 * @property int|null $duration_ms
 * @property ScanOutcome $outcome
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 */
class DocumentScan extends Model
{
    /** Ligne de mesure : elle naît et ne change qu'une fois, à l'usage. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'outcome' => ScanOutcome::class,
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
