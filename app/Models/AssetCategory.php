<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCategory extends Model
{
    protected $fillable = ['key', 'name', 'icon', 'position', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<CategoryField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(CategoryField::class)->orderBy('position');
    }
}
