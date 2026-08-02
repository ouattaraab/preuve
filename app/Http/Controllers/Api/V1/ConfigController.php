<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use App\Models\CategoryField;
use App\Services\CategoryRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ConfigController extends Controller
{
    public function __construct(private readonly CategoryRegistry $registry) {}

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
