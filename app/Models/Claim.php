<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClaimDecision;
use App\Enums\ClaimStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $asset_id
 * @property int $claimant_user_id
 * @property ClaimStatus $status
 * @property Carbon|null $respondent_deadline
 * @property ClaimDecision|null $decision
 * @property string|null $decision_reason
 * @property int|null $claimant_score
 * @property int|null $respondent_score
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property int|null $appeal_of
 * @property string|null $export_sha256
 * @property string|null $export_ref
 * @property Carbon|null $created_at
 */
class Claim extends Model
{
    protected $fillable = [
        'asset_id', 'claimant_user_id', 'status', 'fee_payment_id', 'fee_refunded',
        'respondent_deadline', 'decision', 'decision_reason', 'claimant_score',
        'respondent_score', 'decided_by', 'decided_at', 'appeal_of',
        'export_sha256', 'export_ref',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ClaimStatus::class,
            'decision' => ClaimDecision::class,
            'fee_refunded' => 'boolean',
            'respondent_deadline' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Réclamant, dont la présence est garantie par la clé étrangère : un
     * dossier sans réclamant n'existe pas.
     *
     * @throws \RuntimeException si le compte a disparu
     */
    public function claimantOrFail(): User
    {
        $reclamant = User::find($this->claimant_user_id);

        if (! $reclamant instanceof User) {
            throw new \RuntimeException('Le réclamant du dossier #'.$this->id.' est introuvable.');
        }

        return $reclamant;
    }

    /** @return HasMany<ClaimEvidence, $this> */
    public function evidences(): HasMany
    {
        return $this->hasMany(ClaimEvidence::class);
    }
}
