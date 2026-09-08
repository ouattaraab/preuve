<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation des comptes entreprise (ST-0901, ST-0104).
 *
 * Une société non validée existe mais ne bénéficie de rien : la validation
 * back-office conditionne l'accès à l'offre flotte. C'est le seul contrôle
 * humain entre un RCCM saisi à la main et un tarif professionnel.
 */
final class CompanyValidationController extends Controller
{
    private const PAGE_SIZE = 25;

    public function __construct(private readonly AuditChain $auditChain) {}

    public function index(Request $request): JsonResponse
    {
        $statut = $request->string('status')->toString() ?: 'pending';

        $file = Company::query()
            ->where('validation_status', $statut)
            ->orderBy('created_at')
            ->paginate(self::PAGE_SIZE);

        return response()->json([
            'status' => $statut,
            'companies' => collect($file->items())->map(fn (Company $societe): array => [
                'id' => $societe->id,
                'legal_name' => $societe->legal_name,
                'rccm_number' => $societe->rccm_number,
                'validation_status' => $societe->validation_status,
                'free_fleet_quota' => $societe->free_fleet_quota,
            ])->all(),
            'meta' => [
                'current_page' => $file->currentPage(),
                'last_page' => $file->lastPage(),
                'total' => $file->total(),
            ],
        ]);
    }

    public function validateCompany(Request $request, int $company): JsonResponse
    {
        $request->validate([
            'status' => ['required', Rule::in(['validated', 'rejected'])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $societe = Company::query()->whereKey($company)->first();

        if (! $societe instanceof Company) {
            abort(404);
        }

        $statut = $request->string('status')->toString();
        $motif = $request->string('reason')->toString();

        if ($statut === 'rejected' && $motif === '') {
            // Un refus sans raison laisse le loueur sans rien à corriger, et
            // sans recours.
            throw ValidationException::withMessages([
                'reason' => 'Un refus de validation doit être motivé.',
            ]);
        }

        $agent = $request->user();
        $agentId = $agent instanceof User ? $agent->id : null;

        $this->auditChain->append(
            ActorType::Agent,
            $agentId,
            $statut === 'validated' ? 'company.validated' : 'company.rejected',
            'company',
            $societe->id,
            ['reason' => $motif === '' ? null : $motif],
        );

        $societe->forceFill([
            'validation_status' => $statut,
            'validated_at' => $statut === 'validated' ? now() : null,
        ])->save();

        return response()->json([
            'company' => [
                'id' => $societe->id,
                'validation_status' => $societe->validation_status,
            ],
        ]);
    }
}
