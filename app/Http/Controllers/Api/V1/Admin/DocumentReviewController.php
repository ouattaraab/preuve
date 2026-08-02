<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DocumentReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\User;
use App\Services\DocumentReviewService;
use App\Services\TrustLevelEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * File de revue des justificatifs (ST-0208).
 *
 * Une décision d'agent a un effet direct sur ce qu'un acheteur voit : elle est
 * donc journalisée dans la chaîne d'audit avec son motif, et déclenche le
 * recalcul du niveau de fiabilité — à la hausse comme à la baisse.
 */
final class DocumentReviewController extends Controller
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly DocumentReviewService $documents,
        private readonly TrustLevelEngine $trustLevel,
    ) {}

    /** File d'attente, les plus anciennes d'abord — personne ne doit être oublié. */
    public function index(Request $request): JsonResponse
    {
        $statut = $request->string('status')->toString();
        $filtre = DocumentReviewStatus::tryFrom($statut) ?? DocumentReviewStatus::Pending;

        $file = AssetDocument::query()
            ->where('review_status', $filtre->value)
            ->orderBy('created_at')
            ->paginate(self::PAGE_SIZE);

        return response()->json([
            'status' => $filtre->value,
            'documents' => collect($file->items())->map(
                fn (AssetDocument $document): array => $this->present($document)
            )->all(),
            'meta' => [
                'current_page' => $file->currentPage(),
                'last_page' => $file->lastPage(),
                'total' => $file->total(),
            ],
        ]);
    }

    public function review(Request $request, int $document): JsonResponse
    {
        $request->validate([
            'status' => ['required', Rule::in(['accepted', 'rejected', 'suspected_forgery'])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $piece = AssetDocument::query()->whereKey($document)->first();

        if (! $piece instanceof AssetDocument) {
            abort(404);
        }

        $issue = DocumentReviewStatus::from($request->string('status')->toString());
        $motif = $request->string('reason')->toString();

        try {
            $revue = $this->documents->review(
                $piece,
                $this->agent($request),
                $issue,
                $motif === '' ? null : $motif,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        $bien = $revue->asset;

        return response()->json([
            'document' => $this->present($revue),
            'trust' => $bien instanceof Asset ? $this->trustLevel->progress($bien->fresh() ?? $bien) : null,
        ]);
    }

    /**
     * Accorde ou retire le contrôle croisé qui conditionne « Vérifié » (F3).
     * Il ne supplée jamais les conditions de « Documenté » : un agent confirme
     * ce qui existe, il ne crée pas de preuve.
     */
    public function verify(Request $request, int $asset): JsonResponse
    {
        $request->validate(['verified' => ['required', 'boolean']]);

        $bien = Asset::query()->whereKey($asset)->first();

        if (! $bien instanceof Asset) {
            abort(404);
        }

        $misAJour = $this->documents->setBackOfficeVerification(
            $bien,
            $this->agent($request),
            $request->boolean('verified'),
        );

        return response()->json(['trust' => $this->trustLevel->progress($misAJour)]);
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
    private function present(AssetDocument $document): array
    {
        return [
            'id' => $document->id,
            'asset_id' => $document->asset_id,
            'doc_type' => $document->doc_type->value,
            'doc_type_label' => $document->doc_type->label(),
            'review_status' => $document->review_status->value,
            'review_reason' => $document->review_reason,
            'file_sha256' => $document->file_sha256,
            // Lien signé de courte durée : la pièce ne transite jamais par une
            // URL publique, et le lien ne survit pas à la session de revue.
            'file_url' => $this->documents->temporaryUrl($document),
            'submitted_at' => $document->created_at?->toIso8601String(),
            'reviewed_at' => $document->reviewed_at?->toIso8601String(),
        ];
    }
}
