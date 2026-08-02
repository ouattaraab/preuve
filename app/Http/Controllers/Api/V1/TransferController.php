<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAssetResource;
use App\Models\Asset;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Transferts de propriété (ST-0601 à ST-0603).
 *
 * Le vendeur initie vers un NUMÉRO ; l'acheteur confirme avec son code, ce qui
 * rattache son compte. Les deux confirmations déclenchent la finalisation.
 */
final class TransferController extends Controller
{
    public function __construct(private readonly TransferService $transfers) {}

    public function store(Request $request, int $asset): JsonResponse
    {
        $request->validate(['buyer_phone' => ['required', 'string', 'max:30']]);

        $bien = Asset::query()->whereKey($asset)->first();

        if (! $bien instanceof Asset) {
            abort(404);
        }

        try {
            $transfert = $this->transfers->initiate(
                $bien,
                $this->utilisateur($request),
                $request->string('buyer_phone')->toString(),
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['buyer_phone' => $e->getMessage()]);
        }

        return response()->json([
            'message' => "Un code vient d'être envoyé à l'acheteur. Le transfert expire dans 7 jours.",
            'transfer' => $this->present($transfert),
        ], 201);
    }

    public function confirm(Request $request, int $transfer): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
            'role' => ['required', 'in:seller,buyer'],
        ]);

        $transfert = Transfer::query()->whereKey($transfer)->first();

        if (! $transfert instanceof Transfer) {
            abort(404);
        }

        $utilisateur = $this->utilisateur($request);
        $code = $request->string('code')->toString();

        try {
            $transfert = $request->string('role')->toString() === 'seller'
                ? $this->transfers->confirmBySeller($transfert, $utilisateur, $code)
                : $this->transfers->confirmByBuyer($transfert, $utilisateur, $code);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        $nouveau = $transfert->created_asset_id === null
            ? null
            : Asset::query()->whereKey($transfert->created_asset_id)->first();

        return response()->json([
            'transfer' => $this->present($transfert),
            'asset' => $nouveau instanceof Asset ? new PublicAssetResource($nouveau) : null,
        ]);
    }

    public function destroy(Request $request, int $transfer): JsonResponse
    {
        $transfert = Transfer::query()->whereKey($transfer)->first();

        if (! $transfert instanceof Transfer) {
            abort(404);
        }

        try {
            $transfert = $this->transfers->cancel($transfert, $this->utilisateur($request));
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['transfer' => $e->getMessage()]);
        }

        return response()->json(['transfer' => $this->present($transfert)]);
    }

    private function utilisateur(Request $request): User
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        return $utilisateur;
    }

    /** @return array<string, mixed> */
    private function present(Transfer $transfert): array
    {
        return [
            'id' => $transfert->id,
            'status' => $transfert->status->value,
            'status_label' => $transfert->status->label(),
            'seller_confirmed' => $transfert->seller_otp_at !== null,
            'buyer_confirmed' => $transfert->buyer_otp_at !== null,
            'expires_at' => $transfert->expires_at->toIso8601String(),
            'completed_at' => $transfert->completed_at?->toIso8601String(),
        ];
    }
}
