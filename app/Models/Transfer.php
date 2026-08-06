<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LifeStatus;
use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $asset_id
 * @property int $from_user_id
 * @property int|null $to_user_id
 * @property string $to_phone
 * @property TransferStatus $status
 * @property LifeStatus $previous_life_status
 * @property Carbon|null $seller_otp_at
 * @property Carbon|null $buyer_otp_at
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 * @property string|null $to_email
 * @property string|null $invite_token
 * @property int|null $created_asset_id
 */
class Transfer extends Model
{
    protected $fillable = [
        'asset_id', 'from_user_id', 'to_user_id', 'to_phone', 'to_email', 'invite_token', 'status',
        'previous_life_status', 'seller_otp_at', 'buyer_otp_at', 'expires_at',
        'completed_at', 'created_asset_id',
    ];

    /**
     * Les coordonnées de l'acheteur ne sortent JAMAIS par une sérialisation
     * automatique : elles désignent une personne qui n'a peut-être pas encore
     * de compte, et n'ont rien à faire dans une réponse publique.
     *
     * L'ADRESSE EST AUSSI CACHÉE QUE LE NUMÉRO. L'ajouter sans l'inscrire ici
     * l'aurait exposée au premier `->toJson()` d'un contrôleur — et une adresse
     * divulguée vaut un numéro divulgué.
     *
     * LE JETON D'INVITATION L'EST TOUT AUTANT : il ouvre la page qui décrit le
     * bien cédé. Le laisser sortir dans une réponse rendue au vendeur, ou pire
     * dans une liste, le mettrait à portée de qui n'a pas reçu le courriel.
     */
    protected $hidden = ['to_phone', 'to_email', 'invite_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'previous_life_status' => LifeStatus::class,
            'seller_otp_at' => 'datetime',
            'buyer_otp_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** Vrai si les deux parties ont confirmé par leur code. */
    public function bothConfirmed(): bool
    {
        return $this->seller_otp_at !== null && $this->buyer_otp_at !== null;
    }
}
