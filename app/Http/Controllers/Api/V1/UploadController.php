<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentType;
use App\Enums\UploadStatus;
use App\Exceptions\UploadOffsetMismatch;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\TrustLevelEngine;
use App\Services\UploadSessionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Envois différés avec reprise (ST-0206, CT-05).
 *
 * Trois gestes seulement : ouvrir, pousser un morceau, demander où l'on en est.
 * La file d'attente et le réessai vivent chez le client — c'est lui qui sait
 * quand le réseau revient, et lui qui doit pouvoir fermer l'application sans
 * rien perdre. Le serveur ne fournit que ce qui rend cette file possible :
 * une identité stable et une position exacte.
 */
final class UploadController extends Controller
{
    public function __construct(
        private readonly UploadSessionService $uploads,
        private readonly TrustLevelEngine $trustLevel,
    ) {}

    /** Ouvre une session, ou rouvre celle qui existe déjà sous cet identifiant. */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            // Fourni par le client : c'est ce qui rend le réessai idempotent.
            'uuid' => ['required', 'uuid'],
            'asset_id' => ['required', 'integer', 'min:1'],
            'doc_type' => ['required', Rule::enum(DocumentType::class)],
            'filename' => ['required', 'string', 'max:250'],
            'byte_size' => ['required', 'integer', 'min:1'],
            // L'empreinte est annoncée AVANT le premier octet : elle est ce qui
            // permettra de constater, à la fin, que rien n'a été altéré.
            'checksum' => ['required', 'string', 'size:64', 'regex:/^[0-9a-fA-F]{64}$/'],
        ]);

        $utilisateur = $this->utilisateur($request);

        try {
            $session = $this->uploads->open(
                $utilisateur,
                $this->ownedAsset($utilisateur, (int) $request->integer('asset_id')),
                DocumentType::from($request->string('doc_type')->toString()),
                $request->string('uuid')->toString(),
                $request->string('filename')->toString(),
                (int) $request->integer('byte_size'),
                $request->string('checksum')->toString(),
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['uuid' => $e->getMessage()]);
        }

        return response()->json($this->present($session), 201);
    }

    /**
     * Reçoit un morceau.
     *
     * Le corps est envoyé brut plutôt qu'en `multipart` : un client qui reprend
     * à mi-fichier n'a pas d'objet fichier à présenter, seulement une tranche
     * d'octets lue à une position donnée.
     */
    public function append(Request $request, string $uuid): JsonResponse
    {
        $utilisateur = $this->utilisateur($request);
        $session = $this->uploads->find($utilisateur, $uuid);

        if (! $session instanceof UploadSession) {
            abort(404);
        }

        $position = $request->header('X-Upload-Offset');

        if (! is_string($position) || ! ctype_digit($position)) {
            throw ValidationException::withMessages([
                'offset' => 'L\'en-tête X-Upload-Offset doit indiquer la position du morceau.',
            ]);
        }

        try {
            $session = $this->uploads->append($session, $request->getContent(), (int) $position);
        } catch (UploadOffsetMismatch $e) {
            // 409 et la position RÉELLE : un client qui a perdu le fil doit
            // pouvoir reprendre, pas renvoyer quatre mégaoctets depuis le
            // début.
            return response()->json([
                'message' => $e->getMessage(),
                'received_bytes' => $e->expectedOffset,
            ], 409);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['chunk' => $e->getMessage()]);
        }

        // 422 sur une empreinte fausse : le client doit recommencer, et savoir
        // pourquoi. Répondre 200 laisserait croire à un dépôt réussi.
        $code = $session->status === UploadStatus::Failed ? 422 : 200;

        return response()->json($this->present($session), $code);
    }

    /** Où en est un envoi — le seul appel nécessaire après un redémarrage. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $session = $this->uploads->find($this->utilisateur($request), $uuid);

        if (! $session instanceof UploadSession) {
            abort(404);
        }

        return response()->json($this->present($session));
    }

    /** @return array<string, mixed> */
    private function present(UploadSession $session): array
    {
        $document = $this->uploads->document($session);

        return [
            'uuid' => $session->uuid,
            'status' => $session->status->value,
            // Ce que le client doit lire pour savoir où reprendre.
            'received_bytes' => $session->received_bytes,
            'byte_size' => $session->byte_size,
            'expires_at' => $session->expires_at->toIso8601String(),
            'failure_reason' => $session->failure_reason,
            'document_id' => $document instanceof AssetDocument ? $document->id : null,
            // La jauge n'est rendue qu'une fois la pièce constituée : un envoi
            // à moitié arrivé n'est pas un justificatif, et ne fait rien
            // monter.
            'trust' => $document instanceof AssetDocument && $session->asset instanceof Asset
                ? $this->trustLevel->progress($session->asset)
                : null,
        ];
    }

    /**
     * Un 404 plutôt qu'un 403 sur le bien d'autrui : confirmer l'existence
     * d'un bien par son identifiant interne donnerait un moyen de balayage.
     */
    private function ownedAsset(User $utilisateur, int $assetId): Asset
    {
        $bien = Asset::query()
            ->whereKey($assetId)
            ->where('owner_user_id', $utilisateur->id)
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
