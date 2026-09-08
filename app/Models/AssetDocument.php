<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentReviewStatus;
use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $asset_id
 * @property int $uploaded_by
 * @property DocumentType $doc_type
 * @property string $file_ref
 * @property string $file_sha256
 * @property array<string, mixed>|null $ocr_payload
 * @property DocumentReviewStatus $review_status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_reason
 * @property Carbon|null $created_at
 */
class AssetDocument extends Model
{
    protected $fillable = [
        'asset_id', 'uploaded_by', 'doc_type', 'file_ref', 'file_sha256',
        'ocr_payload', 'review_status', 'reviewed_by', 'reviewed_at', 'review_reason',
    ];

    /**
     * La clé objet et l'extraction OCR ne sortent jamais vers un consultant :
     * la première donne accès à la pièce, la seconde en contient le contenu.
     */
    protected $hidden = ['file_ref', 'ocr_payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'doc_type' => DocumentType::class,
            'review_status' => DocumentReviewStatus::class,
            'ocr_payload' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
