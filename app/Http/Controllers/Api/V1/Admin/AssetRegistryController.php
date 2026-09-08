<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetStatusHistory;
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

    /**
     * Le dossier complet d'un bien, vu du back-office.
     *
     * IL NE DIT TOUJOURS RIEN DU DÉTENTEUR — ni nom, ni numéro, ni identifiant
     * de compte. La règle métier absolue n° 4 ne connaît pas d'exception
     * interne : un back-office qui lèverait l'anonymat en ferait un outil de
     * traque à disposition de quiconque obtient un compte agent. Ce n'est pas
     * un oubli, et cela ne se « complète » pas.
     *
     * CE QUI EXISTE À LA PLACE est un chemin prévu, tracé et opposable : la
     * levée d'anonymat sur réquisition (`/admin/identity-disclosures`),
     * réservée aux administrateurs, exigeant un fondement structuré, rendant
     * l'identité UNE FOIS, et laissant une double trace inaltérable. Cette
     * réponse porte l'adresse de ce chemin plutôt que l'identité : un agent qui
     * en a besoin doit passer par là, et laisser une trace en le faisant.
     *
     * L'HISTORIQUE ET LES PIÈCES SONT LÀ, EUX. Ce sont eux qui permettent
     * d'instruire : ce qui est arrivé au bien, quand, et sur quelles pièces.
     */
    public function show(Request $request, int $asset): JsonResponse
    {
        $bien = Asset::query()->whereKey($asset)->first();

        if (! $bien instanceof Asset) {
            abort(404);
        }

        $attributs = $bien->getAttribute('attributes');
        $attributs = is_array($attributs) ? $attributs : [];

        $historique = AssetStatusHistory::query()
            ->where('asset_id', $bien->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $pieces = AssetDocument::query()
            ->where('asset_id', $bien->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'asset' => [
                'id' => $bien->id,
                'public_ref' => $bien->public_ref,
                'identifier' => $bien->identifier_normalized,
                'identifier_type' => $bien->identifier_type,
                'category' => $bien->asset_category_key,
                // Les champs déclarés par la catégorie : marque, modèle, année.
                // Ils décrivent le BIEN, jamais la personne.
                'attributes' => $attributs,
                'life_status' => $bien->life_status->value,
                'life_status_label' => $bien->life_status->label(),
                'life_status_color' => $bien->life_status->color(),
                'trust_level' => $bien->trust_level->value,
                'trust_level_label' => $bien->trust_level->label(),
                'registered_at' => $bien->registered_at->toIso8601String(),
                'provisional_until' => $bien->provisional_until?->toIso8601String(),
                'stolen_declared_at' => $bien->stolen_declared_at?->toIso8601String(),
                // `active_flag` dit si CET enregistrement est celui qui fait
                // foi : un agent qui instruit un litige doit pouvoir distinguer
                // l'actif d'un maillon archivé de la chaîne des détenteurs.
                'is_active' => $bien->active_flag !== null,
                'lookups_30d' => $this->consultationsSur30Jours([$bien->identifier_normalized])[$bien->identifier_normalized] ?? 0,
            ],
            'history' => $historique->map(fn (AssetStatusHistory $ligne): array => [
                'from_status' => $ligne->from_status?->value,
                'to_status' => $ligne->to_status->value,
                'to_status_label' => $ligne->to_status->label(),
                'trigger_type' => $ligne->trigger_type->value,
                'reason' => $ligne->reason,
                'at' => $ligne->created_at?->toIso8601String(),
                // NI `actor_user_id`, NI son nom : qui a agi relève de la même
                // règle que qui détient.
            ])->all(),
            'documents' => $pieces->map(fn (AssetDocument $piece): array => [
                'id' => $piece->id,
                'doc_type' => $piece->doc_type->value,
                'doc_type_label' => $piece->doc_type->label(),
                'review_status' => $piece->review_status->value,
                'review_status_label' => $piece->review_status->label(),
                'review_reason' => $piece->review_reason,
                'submitted_at' => $piece->created_at?->toIso8601String(),
                'reviewed_at' => $piece->reviewed_at?->toIso8601String(),
                'file_url' => '/api/v1/admin/documents/'.$piece->id.'/file',
            ])->all(),
            // Dit explicitement ce qui n'est pas rendu, et par où passer. Un
            // champ absent sans explication se lit comme un défaut ; expliqué,
            // il se lit comme la protection qu'il est.
            'holder' => [
                'disclosed' => false,
                'notice' => "L'identité du détenteur n'est jamais rendue par le registre ".
                    '(règle métier absolue n° 4). Elle ne peut être levée que sur réquisition '.
                    "d'une autorité, par un administrateur, avec fondement consigné et double trace.",
                'disclosure_path' => '/api/v1/admin/identity-disclosures',
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
