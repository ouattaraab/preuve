<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une consultation publique. Ne contient aucune donnée personnelle : l'adresse
 * IP n'y est présente que sous forme d'empreinte salée par jour.
 *
 * @property int $id
 * @property string $identifier_normalized
 * @property int|null $found_asset_id
 * @property string $ip_hash
 * @property int|null $user_id
 * @property string $source
 * @property int|null $duration_ms
 * @property Carbon $created_at
 */
class Lookup extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'identifier_normalized', 'found_asset_id', 'ip_hash', 'user_id', 'source', 'duration_ms', 'created_at',
    ];

    /**
     * L'empreinte de l'adresse n'a aucune raison de sortir de la base : elle
     * ne sert qu'au comptage interne.
     */
    protected $hidden = ['ip_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
