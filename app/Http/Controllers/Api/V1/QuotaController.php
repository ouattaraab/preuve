<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\QuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * État du quota d'enregistrement et du dû d'abonnement (ST-0804, ST-0805).
 *
 * Sert à informer, jamais à barrer : le quota n'empêche pas d'enregistrer, il
 * dit ce qui reste et ce que coûte la suite.
 */
final class QuotaController extends Controller
{
    public function __construct(private readonly QuotaService $quotas) {}

    public function show(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        $societes = Company::where('owner_user_id', $utilisateur->id)->get();

        return response()->json([
            'personal' => $this->quotas->forUser($utilisateur),
            'fleets' => $societes->map(fn (Company $societe): array => [
                'company_id' => $societe->id,
                'legal_name' => $societe->legal_name,
                ...$this->quotas->forCompany($societe),
            ])->all(),
        ]);
    }
}
