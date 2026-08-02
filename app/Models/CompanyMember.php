<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property string $invited_phone
 * @property CompanyRole $role
 * @property int|null $invited_by
 * @property bool $is_active
 */
class CompanyMember extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'invited_phone', 'role', 'invited_by', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
