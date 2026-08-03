<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\CategoryField;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\CategoryRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catégories de biens et leurs champs (décision D6).
 *
 * SERVIES PAR CONFIGURATION DISTANTE : l'application mobile ne code aucun type
 * de bien en dur et relit ce catalogue au démarrage. Une publication est donc
 * visible immédiatement, sans passer par les magasins d'applications — c'est
 * tout l'intérêt, et c'est aussi ce qui rend une erreur immédiatement visible
 * par tous.
 *
 * UNE CATÉGORIE NE SE SUPPRIME PAS, elle se désactive. Des biens y sont
 * rattachés, et leur catégorie ne doit pas disparaître sous eux : un bien dont
 * la catégorie n'existe plus deviendrait inaffichable, et son propriétaire
 * perdrait la fiche qui le protège. La désactivation la retire des nouveaux
 * enregistrements sans toucher aux existants.
 *
 * L'IDENTIFIANT CANONIQUE NE SE DÉPLACE PAS. C'est lui qui porte la règle
 * « un identifiant = un enregistrement actif » ; le changer sur une catégorie
 * déjà peuplée ferait porter l'unicité sur une autre colonne que celle qui a
 * servi à la garantir, et des doublons apparaîtraient rétroactivement.
 */
final class CategoryAdminController extends Controller
{
    public function __construct(
        private readonly CategoryRegistry $registre,
        private readonly AuditChain $auditChain,
    ) {}

    public function index(): JsonResponse
    {
        $categories = AssetCategory::query()->with('fields')->orderBy('position')->get();

        return response()->json([
            'version' => $this->registre->version(),
            'categories' => $categories->map(fn (AssetCategory $c): array => [
                'id' => $c->id,
                'key' => $c->key,
                'name' => $c->name,
                'icon' => $c->icon,
                'position' => $c->position,
                'is_active' => (bool) $c->is_active,
                // Le nombre de biens rattachés : c'est lui qui dit si une
                // désactivation est anodine ou lourde de conséquences.
                'assets' => Asset::where('asset_category_key', $c->key)->whereNotNull('active_flag')->count(),
                'fields' => $c->fields->map(fn (CategoryField $f): array => [
                    'id' => $f->id,
                    'key' => $f->key,
                    'label' => $f->label,
                    'type' => $f->type,
                    'required' => (bool) $f->is_required,
                    'canonical' => (bool) $f->is_canonical_identifier,
                    'position' => $f->position,
                ])->all(),
            ])->all(),
        ]);
    }

    /** Active ou désactive une catégorie. */
    public function setActive(Request $request, int $category): JsonResponse
    {
        $request->validate(['active' => ['required', 'boolean']]);

        $categorie = AssetCategory::find($category);

        if (! $categorie instanceof AssetCategory) {
            abort(404);
        }

        $actif = $request->boolean('active');

        $this->journaliser($request, $actif ? 'admin.category_enabled' : 'admin.category_disabled', [
            'key' => $categorie->key,
        ]);

        $categorie->forceFill(['is_active' => $actif])->save();
        $version = $this->registre->publish();

        $rattaches = Asset::where('asset_category_key', $categorie->key)->whereNotNull('active_flag')->count();

        return response()->json([
            'message' => $actif
                ? 'Catégorie réactivée et publiée.'
                : sprintf(
                    'Catégorie retirée des nouveaux enregistrements. Les %d bien(s) déjà rattaché(s) '.
                    'restent enregistrés et consultables.',
                    $rattaches,
                ),
            'version' => $version,
        ]);
    }

    /** Ajoute un champ à une catégorie. */
    public function addField(Request $request, int $category): JsonResponse
    {
        $categorie = AssetCategory::find($category);

        if (! $categorie instanceof AssetCategory) {
            abort(404);
        }

        $request->validate([
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['text', 'number', 'date', 'identifier', 'select'])],
            'required' => ['sometimes', 'boolean'],
        ]);

        $cle = $request->string('key')->toString();

        if (CategoryField::where('asset_category_id', $categorie->id)->where('key', $cle)->exists()) {
            return response()->json(['message' => 'Ce champ existe déjà dans cette catégorie.'], 422);
        }

        $this->journaliser($request, 'admin.category_field_added', [
            'category' => $categorie->key,
            'field' => $cle,
        ]);

        $champ = CategoryField::create([
            'asset_category_id' => $categorie->id,
            'key' => $cle,
            'label' => $request->string('label')->toString(),
            'type' => $request->string('type')->toString(),
            'is_required' => $request->boolean('required'),
            // JAMAIS canonique par cet appel : l'identifiant qui porte
            // l'unicité se fixe à la création de la catégorie et ne se déplace
            // pas ensuite.
            'is_canonical_identifier' => false,
            'position' => $this->prochainePosition($categorie->id),
        ]);

        return response()->json([
            'message' => 'Champ ajouté et catalogue publié.',
            'field_id' => $champ->id,
            'version' => $this->registre->publish(),
        ], 201);
    }

    /** Republie le catalogue sans rien modifier. */
    public function publish(Request $request): JsonResponse
    {
        $this->journaliser($request, 'admin.categories_published', []);

        return response()->json([
            'message' => 'Catalogue publié. Les applications le reliront à leur prochain démarrage.',
            'version' => $this->registre->publish(),
        ]);
    }

    /** Position suivante dans la catégorie : les champs s'ajoutent en fin de formulaire. */
    private function prochainePosition(int $categorieId): int
    {
        $max = CategoryField::where('asset_category_id', $categorieId)->max('position');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    /** @param array<string, mixed> $charge */
    private function journaliser(Request $request, string $action, array $charge): void
    {
        $administrateur = $request->user();

        // Le catalogue gouverne ce que toute l'application accepte
        // d'enregistrer : un changement muet rendrait inexplicable un refus
        // survenu le lendemain.
        $this->auditChain->append(
            ActorType::Agent,
            $administrateur instanceof User ? $administrateur->id : null,
            $action,
            'asset_category',
            0,
            $charge,
        );
    }
}
