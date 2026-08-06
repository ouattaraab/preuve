<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentProvider;
use App\Enums\PaymentPurpose;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use App\Services\PaymentCheckout;
use App\Services\PaymentService;
use App\Services\StolenListingService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Mettre son bien volé EN AVANT sur la liste publique (ST-0805).
 *
 * CE QU'ON VEND ICI, ET CE QU'ON NE VEND PAS. Déclarer un vol rend le bien
 * invendable pour quiconque VÉRIFIE son numéro : c'est la protection, elle est
 * immédiate, et elle ne se monnaie pas — celui qui déclare vient de se faire
 * dépouiller. Ce contrôleur vend la VISIBILITÉ : figurer sur la liste que tout
 * le monde parcourt, et que personne n'a besoin de connaître un numéro pour
 * lire. C'est un service en plus, et c'est lui qui permet à la protection de
 * rester gratuite.
 *
 * L'OFFRE ARRIVE APRÈS LA DÉCLARATION, JAMAIS PENDANT. Proposer de payer au
 * moment où quelqu'un signale un vol reviendrait à monnayer sa détresse. On
 * protège d'abord, on propose ensuite.
 */
final class StolenListingController extends Controller
{
    public function __construct(
        private readonly StolenListingService $liste,
        private readonly PaymentService $paiements,
        private readonly PaymentCheckout $caisse,
    ) {}

    /** Ce que la mise en avant coûte, et où en est ce bien. */
    public function show(Request $request, int $asset): JsonResponse
    {
        $bien = $this->bienDuDetenteur($request, $asset);

        return response()->json($this->etat($bien));
    }

    /**
     * Publie le bien, ou ouvre le paiement qui le publiera.
     */
    public function store(Request $request, int $asset): JsonResponse
    {
        $detenteur = $this->utilisateur($request);
        $bien = $this->bienDuDetenteur($request, $asset);

        // GRATUIT VEUT DIRE GRATUIT. Quand l'exploitant met le tarif à zéro, on
        // publie sans détour : ouvrir une page de paiement pour zéro franc
        // ferait échouer le parcours faute d'opérateur, et pour rien.
        if ($this->liste->isFree()) {
            return $this->publier($bien, $detenteur, 'Ton bien est maintenant sur la liste des biens volés.');
        }

        // DÉJÀ PAYÉ, DÉJÀ DÛ. Un paiement abouti dont la publication n'a pas
        // suivi — webhook reçu après un abandon, application fermée trop tôt —
        // ne doit pas obliger à repayer.
        if ($this->paiements->hasPaidFor($detenteur, $bien, PaymentPurpose::TheftListing)) {
            return $this->publier($bien, $detenteur, 'Paiement déjà reçu : ton bien est sur la liste.');
        }

        $request->validate([
            'provider' => ['sometimes', 'string'],
        ]);

        $operateur = PaymentProvider::tryFrom($request->string('provider')->toString())
            ?? PaymentProvider::Paystack;

        try {
            $ouverture = $this->caisse->open(
                $detenteur,
                $bien,
                $operateur,
                PaymentPurpose::TheftListing,
                $this->liste->price(),
                url('/api/v1/assets/'.$bien->id.'/stolen-listing'),
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }

        $checkout = $ouverture['checkout_url'];

        return response()->json([
            'listed' => false,
            'price_fcfa' => $this->liste->price(),
            'checkout_url' => $checkout,
            'message' => 'Règle le montant, puis reviens : ton bien paraîtra sur la liste '
                .'dès que l\'opérateur aura confirmé.',
        ], 201);
    }

    /**
     * Retire le bien de la liste.
     *
     * TOUJOURS GRATUIT ET IMMÉDIAT. Un bien retrouvé, une déclaration faite par
     * erreur : laisser une publication survivre à son motif exposerait un
     * identifiant pour rien. Ce qui a été payé est la visibilité passée, pas
     * l'obligation de la subir.
     */
    public function destroy(Request $request, int $asset): JsonResponse
    {
        $detenteur = $this->utilisateur($request);
        $bien = $this->bienDuDetenteur($request, $asset);

        try {
            $this->liste->withdraw($bien, $detenteur);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['asset' => $e->getMessage()]);
        }

        return response()->json([
            'listed' => false,
            'message' => 'Ton bien ne paraît plus sur la liste publique.',
        ]);
    }

    private function publier(Asset $bien, User $detenteur, string $message): JsonResponse
    {
        try {
            $this->liste->publish($bien, $detenteur);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['asset' => $e->getMessage()]);
        }

        return response()->json(['listed' => true, 'message' => $message] + $this->etat($bien->fresh() ?? $bien));
    }

    /** @return array<string, mixed> */
    private function etat(Asset $bien): array
    {
        return [
            'listed' => $bien->stolen_listed_at !== null,
            'listed_at' => $bien->stolen_listed_at?->toIso8601String(),
            'price_fcfa' => $this->liste->price(),
            'free' => $this->liste->isFree(),
            // CE QUE LA PUBLICATION APPORTE, dit à celui qui va payer. Sans
            // cela, on lui demande de l'argent pour une notion abstraite.
            'explanation' => 'Ton bien est déjà invendable pour qui vérifie son numéro : '
                .'cela ne change pas, et c\'est gratuit. La mise en avant le fait paraître '
                .'sur la liste que tout le monde parcourt — garagistes, acheteurs, forces '
                .'de l\'ordre — sans qu\'ils aient besoin de connaître ton numéro.',
        ];
    }

    private function bienDuDetenteur(Request $request, int $asset): Asset
    {
        $bien = Asset::query()
            ->whereKey($asset)
            // 404 ET NON 403 : distinguer « ce bien n'existe pas » de « il
            // n'est pas à vous » permettrait d'énumérer le registre.
            ->where('owner_user_id', $this->utilisateur($request)->id)
            ->first();

        if (! $bien instanceof Asset) {
            abort(404);
        }

        return $bien;
    }

    private function utilisateur(Request $request): User
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        return $utilisateur;
    }
}
