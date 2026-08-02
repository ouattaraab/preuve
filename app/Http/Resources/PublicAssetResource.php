<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vue publique d'un bien — la seule autorisée hors du propriétaire lui-même.
 *
 * Règle métier absolue n° 4 : l'identité du détenteur n'est JAMAIS divulguée,
 * à personne, même à qui paie un rapport. Cette classe est le point de passage
 * unique de cette règle : elle ne compose qu'à partir de champs sûrs plutôt
 * que d'exclure des champs sensibles d'un modèle complet — une liste
 * d'exclusions se périme silencieusement à chaque colonne ajoutée.
 *
 * L'identifiant complet du bien n'y figure pas davantage : le publier
 * permettrait de constituer, par balayage, l'annuaire des identifiants
 * enregistrés. Seul le verdict compte pour le consultant.
 *
 * @mixin Asset
 */
final class PublicAssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Asset $asset */
        $asset = $this->resource;

        return [
            'public_ref' => $asset->public_ref,
            'category' => $asset->asset_category_key,
            'identifier_type' => $asset->identifier_type,
            'life_status' => [
                // Le code accompagne toujours son libellé : l'interface
                // n'affiche jamais « V-PRV » (CT-04), mais le client mobile a
                // besoin d'une valeur stable pour ses propres règles.
                'code' => $asset->life_status->value,
                'label' => $asset->life_status->label(),
                'message' => $asset->life_status->publicMessage(),
                'color' => $asset->life_status->color(),
                'warning' => $asset->life_status->isPublicWarning(),
            ],
            'trust_level' => [
                'code' => $asset->trust_level->value,
                'label' => $asset->trust_level->label(),
                'color' => $asset->trust_level->color(),
            ],
            // Signal temporel : l'ancienneté d'un enregistrement pèse dans
            // l'appréciation d'un acheteur, son auteur non.
            'registered_at' => $asset->registered_at->toIso8601String(),
            'provisional_until' => $asset->provisional_until?->toIso8601String(),
        ];
    }
}
