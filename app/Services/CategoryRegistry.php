<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AssetCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Catalogue des types de biens et de leurs champs.
 *
 * L'application mobile ne code jamais un type de bien en dur : elle récupère
 * cette configuration au démarrage et la met en cache localement. Une
 * publication est donc visible immédiatement, sans passer par les stores.
 */
final class CategoryRegistry
{
    private const VERSION_KEY = 'preuve.categories.version';

    /** @return Collection<int, AssetCategory> */
    public function published(): Collection
    {
        return AssetCategory::query()
            ->with('fields')
            ->where('is_active', true)
            ->orderBy('position')
            ->get();
    }

    /** Version courante de la configuration, servant d'ETag. */
    public function version(): string
    {
        $version = Cache::get(self::VERSION_KEY);

        return is_string($version) ? $version : 'v0';
    }

    /** Publie l'état courant et renvoie la nouvelle version. */
    public function publish(): string
    {
        $version = 'v'.now()->getTimestampMs();
        Cache::forever(self::VERSION_KEY, $version);

        return $version;
    }

    /**
     * Règles de validation d'un enregistrement, dérivées des champs déclarés.
     *
     * @return array<string, string>
     */
    public function validationRulesFor(string $categoryKey): array
    {
        $category = $this->findOrFail($categoryKey);
        $rules = [];

        foreach ($category->fields as $field) {
            $parts = [$field->is_required ? 'required' : 'nullable'];

            $parts[] = match ($field->type) {
                'number' => 'numeric',
                'date' => 'date',
                default => 'string',
            };

            if ($field->validation_rule !== null && $field->validation_rule !== '') {
                $parts[] = $field->validation_rule;
            }

            $rules['attributes.'.$field->key] = implode('|', $parts);
        }

        return $rules;
    }

    /**
     * Clés des champs déclarés par la catégorie.
     *
     * Sert à ne retenir d'une soumission que les champs prévus : la colonne
     * `attributes` est un JSON libre côté base, et tout ce qui y entrerait sans
     * filtre y resterait — charge arbitraire stockée aux frais de la
     * plateforme, et données personnelles jamais déclarées.
     *
     * @return list<string>
     */
    public function fieldKeys(string $categoryKey): array
    {
        $cles = [];

        foreach ($this->findOrFail($categoryKey)->fields as $field) {
            $cles[] = $field->key;
        }

        return $cles;
    }

    /**
     * Clé du champ extrait vers assets.identifier_normalized.
     *
     * Sans identifiant canonique, la règle « un identifiant = un
     * enregistrement actif » n'aurait plus de support : on refuse.
     */
    public function canonicalFieldKey(string $categoryKey): string
    {
        $field = $this->findOrFail($categoryKey)
            ->fields
            ->firstWhere('is_canonical_identifier', true);

        if ($field === null) {
            throw new RuntimeException(
                "La catégorie « {$categoryKey} » ne désigne aucun identifiant canonique."
            );
        }

        return $field->key;
    }

    private function findOrFail(string $categoryKey): AssetCategory
    {
        $category = AssetCategory::with('fields')->where('key', $categoryKey)->first();

        if ($category === null) {
            throw new RuntimeException("Catégorie inconnue : « {$categoryKey} ».");
        }

        return $category;
    }
}
