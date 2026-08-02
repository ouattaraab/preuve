<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DoublonActifException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAssetResource;
use App\Models\User;
use App\Services\AssetRegistrationService;
use App\Services\CategoryRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Enregistrement express d'un bien (ST-0201). Toute écriture exige un compte
 * authentifié (règle métier absolue n° 2) ; la consultation, elle, n'en exige
 * jamais.
 */
final class AssetController extends Controller
{
    public function __construct(
        private readonly AssetRegistrationService $registration,
        private readonly CategoryRegistry $categories,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['required', 'string', 'max:40'],
            'attributes' => ['required', 'array'],
            // Chronomètre côté client pour la télémétrie CT-02 (< 90 s au
            // médian). Facultatif : un client hors ligne qui rejoue sa file
            // d'envois différés ne mesurerait rien de comparable (CT-05).
            'client_elapsed_ms' => ['sometimes', 'integer', 'min:0'],
        ]);

        $categorie = $request->string('category')->toString();

        // Les champs attendus dépendent de la catégorie, servie par
        // configuration distante : la validation est donc dérivée du
        // catalogue, jamais codée en dur (décision D6).
        try {
            $request->validate($this->categories->validationRulesFor($categorie));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['category' => $e->getMessage()]);
        }

        $proprietaire = $request->user();

        if (! $proprietaire instanceof User) {
            abort(401);
        }

        // Seuls les champs déclarés par la catégorie sont retenus : la colonne
        // `attributes` est un JSON libre en base, et tout ce qui y entrerait
        // sans filtre y resterait — charge arbitraire stockée aux frais de la
        // plateforme, et données personnelles jamais déclarées.
        $attributs = [];

        foreach ($this->categories->fieldKeys($categorie) as $cle) {
            $valeur = $request->input('attributes.'.$cle);

            if ($valeur !== null) {
                $attributs[$cle] = $valeur;
            }
        }

        $chrono = $request->has('client_elapsed_ms') ? (int) $request->integer('client_elapsed_ms') : null;

        try {
            $bien = $this->registration->register(
                // Le propriétaire est le porteur du jeton, jamais une valeur
                // soumise : accepter un owner_user_id de la requête
                // permettrait d'enregistrer un bien au nom d'autrui.
                $proprietaire,
                $categorie,
                $attributs,
                clientElapsedMs: $chrono,
            );
        } catch (DoublonActifException $e) {
            // Jamais de création : la seule issue est la fiche existante et le
            // parcours de réclamation (règle métier absolue n° 3).
            return response()->json([
                'message' => $e->getMessage(),
                'asset' => new PublicAssetResource($e->existant),
                'claim_url' => '/api/v1/claims?public_ref='.$e->existant->public_ref,
            ], 409);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['attributes' => $e->getMessage()]);
        }

        return response()->json(['asset' => new PublicAssetResource($bien)], 201);
    }
}
