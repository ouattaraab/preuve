<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycSubmission;
use App\Models\User;
use App\Services\KycService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * File de vérification d'identité (ST-0103, versant back-office).
 *
 * L'agent voit les images et l'extraction minimisée, jamais le numéro de pièce :
 * il n'en a pas besoin pour apprécier la concordance entre le selfie et la
 * pièce, et le lui montrer le ferait exister ailleurs que dans son empreinte.
 *
 * La vivacité du selfie est appréciée à l'œil au MVP : aucun fournisseur de
 * détection n'est retenu, et un score automatique auquel personne ne pourrait
 * s'opposer vaudrait moins qu'un regard humain.
 */
final class KycReviewController extends Controller
{
    private const PAGE_SIZE = 25;

    public function __construct(private readonly KycService $kyc) {}

    public function index(Request $request): JsonResponse
    {
        $statut = $request->string('status')->toString();
        $filtre = in_array($statut, ['pending', 'verified', 'rejected'], true) ? $statut : 'pending';

        $file = KycSubmission::query()
            ->where('status', $filtre)
            // Les plus anciens d'abord : personne ne doit rester en attente
            // parce qu'un dossier plus récent a été traité avant.
            ->orderBy('created_at')
            ->paginate(self::PAGE_SIZE);

        return response()->json([
            'status' => $filtre,
            'submissions' => collect($file->items())->map(
                fn (KycSubmission $dossier): array => $this->present($dossier)
            )->all(),
            'meta' => [
                'current_page' => $file->currentPage(),
                'last_page' => $file->lastPage(),
                'total' => $file->total(),
            ],
        ]);
    }

    public function review(Request $request, int $submission): JsonResponse
    {
        $request->validate([
            'verified' => ['required', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $dossier = KycSubmission::query()->whereKey($submission)->first();

        if (! $dossier instanceof KycSubmission) {
            abort(404);
        }

        $motif = $request->string('reason')->toString();

        try {
            $tranche = $this->kyc->review(
                $dossier,
                $this->agent($request),
                $request->boolean('verified'),
                $motif === '' ? null : $motif,
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return response()->json(['submission' => $this->present($tranche)]);
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
    private function present(KycSubmission $dossier): array
    {
        return [
            'id' => $dossier->id,
            'user_id' => $dossier->user_id,
            'status' => $dossier->status,
            // Extraction minimisée : le numéro de pièce n'y figure pas.
            'extraction' => $dossier->ocr_payload,
            'liveness_score' => $dossier->liveness_score,
            'images' => [
                'id_front' => $this->lien($dossier->id_front_ref),
                'id_back' => $this->lien($dossier->id_back_ref),
                'selfie' => $this->lien($dossier->selfie_ref),
            ],
            'review_reason' => $dossier->review_reason,
            'submitted_at' => $dossier->created_at?->toIso8601String(),
            'reviewed_at' => $dossier->reviewed_at?->toIso8601String(),
        ];
    }

    /** Lien signé de courte durée : une pièce d'identité ne vit jamais derrière une URL publique. */
    private function lien(string $reference): ?string
    {
        $disque = config('preuve.documents.disk');
        $disque = is_string($disque) && $disque !== '' ? $disque : 's3';

        try {
            return Storage::disk($disque)->temporaryUrl($reference, now()->addMinutes(10));
        } catch (Throwable) {
            return null;
        }
    }
}
