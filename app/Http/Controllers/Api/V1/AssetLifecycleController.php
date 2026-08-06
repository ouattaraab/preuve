<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PaymentRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAssetResource;
use App\Models\Asset;
use App\Models\User;
use App\Services\AssetLifecycleService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Déclarations du détenteur : vol, levée, fin de vie (ST-0604 à ST-0606).
 *
 * Le vol se déclare en un geste — un seul code, aucun autre champ : le
 * déclarant vient d'être dépouillé, et chaque exigence supplémentaire est une
 * chance de plus que la déclaration n'aboutisse pas.
 */
final class AssetLifecycleController extends Controller
{
    public function __construct(private readonly AssetLifecycleService $cycle) {}

    public function declareStolen(Request $request, int $asset): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        return $this->apply(
            fn (Asset $bien, User $detenteur): Asset => $this->cycle->declareStolen(
                $bien,
                $detenteur,
                $request->string('code')->toString(),
            ),
            $request,
            $asset,
            'Votre bien est signalé volé. Toute personne qui le consulte en est avertie.',
        );
    }

    public function clearStolen(Request $request, int $asset): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        return $this->apply(
            fn (Asset $bien, User $detenteur): Asset => $this->cycle->clearStolen(
                $bien,
                $detenteur,
                $request->string('code')->toString(),
            ),
            $request,
            $asset,
            'La déclaration de vol est levée. L\'épisode reste visible dans l\'historique du bien.',
        );
    }

    public function declareEndOfLife(Request $request, int $asset): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $motif = $request->string('reason')->toString();

        return $this->apply(
            fn (Asset $bien, User $detenteur): Asset => $this->cycle->declareEndOfLife(
                $bien,
                $detenteur,
                $request->string('code')->toString(),
                $motif === '' ? null : $motif,
            ),
            $request,
            $asset,
            'Le cycle de vie du bien est clos.',
        );
    }

    /** @param callable(Asset, User): Asset $action */
    private function apply(callable $action, Request $request, int $asset, string $message): JsonResponse
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        $bien = Asset::query()->whereKey($asset)->first();

        if (! $bien instanceof Asset) {
            abort(404);
        }

        try {
            $misAJour = $action($bien, $utilisateur);
        } catch (PaymentRequiredException $e) {
            // 402 ET NON 422. L'utilisateur n'a rien rempli de travers : il lui
            // manque un règlement. Le rendre comme une erreur de saisie le
            // ferait chercher un champ fautif qui n'existe pas — et le client
            // a besoin du montant pour l'annoncer avant d'ouvrir la caisse.
            return response()->json([
                'payment_required' => true,
                'purpose' => $e->purpose->value,
                'fee_fcfa' => $e->amountFcfa,
                'message' => $e->getMessage(),
            ], 402);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['asset' => $e->getMessage()]);
        }

        return response()->json([
            'message' => $message,
            'asset' => new PublicAssetResource($misAJour),
        ]);
    }
}
