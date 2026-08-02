<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Envoi différé en cours (ST-0206).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $asset_id
 * @property string $doc_type
 * @property string $filename
 * @property int $byte_size
 * @property int $received_bytes
 * @property string $checksum
 * @property UploadStatus $status
 * @property string|null $failure_reason
 * @property int|null $document_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 */
class UploadSession extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => UploadStatus::class,
            'byte_size' => 'integer',
            'received_bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
