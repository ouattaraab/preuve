<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $fillable = [
        'destination', 'channel', 'purpose', 'code_hash',
        'attempts', 'max_attempts', 'expires_at', 'locked_until',
    ];

    protected $hidden = ['code_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }
}
