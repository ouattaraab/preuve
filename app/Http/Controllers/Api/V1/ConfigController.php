<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use App\Models\CategoryField;
use App\Services\AppRelease;
use App\Services\CategoryRegistry;
use App\Services\Scan\DocumentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ConfigController extends Controller
{
    public function __construct(
        private readonly CategoryRegistry $registry,
        private readonly AppRelease $release,
        private readonly DocumentReader $lecteur,
    ) {}

    /**
     * Version minimale exigée des applications.
     *
     * PUBLIQUE ET INTERROGEABLE AVANT CONNEXION, à dessein : l'application doit
     * pouvoir afficher son écran de mise à jour au démarrage, plutôt que de
     * laisser l'utilisateur saisir un bien pendant quatre-vingt-dix secondes
     * pour se heurter au refus à l'envoi.
     *
     * Elle n'est pas mise en cache : c'est le réglage qu'on change au moment où
     * il faut qu'il soit vu tout de suite.
     */
    public function app(): JsonResponse
    {
        $minimum = $this->release->minimum();

        return response()->json([
            'minimum_version' => $minimum,
            'latest_version' => $this->release->latest(),
            'update_required_for_writes' => $minimum !== null,
            // Répété ici parce que c'est la promesse n° 1 du produit : un
            // verdict ne dépend jamais de la version installée.
            'lookup_always_available' => true,
            // ANNONCÉ POUR QUE LE CLIENT NE PROPOSE PAS CE QUI N'EXISTE PAS.
            // Sans fournisseur d'extraction, offrir « Je scanne » fait prendre
            // une photo, la fait envoyer, et rend un échec que l'utilisateur
            // attribuera à sa photo — il recommencera. Mieux vaut ne pas
            // proposer le raccourci que le proposer sans qu'il puisse aboutir.
            'scan_available' => $this->lecteur->isConfigured(),
        ])->header('Cache-Control', 'no-store');
    }

    /** Configuration distante des catégories — publique, mise en cache CDN. */
    public function categories(Request $request): JsonResponse|Response
    {
        $version = $this->registry->version();
        $etag = '"'.$version.'"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->noContent(Response::HTTP_NOT_MODIFIED);
        }

        $categories = $this->registry->published()->map(
            fn (AssetCategory $category): array => [
                'key' => $category->key,
                'name' => $category->name,
                'icon' => $category->icon,
                'fields' => $category->fields->map(
                    fn (CategoryField $field): array => [
                        'key' => $field->key,
                        'label' => $field->label,
                        'type' => $field->type,
                        'required' => $field->is_required,
                        'canonical' => $field->is_canonical_identifier,
                    ]
                )->all(),
            ]
        );

        return response()
            ->json(['version' => $version, 'categories' => $categories])
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=300');
    }
}
