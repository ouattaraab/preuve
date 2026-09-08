<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\User;
use App\Services\DocumentReviewService;
use App\Services\DocumentVault;
use App\Services\TrustLevelEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Dépôt de justificatifs par le propriétaire et jauge de progression
 * (ST-0207).
 *
 * Le renforcement vient APRÈS l'enregistrement, jamais pendant : c'est ce qui
 * permet au parcours initial de tenir en 90 secondes sans KYC (CT-02, CT-06).
 */
final class AssetDocumentController extends Controller
{
    public function __construct(
        private readonly DocumentReviewService $documents,
        private readonly TrustLevelEngine $trustLevel,
        private readonly DocumentVault $vault,
    ) {}

    /**
     * Les pièces déposées sur un bien, vues par SON détenteur.
     *
     * SANS ELLE, PERSONNE NE PEUT REVOIR CE QU'IL A ENVOYÉ. Quelqu'un qui a
     * photographié sa carte grise il y a six mois n'a aucun moyen de savoir si
     * elle est arrivée, ni si un agent l'a acceptée — et il la renverra, ou
     * pire, il croira son bien documenté alors qu'il ne l'est pas.
     */
    public function index(Request $request, int $asset): JsonResponse
    {
        $bien = $this->ownedAsset($request, $asset);

        $pieces = AssetDocument::query()
            ->where('asset_id', $bien->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'documents' => $pieces->map(fn (AssetDocument $piece): array => $this->present($piece))->all(),
            'trust' => $this->trustLevel->progress($bien),
        ]);
    }

    /**
     * Rend la pièce EN CLAIR à son déposant.
     *
     * Le contenu est déchiffré à la volée et n'est jamais réécrit sur le
     * disque : un fichier temporaire déchiffré survivrait à la requête et
     * annulerait le chiffrement au repos pour quiconque lit le disque partagé —
     * or ce compte en héberge huit autres.
     *
     * LE BIEN EST VÉRIFIÉ AVANT LA PIÈCE, et la pièce doit lui appartenir : un
     * identifiant de document deviné ne doit pas ouvrir la carte grise d'un
     * inconnu. Le refus est un 404, jamais un 403 — confirmer l'existence d'une
     * pièce apprendrait déjà quelque chose.
     */
    public function file(Request $request, int $asset, int $document): Response
    {
        $bien = $this->ownedAsset($request, $asset);

        $piece = AssetDocument::query()
            ->whereKey($document)
            ->where('asset_id', $bien->id)
            ->first();

        if (! $piece instanceof AssetDocument) {
            abort(404);
        }

        $clair = $this->documents->readable($piece);

        return response($clair, 200, [
            // Type deviné sur le CLAIR : le chiffré n'en a aucun.
            'Content-Type' => $this->vault->mimeOf($clair),
            'Content-Disposition' => 'inline',
            // Jamais en cache — ni navigateur, ni relais : c'est une pièce
            // d'identité ou un titre de propriété.
            'Cache-Control' => 'no-store, private',
            // ON NE LAISSE PAS LE NAVIGATEUR DEVINER LE TYPE : il est déjà
            // détecté sur le contenu réel (jamais l'extension déclarée).
            // `nosniff` empêche qu'un fichier polyglotte soit reclassé en HTML
            // et s'exécute dans le contexte de session de l'agent ; la CSP
            // `sandbox` le confine en dernier ressort.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function store(Request $request, int $asset): JsonResponse
    {
        $bien = $this->ownedAsset($request, $asset);

        $tailleMax = config('preuve.documents.max_kb');
        $tailleMax = is_numeric($tailleMax) ? (int) $tailleMax : 8192;

        $request->validate([
            'doc_type' => ['required', Rule::enum(DocumentType::class)],
            'file' => ['required', 'file', 'max:'.$tailleMax, 'mimes:pdf,jpg,jpeg,png,heic'],
        ]);

        $fichier = $request->file('file');

        if (! $fichier instanceof UploadedFile) {
            abort(422);
        }

        $document = $this->documents->submit(
            $bien,
            $this->utilisateur($request),
            DocumentType::from($request->string('doc_type')->toString()),
            $fichier,
        );

        return response()->json([
            // Le dépôt ne fait rien monter : la revue d'un agent seule y
            // parvient. Le dire ici évite au déposant d'attendre un effet
            // immédiat qui ne viendra pas.
            'message' => 'Justificatif reçu. Il sera examiné par un agent avant de compter dans votre niveau '.
                'de fiabilité.',
            'document' => $this->present($document),
            'trust' => $this->trustLevel->progress($bien),
        ], 201);
    }

    /** Jauge : niveau courant, niveau suivant, ce qui manque et ce qu'il apporte. */
    public function trust(Request $request, int $asset): JsonResponse
    {
        $bien = $this->ownedAsset($request, $asset);

        return response()->json([
            'trust' => $this->trustLevel->progress($bien),
            'documents' => AssetDocument::where('asset_id', $bien->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (AssetDocument $document): array => $this->present($document))
                ->all(),
        ]);
    }

    /**
     * Un propriétaire n'agit que sur SES biens. Un 404 plutôt qu'un 403 sur le
     * bien d'autrui : confirmer l'existence d'un bien par son identifiant
     * interne donnerait un moyen de balayage.
     */
    private function ownedAsset(Request $request, int $asset): Asset
    {
        $utilisateur = $this->utilisateur($request);

        $bien = Asset::query()
            ->whereKey($asset)
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

    /** @return array<string, mixed> */
    private function present(AssetDocument $document): array
    {
        return [
            'id' => $document->id,
            'doc_type' => $document->doc_type->value,
            'doc_type_label' => $document->doc_type->label(),
            'review_status' => $document->review_status->value,
            'review_status_label' => $document->review_status->label(),
            // Le motif de refus est rendu au déposant : c'est ce qui lui permet
            // de corriger, et ce qui rend la décision contestable.
            'review_reason' => $document->review_reason,
            'submitted_at' => $document->created_at?->toIso8601String(),
            // Téléchargement AUTHENTIFIÉ, et non lien signé : la pièce est
            // chiffrée au repos — un lien direct rendrait du charabia — et un
            // lien signé est une capacité au porteur, qui ouvre la pièce à
            // quiconque le recopie. Ici la propriété est revérifiée à chaque
            // requête.
            'file_url' => '/api/v1/assets/'.$document->asset_id.'/documents/'.$document->id.'/file',
        ];
    }
}
