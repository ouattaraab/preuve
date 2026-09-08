<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_ref
 * @property int $owner_user_id
 * @property int|null $company_id
 * @property int|null $previous_asset_id
 * @property string $asset_category_key
 * @property string $identifier_type
 * @property string $identifier_raw
 * @property string $identifier_normalized
 * @property int|null $active_flag
 * @property TrustLevel $trust_level
 * @property LifeStatus $life_status
 * @property Carbon|null $provisional_until
 * @property Carbon|null $stolen_declared_at
 * @property Carbon|null $trust_verified_at
 * @property int|null $trust_verified_by
 * @property bool $stolen_consolidated
 * @property Carbon|null $stolen_listed_at Consentement daté à figurer sur la
 *                                         liste publique des biens volés.
 * @property Carbon|null $spike_alerted_at
 * @property Carbon $registered_at
 */
class Asset extends Model
{
    protected $fillable = [
        'public_ref', 'owner_user_id', 'company_id', 'previous_asset_id', 'asset_category_key',
        'identifier_type', 'identifier_raw', 'identifier_normalized', 'active_flag',
        'attributes', 'trust_level', 'life_status', 'provisional_until',
        'stolen_declared_at', 'stolen_consolidated', 'stolen_listed_at', 'spike_alerted_at', 'registered_at',
    ];

    /**
     * L'identité du propriétaire n'est jamais exposée publiquement
     * (règle métier absolue n° 4).
     */
    protected $hidden = ['owner_user_id', 'identifier_raw'];

    /**
     * ATTENTION — la colonne `attributes` (champs propres à la catégorie du
     * bien) porte le même nom que la propriété interne `Model::$attributes`
     * d'Eloquent. Depuis l'extérieur du modèle, `$asset->attributes` passe par
     * __get() et renvoie bien la valeur castée : la propriété interne est
     * `protected`, donc invisible. Mais À L'INTÉRIEUR de cette classe,
     * `$this->attributes` désigne le tableau brut de TOUS les attributs du
     * modèle, jamais le contenu de la colonne. Y écrire écraserait le modèle
     * entier en silence. Utiliser `$this->getAttribute('attributes')` dans
     * toute méthode ajoutée ici. Verrouillé par tests/Feature/AssetAttributsTest.php.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'trust_level' => TrustLevel::class,
            'life_status' => LifeStatus::class,
            'provisional_until' => 'datetime',
            'stolen_declared_at' => 'datetime',
            'stolen_listed_at' => 'datetime',
            'stolen_consolidated' => 'boolean',
            'spike_alerted_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<AssetStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(AssetStatusHistory::class)->orderBy('created_at');
    }

    public function isActive(): bool
    {
        return $this->active_flag === 1;
    }
}
