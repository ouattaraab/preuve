<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAssetResource;
use App\Models\Asset;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\AssetScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Scan de carte grise ou de facture pour pré-remplir l'identifiant (ST-0202).
 *
 * Répond TOUJOURS 200, y compris quand rien n'a pu être lu. Un document
 * illisible n'est pas une erreur de la requête : c'est le résultat le plus
 * fréquent en conditions réelles, et le rendre en 4xx ferait afficher un écran
 * d'erreur là où l'utilisateur doit simplement passer à la saisie manuelle —
 * qui reste le chemin nominal.
 *
 * Le document scanné n'est pas conservé (voir MindeeDocumentReader).
 */
final class AssetScanController extends Controller
{
    /** Un scan ne peut porter que sur une pièce qui contient l'identifiant. */
    private const TYPES_SCANNABLES = [DocumentType::RegistrationCard, DocumentType::Invoice];

    public function __construct(private readonly AssetScanService $scans) {}

    public function store(Request $request): JsonResponse
    {
        $tailleMax = config('preuve.documents.max_kb');
        $tailleMax = is_numeric($tailleMax) ? (int) $tailleMax : 8192;

        $request->validate([
            'doc_type' => ['required', Rule::in(array_map(
                static fn (DocumentType $type): string => $type->value,
                self::TYPES_SCANNABLES,
            ))],
            'file' => ['required', 'file', 'max:'.$tailleMax, 'mimes:pdf,jpg,jpeg,png,heic'],
        ]);

        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        $fichier = $request->file('file');

        if (! $fichier instanceof UploadedFile) {
            abort(422);
        }

        $resultat = $this->scans->scan(
            $utilisateur,
            DocumentType::from($request->string('doc_type')->toString()),
            $fichier,
        );

        return $this->presenter($resultat);
    }

    /**
     * Même sélection, à partir de mots DÉJÀ LUS SUR L'APPAREIL.
     *
     * L'image ne quitte pas le téléphone. Voir AssetScanService::scanText().
     */
    public function text(Request $request): JsonResponse
    {
        $request->validate([
            'doc_type' => ['required', Rule::in(array_map(
                static fn (DocumentType $type): string => $type->value,
                self::TYPES_SCANNABLES,
            ))],
            'words' => ['required', 'array', 'max:400'],
            'words.*' => ['string', 'max:64'],
        ]);

        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        /** @var list<string> $mots */
        $mots = array_values($request->collect('words')->filter(
            static fn (mixed $mot): bool => is_string($mot) && $mot !== '',
        )->all());

        return $this->presenter($this->scans->scanText(
            $utilisateur,
            DocumentType::from($request->string('doc_type')->toString()),
            $mots,
        ));
    }

    /**
     * @param  array{scan: DocumentScan, identifier: array{value: string, type: string}|null, attributes: array<string, string>, confidence: int|null, existing: Asset|null, message: string}  $resultat
     */
    private function presenter(array $resultat): JsonResponse
    {
        $existant = $resultat['existing'];

        return response()->json([
            // À renvoyer avec l'enregistrement : c'est lui qui permet de
            // mesurer si la proposition a tenu jusqu'à la soumission.
            'scan_id' => $resultat['scan']->id,
            // `null` sans détour quand la lecture a échoué : pas de valeur
            // approchée, qui serait recopiée sans être vérifiée.
            'identifier' => $resultat['identifier'],
            'attributes' => $resultat['attributes'],
            'confidence' => $resultat['confidence'],
            // La catégorie n'est PAS devinée. Une carte grise ne dit pas si le
            // catalogue range l'engin en « voiture » ou en « moto », et
            // pré-choisir change tout le formulaire : le client dispose du
            // catalogue et de `identifier.type` pour proposer lui-même.
            'existing_asset' => $existant instanceof Asset ? new PublicAssetResource($existant) : null,
            'message' => $resultat['message'],
        ]);
    }
}
