<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KycSubmission;
use App\Models\User;
use App\Services\KycService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Vérification d'identité côté utilisateur (ST-0103).
 *
 * Friction assumée et proportionnée (CT-06) : elle n'apparaît que pour ouvrir
 * le niveau « Documenté », la réclamation et le transfert. Enregistrer un bien
 * ou consulter un statut n'en demande jamais.
 */
final class KycController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    /** État courant et motif du dernier refus, s'il y en a eu un. */
    public function show(Request $request): JsonResponse
    {
        $utilisateur = $this->utilisateur($request);
        $dernier = KycSubmission::where('user_id', $utilisateur->id)->orderByDesc('id')->first();

        return response()->json([
            'status' => $utilisateur->kycStatus()->value,
            'status_label' => $utilisateur->kycStatus()->label(),
            'can_submit' => $utilisateur->kycStatus()->allowsNewSubmission(),
            'verified_at' => $utilisateur->kyc_verified_at?->toIso8601String(),
            'last_submission' => $dernier === null ? null : [
                'id' => $dernier->id,
                'status' => $dernier->status,
                // Rendu au déposant : c'est ce qui lui permet de corriger.
                'review_reason' => $dernier->review_reason,
                'submitted_at' => $dernier->created_at?->toIso8601String(),
                'reviewed_at' => $dernier->reviewed_at?->toIso8601String(),
            ],
            'unlocks' => [
                'Faire passer vos biens au niveau « Documenté »',
                'Ouvrir une réclamation sur un bien',
                'Transférer la propriété d\'un bien',
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tailleMax = config('preuve.documents.max_kb');
        $tailleMax = is_numeric($tailleMax) ? (int) $tailleMax : 8192;

        $request->validate([
            'id_front' => ['required', 'file', 'max:'.$tailleMax, 'mimes:jpg,jpeg,png,heic,pdf'],
            'id_back' => ['required', 'file', 'max:'.$tailleMax, 'mimes:jpg,jpeg,png,heic,pdf'],
            // Le selfie ne peut pas être un PDF : c'est une prise de vue, et
            // accepter un document permettrait de soumettre une photo de photo.
            'selfie' => ['required', 'file', 'max:'.$tailleMax, 'mimes:jpg,jpeg,png,heic'],
        ]);

        $utilisateur = $this->utilisateur($request);

        try {
            $dossier = $this->kyc->submit(
                $utilisateur,
                $this->fichier($request, 'id_front'),
                $this->fichier($request, 'id_back'),
                $this->fichier($request, 'selfie'),
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['id_front' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Dossier reçu. Un agent vérifie votre identité, vous serez prévenu de l\'issue.',
            'submission' => ['id' => $dossier->id, 'status' => $dossier->status],
            'status' => $utilisateur->fresh()?->kycStatus()->value,
        ], 201);
    }

    private function fichier(Request $request, string $champ): UploadedFile
    {
        $fichier = $request->file($champ);

        if (! $fichier instanceof UploadedFile) {
            abort(422);
        }

        return $fichier;
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
