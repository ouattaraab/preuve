<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EvidenceType;
use App\Exceptions\FraisDossierImpayesException;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Claim;
use App\Models\User;
use App\Services\ClaimArbitrationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Dépôt d'une réclamation par la victime (ST-0501), et versement des pièces
 * par l'une ou l'autre partie (ST-0504).
 *
 * Le mis en cause verse ses justificatifs par la même route : c'est le même
 * geste, et lui imposer un parcours distinct compliquerait sans raison le seul
 * moment où il peut se défendre.
 */
final class ClaimController extends Controller
{
    public function __construct(private readonly ClaimArbitrationService $arbitrage) {}

    /**
     * Ouvre un dossier à partir de la RÉFÉRENCE PUBLIQUE du bien.
     *
     * C'EST LE SEUL CHEMIN QU'UNE VICTIME PUISSE EMPRUNTER. Réclamer suppose de
     * désigner le bien d'un AUTRE, et cet autre n'a évidemment pas communiqué
     * son identifiant interne — la consultation publique le tait, précisément
     * pour qu'on ne puisse pas balayer le registre. La référence opaque, elle,
     * s'obtient légitimement : elle figure sur le verdict qu'on vient de lire,
     * et dans le refus qu'on reçoit en tentant d'enregistrer un bien déjà pris.
     *
     * Sans cette route, `POST /assets/{id}/claims` n'était atteignable par
     * personne d'autre que le détenteur lui-même, à qui elle ne sert à rien :
     * le recours de l'EP-05 existait en base et nulle part ailleurs.
     */
    public function storeByReference(Request $request): JsonResponse
    {
        $request->validate(['public_ref' => ['required', 'string', 'max:32']]);

        $bien = Asset::query()
            ->where('public_ref', mb_strtoupper(trim($request->string('public_ref')->toString())))
            // L'enregistrement ACTIF : un bien archivé appartient au passé, et
            // le contester reviendrait à contester une ligne d'historique.
            ->whereNotNull('active_flag')
            ->first();

        if (! $bien instanceof Asset) {
            abort(404);
        }

        return $this->ouvrir($bien, $request);
    }

    public function store(Request $request, int $asset): JsonResponse
    {
        $bien = Asset::query()->whereKey($asset)->first();

        if (! $bien instanceof Asset) {
            abort(404);
        }

        return $this->ouvrir($bien, $request);
    }

    private function ouvrir(Asset $bien, Request $request): JsonResponse
    {
        try {
            $dossier = $this->arbitrage->open($bien, $this->utilisateur($request));
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['asset' => $e->getMessage()]);
        }

        return response()->json(['claim' => $this->present($dossier)], 201);
    }

    public function addEvidence(Request $request, int $claim): JsonResponse
    {
        $tailleMax = config('preuve.documents.max_kb');
        $tailleMax = is_numeric($tailleMax) ? (int) $tailleMax : 8192;

        $request->validate([
            'evidence_type' => ['required', Rule::enum(EvidenceType::class)],
            'file' => ['sometimes', 'file', 'max:'.$tailleMax, 'mimes:pdf,jpg,jpeg,png,heic'],
            'document_date' => ['sometimes', 'nullable', 'date'],
        ]);

        $dossier = $this->dossier($claim);
        $utilisateur = $this->utilisateur($request);
        $partie = $this->partieDe($dossier, $utilisateur);

        $fichier = $request->file('file');

        try {
            $piece = $this->arbitrage->addEvidence(
                $dossier,
                $partie,
                EvidenceType::from($request->string('evidence_type')->toString()),
                $fichier instanceof UploadedFile ? $fichier : null,
                $request->string('document_date')->toString() ?: null,
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['evidence_type' => $e->getMessage()]);
        }

        return response()->json([
            'evidence' => [
                'id' => $piece->id,
                'party' => $piece->party,
                'type' => $piece->evidence_type->value,
                'type_label' => $piece->evidence_type->label(),
            ],
        ], 201);
    }

    public function submit(Request $request, int $claim): JsonResponse
    {
        $dossier = $this->dossier($claim);

        if ($dossier->claimant_user_id !== $this->utilisateur($request)->id) {
            abort(404);
        }

        try {
            $depose = $this->arbitrage->submit($dossier);
        } catch (FraisDossierImpayesException $e) {
            // 402 et non 422 : le dossier n'a rien d'incorrect, il attend un
            // règlement. Le client doit router vers le paiement, et le montant
            // l'accompagne — un refus qui ne dit pas combien ne laisse que
            // l'abandon, c'est-à-dire une victime qui renonce à son recours.
            return response()->json([
                'message' => $e->getMessage(),
                'fee' => $e->frais,
            ], 402);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['claim' => $e->getMessage()]);
        }

        return response()->json([
            'message' => $depose->status->value === 'contradictory'
                ? 'Votre réclamation est recevable : le bien est gelé le temps de l\'instruction.'
                : 'Votre réclamation est déposée. Un agent en examine la recevabilité sous 48 heures.',
            'claim' => $this->present($depose),
        ]);
    }

    public function show(Request $request, int $claim): JsonResponse
    {
        $dossier = $this->dossier($claim);
        $this->partieDe($dossier, $this->utilisateur($request));

        return response()->json(['claim' => $this->present($dossier)]);
    }

    public function appeal(Request $request, int $claim): JsonResponse
    {
        $dossier = $this->dossier($claim);
        $utilisateur = $this->utilisateur($request);
        $this->partieDe($dossier, $utilisateur);

        try {
            $enAppel = $this->arbitrage->appeal($dossier, $utilisateur);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['claim' => $e->getMessage()]);
        }

        return response()->json(['claim' => $this->present($enAppel)]);
    }

    private function dossier(int $claim): Claim
    {
        $dossier = Claim::query()->whereKey($claim)->first();

        if (! $dossier instanceof Claim) {
            abort(404);
        }

        return $dossier;
    }

    /**
     * Détermine de quel côté se trouve l'utilisateur — et refuse l'accès à qui
     * n'est ni réclamant ni mis en cause. Un 404 plutôt qu'un 403 : confirmer
     * l'existence d'un dossier révélerait qu'un bien est contesté.
     */
    private function partieDe(Claim $dossier, User $utilisateur): string
    {
        if ($dossier->claimant_user_id === $utilisateur->id) {
            return 'claimant';
        }

        $bien = $dossier->asset;

        if ($bien instanceof Asset && $bien->owner_user_id === $utilisateur->id) {
            return 'respondent';
        }

        abort(404);
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
    private function present(Claim $dossier): array
    {
        return [
            'id' => $dossier->id,
            'status' => $dossier->status->value,
            'status_label' => $dossier->status->label(),
            'respondent_deadline' => $dossier->respondent_deadline?->toIso8601String(),
            'decision' => $dossier->decision?->value,
            'decision_label' => $dossier->decision?->label(),
            'decision_reason' => $dossier->decision_reason,
            // Les scores ne sont rendus qu'une fois la décision prononcée :
            // les exposer pendant l'instruction inviterait à produire des
            // pièces pour le score plutôt que pour la vérité.
            'scores' => $dossier->decided_at === null ? null : [
                'claimant' => $dossier->claimant_score,
                'respondent' => $dossier->respondent_score,
            ],
            // Les frais sont annoncés, jamais opposés : le dossier suit son
            // cours qu'ils soient réglés ou non (ST-0501).
            'fee' => $this->arbitrage->fee($dossier),
            'export_sha256' => $dossier->export_sha256,
            'submitted_at' => $dossier->created_at?->toIso8601String(),
        ];
    }
}
