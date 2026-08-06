<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycSubmission;
use App\Models\User;
use App\Services\DocumentVault;
use App\Services\KycService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

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

    public function __construct(
        private readonly KycService $kyc,
        private readonly DocumentVault $vault,
    ) {}

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
        $personne = $dossier->user;

        return [
            'id' => $dossier->id,
            'user_id' => $dossier->user_id,
            'status' => $dossier->status,
            // CE QUE LA PERSONNE A DÉCLARÉ, à comparer avec ce que la pièce dit.
            //
            // CE N'EST PAS UNE ENTORSE À LA RÈGLE MÉTIER ABSOLUE N° 4, et il
            // faut le dire précisément : cette règle protège le lien « ce bien
            // ↔ cette personne » — le consultant ne voit pas le déclarant, le
            // propriétaire ne voit pas le consultant, le rapport payant ne nomme
            // personne. Un dossier d'identité ne porte AUCUN bien, et n'en
            // révèle aucun : rien ici ne rattache cette personne à quoi que ce
            // soit du registre.
            //
            // SANS CES CHAMPS, LA REVUE EST IMPOSSIBLE. L'agent voit déjà le nom
            // — il est imprimé sur la pièce qu'on lui affiche, et l'extraction
            // le porte souvent. Le masquer ne protégeait donc rien ; cela
            // empêchait seulement la seule vérification qui compte : le nom
            // déclaré correspond-il au document présenté ?
            //
            // MINIMISÉ À CE QUI SERT À DÉCIDER (Loi 2013-450) : le nom et
            // l'ancienneté du compte. Ni téléphone, ni adresse — ils n'aident
            // en rien à comparer un visage et un document, et les afficher
            // ferait du back-office un annuaire.
            'holder' => [
                'full_name' => $personne?->full_name,
                'account_created_at' => $personne?->created_at?->toIso8601String(),
                'kyc_status' => $personne?->kyc_status,
            ],
            // Extraction minimisée : le numéro de pièce n'y figure pas.
            'extraction' => $dossier->ocr_payload,
            'liveness_score' => $dossier->liveness_score,
            'images' => [
                'id_front' => $this->lien($dossier->id_front_ref, $dossier->id, 'front'),
                'id_back' => $this->lien($dossier->id_back_ref, $dossier->id, 'back'),
                'selfie' => $this->lien($dossier->selfie_ref, $dossier->id, 'selfie'),
            ],
            // LA SÉQUENCE, POUR QUE L'AGENT VOIE LE VISAGE TOURNER. Une photo
            // imprimée brandie devant l'objectif ne tourne pas la tête.
            //
            // Ce ne sont PAS des preuves et l'écran doit le dire : le téléphone
            // a guidé la prise, il n'a rien certifié. Elles servent à ce que
            // l'agent en juge — c'est lui qui tranche, comme avant, mais sur
            // trois prises au lieu d'une.
            'liveness' => [
                'method' => $dossier->liveness_frames === null ? null : 'device_challenge',
                'frames' => array_map(
                    fn (array $prise): array => [
                        'label' => $prise['label'],
                        'url' => $this->lien(
                            $prise['ref'],
                            $dossier->id,
                            'liveness-'.$prise['label'],
                        ),
                    ],
                    $dossier->liveness_frames ?? [],
                ),
                'notice' => $dossier->liveness_frames === null
                    ? 'Aucune séquence : dossier déposé sans prises de vivacité. '.
                        'Apprécie la concordance sur le seul selfie, comme avant.'
                    : 'Prises guidées par l\'appareil du déposant. Elles ne prouvent rien '.
                        'par elles-mêmes — c\'est à toi de voir si le visage a réellement tourné.',
            ],
            'review_reason' => $dossier->review_reason,
            'submitted_at' => $dossier->created_at?->toIso8601String(),
            'reviewed_at' => $dossier->reviewed_at?->toIso8601String(),
        ];
    }

    /**
     * Sert une pièce d'identité en clair à un agent.
     *
     * Ce sont les pièces les plus sensibles du bucket : téléchargement
     * authentifié, rôle vérifié à chaque requête, aucun cache, et rien n'est
     * jamais réécrit en clair sur le disque.
     */
    public function file(int $submission, string $part): Response
    {
        $dossier = KycSubmission::find($submission);

        if (! $dossier instanceof KycSubmission) {
            abort(404);
        }

        $reference = match ($part) {
            'front' => $dossier->id_front_ref,
            'back' => $dossier->id_back_ref,
            'selfie' => $dossier->selfie_ref,
            // Prises de vivacité. La consigne est comparée à celle ENREGISTRÉE,
            // jamais interprétée comme un chemin : le coffre reçoit une clé
            // qu'il a lui-même écrite, et rien qui vienne de l'URL.
            default => $this->prise($dossier, $part),
        };

        if (! is_string($reference) || $reference === '') {
            abort(404);
        }

        $clair = $this->vault->get($reference);

        return response($clair, 200, [
            'Content-Type' => $this->vault->mimeOf($clair),
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Référence d'une prise de vivacité, ou nul si la consigne est inconnue. */
    private function prise(KycSubmission $dossier, string $part): ?string
    {
        if (! str_starts_with($part, 'liveness-')) {
            return null;
        }

        $consigne = substr($part, strlen('liveness-'));

        foreach ($dossier->liveness_frames ?? [] as $prise) {
            if ($prise['label'] === $consigne) {
                return $prise['ref'];
            }
        }

        return null;
    }

    /** Téléchargement authentifié, jamais une URL signée : voir file(). */
    private function lien(string $reference, int $submission, string $part): ?string
    {
        return $reference === '' ? null : '/api/v1/admin/kyc/'.$submission.'/file/'.$part;
    }
}
