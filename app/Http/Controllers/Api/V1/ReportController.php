<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OtpPurpose;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PaymentService;
use App\Services\ReportAccessService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Achat et lecture du rapport détaillé (ST-0801, ST-0802).
 *
 * L'achat n'exige pas de compte, mais exige une IDENTITÉ : un rapport anonyme
 * deviendrait l'outil de repérage idéal. L'acheteur sans compte fournit nom,
 * e-mail et téléphone, ce dernier vérifié par code AVANT le paiement — vérifier
 * après coup ne servirait à rien, l'accès serait déjà ouvert.
 *
 * La LECTURE, elle, ne passe que par le jeton : ni compte, ni session. C'est ce
 * qui permet de recevoir son rapport par SMS et de l'ouvrir sur n'importe quel
 * appareil, y compris celui d'un tiers au marché.
 */
final class ReportController extends Controller
{
    public function __construct(
        private readonly PaymentService $paiements,
        private readonly ReportAccessService $rapports,
        private readonly OtpService $otp,
    ) {}

    /** Envoie le code de vérification à un acheteur sans compte. */
    public function requestGuestCode(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30']]);

        $this->otp->request($request->string('phone')->toString(), OtpPurpose::GuestPayment);

        return response()->json([
            'message' => 'Si ce numéro est joignable, un code vient de lui être envoyé.',
        ]);
    }

    public function purchase(Request $request, int $asset): JsonResponse
    {
        $request->validate([
            'provider' => ['required', Rule::enum(PaymentProvider::class)],
            'buyer_name' => ['sometimes', 'string', 'max:150'],
            'buyer_email' => ['sometimes', 'email', 'max:150'],
            'buyer_phone' => ['sometimes', 'string', 'max:30'],
            'code' => ['sometimes', 'string'],
        ]);

        $bien = Asset::query()->whereKey($asset)->first();

        if (! $bien instanceof Asset) {
            abort(404);
        }

        $operateur = PaymentProvider::from($request->string('provider')->toString());
        $acheteur = $request->user('sanctum');

        try {
            $paiement = $acheteur instanceof User
                ? $this->paiements->intendForUser($acheteur, $bien, $operateur)
                : $this->paiements->intendForGuest(
                    $bien,
                    $operateur,
                    $request->string('buyer_name')->toString(),
                    $request->string('buyer_email')->toString(),
                    $request->string('buyer_phone')->toString(),
                    $request->string('code')->toString(),
                );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['buyer_phone' => $e->getMessage()]);
        }

        return response()->json([
            'payment' => [
                'id' => $paiement->id,
                'amount_fcfa' => $paiement->amount_fcfa,
                'provider' => $paiement->provider->value,
                'provider_label' => $paiement->provider->label(),
                'status' => $paiement->status->value,
            ],
            'message' => 'Réglez le montant auprès de l\'opérateur. Votre lien de rapport vous sera transmis '
                .'dès la confirmation du paiement.',
        ], 201);
    }

    /**
     * Lecture par jeton. Aucune authentification : le jeton EST le droit
     * d'accès, et il a été acheté par une personne identifiée.
     */
    public function show(string $token): JsonResponse
    {
        try {
            $rapport = $this->rapports->read($token);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($rapport);
    }
}
