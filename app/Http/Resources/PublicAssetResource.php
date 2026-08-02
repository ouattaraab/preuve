<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AgeBracket;
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
            'signals' => $this->temporalSignals($asset),
        ];
    }

    /**
     * Signaux temporels non antidatables (ST-0404).
     *
     * Les deux dates qui les fondent — enregistrement du bien et création du
     * compte — sont posées par le système et n'ont aucun chemin d'écriture
     * depuis l'API. Un signal que le déclarant pourrait antidater ne vaudrait
     * rien : c'est précisément le vendeur pressé qui aurait intérêt à se
     * fabriquer de l'ancienneté.
     *
     * L'ancienneté du compte est rendue en TRANCHE, jamais en date : une date
     * exacte, recoupée avec ce qu'un acheteur sait par ailleurs, aiderait à
     * identifier le déclarant — ce que l'anonymat interdit (règle métier
     * absolue n° 4).
     *
     * @return array<string, mixed>
     */
    private function temporalSignals(Asset $asset): array
    {
        $enregistrement = AgeBracket::fromDate($asset->registered_at);
        $compte = AgeBracket::fromDate($asset->owner?->created_at);

        return [
            'registration_age' => $enregistrement === null ? null : [
                'bracket' => $enregistrement->value,
                'label' => $enregistrement->label(),
                'attention' => $enregistrement->deservesAttention(),
            ],
            // L'ancienneté du COMPTE, pas son identité : c'est le seul trait du
            // déclarant que le verdict public expose (systemPatterns §4).
            'holder_account_age' => $compte === null ? null : [
                'bracket' => $compte->value,
                'label' => $compte->label(),
                'attention' => $compte->deservesAttention(),
            ],
            // Formulation factuelle : la plateforme informe, elle ne juge pas.
            // C'est à l'acheteur de conclure, avec le vendeur en face de lui.
            'notice' => $enregistrement?->deservesAttention() === true || $compte?->deservesAttention() === true
                ? 'Enregistrement ou compte très récent. Ce n\'est pas anormal en soi — un bien acheté hier '.
                    'produit le même signal — mais demandez au vendeur depuis quand il détient ce bien.'
                : null,
        ];
    }
}
