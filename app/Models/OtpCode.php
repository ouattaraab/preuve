<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $destination
 * @property OtpChannel $channel
 * @property OtpPurpose $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $locked_until
 * @property Carbon|null $created_at
 */
class OtpCode extends Model
{
    protected $fillable = [
        'destination', 'channel', 'purpose', 'code_hash',
        'attempts', 'max_attempts', 'expires_at', 'consumed_at', 'locked_until',
    ];

    protected $hidden = ['code_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => OtpChannel::class,
            'purpose' => OtpPurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }
}
