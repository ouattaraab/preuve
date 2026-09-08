<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $buyer_name
 * @property string|null $buyer_email
 * @property string|null $buyer_phone
 * @property PaymentPurpose $purpose
 * @property int|null $related_id
 * @property int $amount_fcfa
 * @property PaymentProvider $provider
 * @property string|null $provider_ref
 * @property PaymentStatus $status
 * @property Carbon|null $paid_at
 */
class Payment extends Model
{
    protected $fillable = [
        'user_id', 'buyer_name', 'buyer_email', 'buyer_phone', 'purpose',
        'related_id', 'amount_fcfa', 'provider', 'provider_ref', 'status', 'paid_at',
    ];

    /**
     * L'identité de l'acheteur invité ne sort JAMAIS par sérialisation : le
     * propriétaire d'un bien ne doit pas savoir qui a acheté un rapport sur
     * lui, même quand celui-ci a payé (règle métier absolue n° 4).
     */
    protected $hidden = ['buyer_name', 'buyer_email', 'buyer_phone'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'provider' => PaymentProvider::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
        ];
    }
}
