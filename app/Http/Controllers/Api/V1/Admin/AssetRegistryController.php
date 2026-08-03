<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Registre des biens, vu du back-office (ST-0902).
 *
 * IL NE DIT RIEN DU DÉTENTEUR. Un agent voit le bien, son statut, sa fiabilité
 * et son activité de consultation — jamais qui le détient. C'est la règle
 * métier absolue n° 4, et elle ne connaît pas d'exception interne : un
 * back-office qui lèverait l'anonymat en ferait un outil de traque à
 * disposition de quiconque obtient un compte agent.
 *
 * IL EST PAGINÉ, ET C'EST UNE PROTECTION. La consultation publique est
 * délibérément plafonnée pour qu'on ne puisse pas cartographier le parc en
 * balayant les identifiants ; une liste d'administration sans borne
 * rétablirait exactement ce que ce plafond empêche, du côté où l'on ne
 * regarde pas.
 */
final class AssetRegistryController extends Controller
{
    /** Assez pour travailler, assez peu pour ne pas devenir un export. */
    private const PAR_PAGE = 25;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'nullable', 'string', 'max:10'],
            'trust' => ['sometimes', 'nullable', 'string', 'max:4'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $requete = Asset::query()->whereNotNull('active_flag');

        $this->appliquerFiltres($requete, $request);

        $page = $requete->orderByDesc('registered_at')
            ->paginate(self::PAR_PAGE, ['*'], 'page', (int) $request->integer('page', 1));

        // Comptage des consultations en UNE requête groupée : un compte par
        // ligne ferait vingt-cinq requêtes par page, sur la table qui grossit
        // le plus vite de la base.
        $identifiants = [];

        foreach ($page->getCollection() as $bien) {
            $identifiants[] = $bien->identifier_normalized;
        }

        $consultations = $this->consultationsSur30Jours($identifiants);

        return response()->json([
            'assets' => $page->getCollection()->map(
                fn (Asset $bien): array => $this->presenter($bien, $consultations[$bien->identifier_normalized] ?? 0)
            )->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** @param Builder<Asset> $requete */
    private function appliquerFiltres(Builder $requete, Request $request): void
    {
        $recherche = $request->string('q')->toString();

        if ($recherche !== '') {
            // Normalisé comme à l'enregistrement : sans cela « AA-123-BC » ne
            // trouverait pas « AA123BC », et l'agent conclurait à tort que le
            // bien n'existe pas.
            $normalise = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($recherche)) ?? '';

            $requete->where(function (Builder $q) use ($normalise, $recherche): void {
                $q->where('identifier_normalized', 'like', $normalise.'%')
                    ->orWhere('public_ref', mb_strtoupper(trim($recherche)));
            });
        }

        $statut = $request->string('status')->toString();

        if ($statut !== '') {
            $requete->where('life_status', $statut);
        }

        $fiabilite = $request->string('trust')->toString();

        if ($fiabilite !== '') {
            $requete->where('trust_level', $fiabilite);
        }
    }

    /**
     * @param  list<string>  $identifiants
     * @return array<string, int>
     */
    private function consultationsSur30Jours(array $identifiants): array
    {
        if ($identifiants === []) {
            return [];
        }

        $lignes = DB::table('lookups')
            ->select('identifier_normalized', DB::raw('COUNT(*) as total'))
            ->whereIn('identifier_normalized', $identifiants)
            ->where('created_at', '>=', now()->subDays(30)->format('Y-m-d H:i:s'))
            ->groupBy('identifier_normalized')
            ->get();

        $compte = [];

        foreach ($lignes as $ligne) {
            $cle = $ligne->identifier_normalized ?? null;
            $total = $ligne->total ?? 0;

            if (is_string($cle) && is_numeric($total)) {
                $compte[$cle] = (int) $total;
            }
        }

        return $compte;
    }

    /** @return array<string, mixed> */
    private function presenter(Asset $bien, int $consultations): array
    {
        // `getAttribute('attributes')` et non `$bien->attributes` : la colonne
        // porte le même nom que la propriété interne d'Eloquent, et l'accès
        // direct rendrait le tableau brut du modèle entier (voir Asset).
        $attributs = $bien->getAttribute('attributes');
        $attributs = is_array($attributs) ? $attributs : [];

        return [
            'id' => $bien->id,
            'public_ref' => $bien->public_ref,
            'identifier' => $bien->identifier_normalized,
            'identifier_type' => $bien->identifier_type,
            'category' => $bien->asset_category_key,
            // Marque et modèle viennent des champs déclarés par la catégorie :
            // utiles à l'agent pour reconnaître le bien, et sans rapport avec
            // l'identité du détenteur.
            'label' => is_string($attributs['brand_model'] ?? null) ? $attributs['brand_model'] : null,
            'life_status' => $bien->life_status->value,
            'life_status_label' => $bien->life_status->label(),
            'trust_level' => $bien->trust_level->value,
            'trust_level_label' => $bien->trust_level->label(),
            'registered_at' => $bien->registered_at->toIso8601String(),
            'lookups_30d' => $consultations,
            // Ni `owner_user_id`, ni téléphone, ni nom : le détenteur n'existe
            // pas dans cette vue (règle métier absolue n° 4).
        ];
    }
}
