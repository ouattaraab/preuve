<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ClaimDecision;
use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\ClaimEvidence;
use App\Models\User;
use App\Services\ClaimArbitrationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Instruction et arbitrage côté back-office (ST-0502, ST-0505).
 *
 * L'agent voit les deux dossiers côte à côte, la grille calcule un écart, et
 * c'est lui qui décide — en motivant. La grille assiste, elle ne tranche pas :
 * une décision de propriété prononcée par une somme de points sans un mot
 * d'explication serait incontestable, donc arbitraire.
 */
final class ClaimReviewController extends Controller
{
    private const PAGE_SIZE = 25;

    public function __construct(private readonly ClaimArbitrationService $arbitrage) {}

    public function index(Request $request): JsonResponse
    {
        $statut = $request->string('status')->toString() ?: 'submitted';

        $file = Claim::query()
            ->where('status', $statut)
            // Les plus anciens d'abord : un bien gelé le reste tant que le
            // dossier n'est pas instruit.
            ->orderBy('created_at')
            ->paginate(self::PAGE_SIZE);

        return response()->json([
            'status' => $statut,
            'claims' => collect($file->items())->map(
                fn (Claim $dossier): array => $this->present($dossier)
            )->all(),
            'meta' => [
                'current_page' => $file->currentPage(),
                'last_page' => $file->lastPage(),
                'total' => $file->total(),
            ],
        ]);
    }

    public function show(int $claim): JsonResponse
    {
        return response()->json(['claim' => $this->present($this->dossier($claim), avecPieces: true)]);
    }

    public function admissibility(Request $request, int $claim): JsonResponse
    {
        $request->validate([
            'admissible' => ['required', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $motif = $request->string('reason')->toString();

        try {
            $dossier = $this->arbitrage->markAdmissible(
                $this->dossier($claim),
                $this->agent($request),
                $request->boolean('admissible'),
                $motif === '' ? null : $motif,
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return response()->json(['claim' => $this->present($dossier)]);
    }

    public function discardEvidence(Request $request, int $evidence): JsonResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:500']]);

        $piece = ClaimEvidence::query()->whereKey($evidence)->first();

        if (! $piece instanceof ClaimEvidence) {
            abort(404);
        }

        try {
            $ecartee = $this->arbitrage->discardEvidence(
                $piece,
                $this->agent($request),
                $request->string('note')->toString(),
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['note' => $e->getMessage()]);
        }

        return response()->json([
            'evidence' => ['id' => $ecartee->id, 'weight_applied' => $ecartee->weight_applied],
            'scores' => $this->arbitrage->score($this->dossier($ecartee->claim_id)),
        ]);
    }

    public function decide(Request $request, int $claim): JsonResponse
    {
        $request->validate([
            'decision' => ['required', Rule::enum(ClaimDecision::class)],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $dossier = $this->arbitrage->decide(
                $this->dossier($claim),
                $this->agent($request),
                ClaimDecision::from($request->string('decision')->toString()),
                $request->string('reason')->toString(),
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['decision' => $e->getMessage()]);
        }

        return response()->json(['claim' => $this->present($dossier)]);
    }

    private function dossier(int $claim): Claim
    {
        $dossier = Claim::query()->whereKey($claim)->first();

        if (! $dossier instanceof Claim) {
            abort(404);
        }

        return $dossier;
    }

    private function agent(Request $request): User
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        return $utilisateur;
    }

    /** @return array<string, mixed> */
    private function present(Claim $dossier, bool $avecPieces = false): array
    {
        $scores = $this->arbitrage->score($dossier);

        $vue = [
            'id' => $dossier->id,
            'asset_id' => $dossier->asset_id,
            'status' => $dossier->status->value,
            'respondent_deadline' => $dossier->respondent_deadline?->toIso8601String(),
            'scores' => $scores,
            // La suggestion de la grille est affichée comme telle : l'agent
            // reste libre de s'en écarter, et de le motiver.
            'suggested_decision' => $scores['suggested']->value,
            'decision' => $dossier->decision?->value,
            'decision_reason' => $dossier->decision_reason,
        ];

        if ($avecPieces) {
            $vue['evidences'] = $dossier->evidences->map(fn (ClaimEvidence $piece): array => [
                'id' => $piece->id,
                'party' => $piece->party,
                'type' => $piece->evidence_type->value,
                'type_label' => $piece->evidence_type->label(),
                'default_weight' => $piece->evidence_type->weight(),
                'weight_applied' => $piece->weight_applied,
                'document_date' => $piece->document_date,
                'file_sha256' => $piece->file_sha256,
                'agent_note' => $piece->agent_note,
            ])->all();
        }

        return $vue;
    }
}
