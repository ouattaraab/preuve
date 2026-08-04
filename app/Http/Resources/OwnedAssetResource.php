<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vue d'un bien PAR SON PROPRE DÉTENTEUR — jamais par un tiers.
 *
 * CE N'EST PAS UN ASSOUPLISSEMENT DE LA RÈGLE MÉTIER ABSOLUE N° 4. Cette règle
 * protège l'IDENTITÉ du détenteur contre les tiers ; elle n'a jamais interdit à
 * quelqu'un de relire ce qu'il a lui-même saisi. Rien ici ne désigne une
 * personne : cette vue ne rend que le bien, à celui qui le détient.
 *
 * DEUX CHAMPS ABSENTS DE LA VUE PUBLIQUE Y FIGURENT, et chacun se justifie :
 *
 * - `identifier` — le numéro complet. La vue publique le masque pour empêcher
 *   de constituer par balayage l'annuaire des identifiants enregistrés. Ce
 *   risque n'existe pas ici : le détenteur ne peut lire que les siens, qu'il a
 *   saisis lui-même. Le lui cacher l'empêcherait de reconnaître son propre
 *   véhicule dans une liste qui en compte douze.
 * - `id` — l'identifiant interne. Il est indispensable pour agir : déclarer un
 *   vol, céder, réclamer passent tous par `/assets/{id}/…`. Le rendre ne
 *   fabrique aucun moyen de balayage : le serveur répond 404, et non 403, sur
 *   le bien d'autrui — deviner un numéro n'apprend donc rien.
 *
 * @mixin Asset
 */
final class OwnedAssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Asset $asset */
        $asset = $this->resource;

        // `getAttribute('attributes')` et non `$asset->attributes` : la colonne
        // porte le même nom que la propriété interne d'Eloquent, et l'accès
        // direct rendrait le tableau brut du modèle entier (voir Asset).
        $attributs = $asset->getAttribute('attributes');
        $attributs = is_array($attributs) ? $attributs : [];

        return [
            'id' => $asset->id,
            'public_ref' => $asset->public_ref,
            'identifier' => $asset->identifier_normalized,
            'identifier_type' => $asset->identifier_type,
            'category' => $asset->asset_category_key,
            'attributes' => $attributs,
            'life_status' => [
                // Le code accompagne toujours son libellé : l'interface
                // n'affiche jamais « V-PRV » (CT-04), mais le client a besoin
                // d'une valeur stable pour décider quelles actions proposer.
                'code' => $asset->life_status->value,
                'label' => $asset->life_status->label(),
                'color' => $asset->life_status->color(),
                'warning' => $asset->life_status->isPublicWarning(),
            ],
            'trust_level' => [
                'code' => $asset->trust_level->value,
                'label' => $asset->trust_level->label(),
                'color' => $asset->trust_level->color(),
            ],
            'registered_at' => $asset->registered_at->toIso8601String(),
            'provisional_until' => $asset->provisional_until?->toIso8601String(),
            'stolen_declared_at' => $asset->stolen_declared_at?->toIso8601String(),
            // Ni `owner_user_id`, ni téléphone, ni nom : même dans sa propre
            // vue, le détenteur n'est désigné nulle part. Il n'y en a aucun
            // besoin — c'est le porteur du jeton — et une identité qui ne
            // circule pas ne fuit pas.
        ];
    }
}
