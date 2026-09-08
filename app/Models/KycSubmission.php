<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un dossier de vérification d'identité.
 *
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property string $id_front_ref
 * @property string $id_back_ref
 * @property string $selfie_ref
 * @property array<string, mixed>|null $ocr_payload
 * @property int|null $liveness_score
 * @property list<array{label: string, ref: string, sha256: string}>|null $liveness_frames
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_reason
 * @property Carbon|null $created_at
 */
class KycSubmission extends Model
{
    protected $fillable = [
        'user_id', 'status', 'id_front_ref', 'id_back_ref', 'selfie_ref',
        'id_front_sha256', 'id_back_sha256', 'selfie_sha256',
        'ocr_payload', 'liveness_score', 'liveness_frames', 'reviewed_by', 'reviewed_at', 'review_reason',
    ];

    /**
     * Les clés objet donnent accès à des images de pièce d'identité, et
     * l'extraction OCR en porte le contenu : ni l'une ni l'autre ne sortent par
     * une sérialisation automatique.
     */
    protected $hidden = ['id_front_ref', 'id_back_ref', 'selfie_ref', 'ocr_payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ocr_payload' => 'array',
            'liveness_frames' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
