<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EvidenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $claim_id
 * @property string $party
 * @property EvidenceType $evidence_type
 * @property string|null $file_ref
 * @property string|null $file_sha256
 * @property string|null $document_date
 * @property int $weight_applied
 * @property string|null $agent_note
 */
class ClaimEvidence extends Model
{
    /**
     * Explicite : « evidence » étant indénombrable en anglais, Laravel en
     * déduirait la table `claim_evidence` au singulier, alors que le schéma de
     * référence nomme `claim_evidences`.
     */
    protected $table = 'claim_evidences';

    protected $fillable = [
        'claim_id', 'party', 'evidence_type', 'file_ref', 'file_sha256',
        'document_date', 'weight_applied', 'agent_note',
    ];

    /** La clé objet donne accès à la pièce elle-même : jamais de sérialisation automatique. */
    protected $hidden = ['file_ref'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['evidence_type' => EvidenceType::class];
    }

    /** @return BelongsTo<Claim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }
}
