<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\ClaimDecision;
use App\Enums\ClaimStatus;
use App\Enums\EvidenceType;
use App\Enums\LifeStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\TriggerType;
use App\Models\Asset;
use App\Models\Claim;
use App\Models\ClaimEvidence;
use App\Models\Payment;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Réclamation et arbitrage (EP-05, §4.5 et §5.2).
 *
 * C'est le seul recours d'une victime dont le bien a été enregistré par
 * quelqu'un d'autre — et le seul chemin vers « Litige en cours ». Tout le
 * dispositif tient sur un équilibre : filtrer les dossiers vides SANS écarter
 * les victimes.
 *
 * LA RECEVABILITÉ EST LARGE (ST-0502). Un document nominatif ou un récépissé de
 * plainte suffit à ouvrir le dossier. Tout le reste part en revue manuelle
 * plutôt qu'au rejet : une victime qui n'a pas encore pu porter plainte, ou
 * dont les papiers ont été volés avec le bien, doit pouvoir être entendue.
 *
 * LE GEL EST IMMÉDIAT (ST-0503). Dès la recevabilité, le bien passe en
 * « Litige en cours » et devient invendable au regard de quiconque le consulte.
 * Attendre l'issue de l'instruction laisserait au fraudeur les semaines dont il
 * a besoin pour vendre.
 *
 * LA GRILLE ASSISTE, ELLE NE DÉCIDE PAS (ST-0505). Elle calcule un écart ;
 * l'agent motive. Une décision de propriété prononcée par une somme de points
 * sans un mot d'explication serait incontestable, donc arbitraire.
 *
 * « LITIGE NON TRANCHÉ » N'EST PAS UN ÉCHEC. En deçà de 20 points d'écart, la
 * plateforme reconnaît que les preuves ne départagent pas les parties : le bien
 * reste gelé, l'export horodaté est remis aux deux, et le litige relève de la
 * justice. Forcer une décision reviendrait à trancher un droit de propriété sur
 * une intime conviction — ce qui appartient au juge, pas à un opérateur privé.
 */
final class ClaimArbitrationService
{
    /** Écart de points en deçà duquel la plateforme ne tranche pas (§5.2). */
    public const DECISION_GAP = 20;

    /** Délai laissé au mis en cause pour produire ses pièces (ST-0504). */
    private const CONTRADICTORY_DAYS = 15;

    public function __construct(
        private readonly StatusTransitionService $transitions,
        private readonly TransferService $transfers,
        private readonly AuditChain $auditChain,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Frais de dossier attendus (ST-0501), remboursés si la réclamation
     * aboutit.
     *
     * ILS NE CONDITIONNENT PAS LE DÉPÔT. Leur rôle est de décourager les
     * dossiers de nuisance — contester la propriété d'autrui doit coûter
     * quelque chose — mais une victime démunie ne doit pas se voir fermer son
     * seul recours faute de 2000 francs. Le dossier suit son cours ; le
     * paiement est attendu, non exigé, et son absence est visible de l'agent.
     *
     * @return array{amount_fcfa: int, paid: bool, refundable: bool}
     */
    public function fee(Claim $dossier): array
    {
        $montant = config('preuve.claim_fee_fcfa');
        $montant = is_numeric($montant) && (int) $montant > 0 ? (int) $montant : 2000;

        $regle = Payment::query()
            ->where('purpose', PaymentPurpose::ClaimFee->value)
            ->where('related_id', $dossier->id)
            ->where('status', PaymentStatus::Succeeded->value)
            ->exists();

        return [
            'amount_fcfa' => $montant,
            'paid' => $regle,
            // Remboursables si la réclamation est fondée : le réclamant a eu
            // raison de contester, il n'a pas à en supporter le coût.
            'refundable' => $dossier->decision === ClaimDecision::TransferToClaimant,
        ];
    }

    /**
     * Ouvre une réclamation en brouillon. Le KYC est exigé : contester la
     * propriété d'autrui engage, et un dossier anonyme serait un outil de
     * nuisance gratuit.
     *
     * @throws DomainException
     */
    public function open(Asset $bien, User $reclamant): Claim
    {
        if (! $reclamant->hasVerifiedIdentity()) {
            throw new DomainException(
                'Vérifiez votre identité avant de déposer une réclamation : elle engage votre responsabilité.'
            );
        }

        if ($bien->owner_user_id === $reclamant->id) {
            throw new DomainException('Ce bien est déjà enregistré à votre nom.');
        }

        if ($bien->active_flag === null) {
            throw new DomainException('Cet enregistrement est archivé : réclamez sur l\'enregistrement actif.');
        }

        $existante = Claim::query()
            ->where('asset_id', $bien->id)
            ->where('claimant_user_id', $reclamant->id)
            ->whereNotIn('status', [ClaimStatus::Closed->value, ClaimStatus::Decided->value])
            ->first();

        if ($existante instanceof Claim) {
            // Reprise du brouillon plutôt que création d'un doublon : un
            // dossier interrompu par une coupure réseau doit se retrouver
            // (CT-05).
            return $existante;
        }

        return Claim::create([
            'asset_id' => $bien->id,
            'claimant_user_id' => $reclamant->id,
            'status' => ClaimStatus::Draft,
        ]);
    }

    /**
     * Verse une pièce au dossier. Le poids par défaut de la grille est appliqué
     * d'emblée ; l'agent le confirmera ou l'annulera à l'instruction.
     *
     * @throws DomainException
     */
    public function addEvidence(
        Claim $dossier,
        string $partie,
        EvidenceType $type,
        ?UploadedFile $fichier = null,
        ?string $dateDocument = null,
    ): ClaimEvidence {
        if (! $dossier->status->acceptsEvidence()) {
            throw new DomainException('Ce dossier n\'accepte plus de pièces.');
        }

        if (! in_array($partie, ['claimant', 'respondent'], true)) {
            throw new DomainException('Partie inconnue.');
        }

        $chemin = null;
        $empreinte = null;

        if ($fichier instanceof UploadedFile) {
            $chemin = (string) $fichier->store('claims/'.$dossier->id, $this->disk());
            $empreinte = hash_file('sha256', $fichier->getRealPath());
        }

        return ClaimEvidence::create([
            'claim_id' => $dossier->id,
            'party' => $partie,
            'evidence_type' => $type,
            'file_ref' => $chemin,
            'file_sha256' => is_string($empreinte) ? $empreinte : null,
            'document_date' => $dateDocument,
            'weight_applied' => $type->weight(),
        ]);
    }

    /**
     * Dépose le dossier et statue sur sa recevabilité.
     *
     * @throws DomainException
     */
    public function submit(Claim $dossier): Claim
    {
        if ($dossier->status !== ClaimStatus::Draft) {
            throw new DomainException('Ce dossier a déjà été déposé.');
        }

        $recevable = $this->isAutomaticallyAdmissible($dossier);

        $this->auditChain->append(
            ActorType::User,
            $dossier->claimant_user_id,
            'claim.submitted',
            'claim',
            $dossier->id,
            ['asset_id' => $dossier->asset_id, 'auto_admissible' => $recevable],
        );

        $dossier->forceFill([
            'status' => $recevable ? ClaimStatus::Admissible : ClaimStatus::Submitted,
        ])->save();

        if ($recevable) {
            $this->freeze($dossier);
        }

        return $dossier->fresh() ?? $dossier;
    }

    /**
     * Recevabilité prononcée par un agent, après revue manuelle (ST-0502).
     *
     * @throws DomainException
     */
    public function markAdmissible(Claim $dossier, User $agent, bool $recevable, ?string $motif = null): Claim
    {
        if ($dossier->status !== ClaimStatus::Submitted) {
            throw new DomainException('Seul un dossier déposé peut être déclaré recevable ou non.');
        }

        if (! $recevable && ($motif === null || trim($motif) === '')) {
            throw new DomainException(
                'Un refus de recevabilité doit être motivé : sans raison, la victime est écartée sans recours.'
            );
        }

        $this->auditChain->append(
            ActorType::Agent,
            $agent->id,
            $recevable ? 'claim.admissible' : 'claim.rejected',
            'claim',
            $dossier->id,
            ['reason' => $motif],
        );

        $dossier->forceFill([
            'status' => $recevable ? ClaimStatus::Admissible : ClaimStatus::Closed,
            'decision_reason' => $recevable ? null : $motif,
        ])->save();

        if ($recevable) {
            $this->freeze($dossier);

            return $dossier->fresh() ?? $dossier;
        }

        $this->notifications->notify(
            $dossier->claimantOrFail(),
            NotificationType::ClaimDecided,
            'Votre réclamation n\'a pas été retenue',
            'Motif : '.((string) $motif),
        );

        return $dossier->fresh() ?? $dossier;
    }

    /**
     * Scores de la grille, tels qu'ils ressortent des pièces versées.
     *
     * @return array{claimant: int, respondent: int, gap: int, suggested: ClaimDecision}
     */
    public function score(Claim $dossier): array
    {
        $reclamant = 0;
        $misEnCause = 0;

        foreach ($dossier->evidences as $piece) {
            if ($piece->party === 'claimant') {
                $reclamant += $piece->weight_applied;
            } else {
                $misEnCause += $piece->weight_applied;
            }
        }

        $ecart = abs($reclamant - $misEnCause);

        $suggestion = match (true) {
            $ecart < self::DECISION_GAP => ClaimDecision::Unresolved,
            $reclamant > $misEnCause => ClaimDecision::TransferToClaimant,
            default => ClaimDecision::KeepCurrent,
        };

        return [
            'claimant' => $reclamant,
            'respondent' => $misEnCause,
            'gap' => $ecart,
            'suggested' => $suggestion,
        ];
    }

    /**
     * Écarte une pièce suspectée de falsification : son poids tombe à zéro
     * (§5.2). L'affaire mérite alors une escalade, que la note de l'agent
     * consigne.
     *
     * @throws DomainException
     */
    public function discardEvidence(ClaimEvidence $piece, User $agent, string $note): ClaimEvidence
    {
        if (trim($note) === '') {
            throw new DomainException('Écarter une pièce exige une note : elle fonde la décision qui suivra.');
        }

        $this->auditChain->append(
            ActorType::Agent,
            $agent->id,
            'claim.evidence_discarded',
            'claim_evidence',
            $piece->id,
            ['claim_id' => $piece->claim_id, 'note' => $note],
        );

        $piece->forceFill(['weight_applied' => 0, 'agent_note' => $note])->save();

        return $piece;
    }

    /**
     * Prononce la décision. La motivation est OBLIGATOIRE, et l'agent peut
     * s'écarter de la suggestion de la grille — c'est lui qui décide, pas elle.
     *
     * @throws DomainException
     */
    public function decide(Claim $dossier, User $agent, ClaimDecision $decision, string $motivation): Claim
    {
        if (! in_array($dossier->status, [
            ClaimStatus::Admissible,
            ClaimStatus::Contradictory,
            ClaimStatus::UnderReview,
            ClaimStatus::Appealed,
        ], true)) {
            throw new DomainException('Ce dossier n\'est pas en état d\'être tranché.');
        }

        if (trim($motivation) === '') {
            throw new DomainException(
                'La décision doit être motivée : une décision de propriété sans explication est '.
                'incontestable, donc arbitraire.'
            );
        }

        // Un appel est instruit par un agent DIFFÉRENT (§5.2) : se relire
        // soi-même n'est pas un recours.
        if ($dossier->status === ClaimStatus::Appealed && $dossier->decided_by === $agent->id) {
            throw new DomainException(
                'Un appel doit être instruit par un autre agent que celui de la première décision.'
            );
        }

        $scores = $this->score($dossier);

        $this->auditChain->append(
            ActorType::Agent,
            $agent->id,
            'claim.decided',
            'claim',
            $dossier->id,
            [
                'decision' => $decision->value,
                'claimant_score' => $scores['claimant'],
                'respondent_score' => $scores['respondent'],
                'suggested' => $scores['suggested']->value,
                'reason' => $motivation,
            ],
        );

        $dossier->forceFill([
            'status' => ClaimStatus::Decided,
            'decision' => $decision,
            'decision_reason' => $motivation,
            'claimant_score' => $scores['claimant'],
            'respondent_score' => $scores['respondent'],
            'decided_by' => $agent->id,
            'decided_at' => now(),
        ])->save();

        $this->applyDecision($dossier->fresh() ?? $dossier, $agent, $decision);

        if ($decision === ClaimDecision::TransferToClaimant) {
            // Le réclamant a eu raison de contester : il n'a pas à supporter le
            // coût du dossier.
            $this->markFeeRefundable($dossier);
        }

        return $dossier->fresh() ?? $dossier;
    }

    /**
     * Ouvre l'appel interne, unique (§5.2).
     *
     * @throws DomainException
     */
    public function appeal(Claim $dossier, User $partie): Claim
    {
        if ($dossier->status !== ClaimStatus::Decided) {
            throw new DomainException('Seule une décision peut être contestée.');
        }

        if ($dossier->appeal_of !== null) {
            throw new DomainException('Cette décision a déjà fait l\'objet d\'un appel : le recours est unique.');
        }

        if ($dossier->decided_at !== null && $dossier->decided_at->addDays(15)->isPast()) {
            throw new DomainException('Le délai d\'appel de 15 jours est écoulé.');
        }

        $this->auditChain->append(
            ActorType::User,
            $partie->id,
            'claim.appealed',
            'claim',
            $dossier->id,
            [],
        );

        $dossier->forceFill([
            'status' => ClaimStatus::Appealed,
            'appeal_of' => $dossier->id,
        ])->save();

        return $dossier->fresh() ?? $dossier;
    }

    /**
     * Produit l'export horodaté remis aux deux parties (ST-0506).
     *
     * Son empreinte est ce qui le rend opposable : une partie qui le verse à un
     * dossier judiciaire doit pouvoir démontrer qu'il n'a pas été retouché. Le
     * document est volontairement du texte lisible — il sera lu par un
     * magistrat, sans accès au code ni à la base.
     */
    public function export(Claim $dossier): string
    {
        $scores = $this->score($dossier);
        $bien = $dossier->asset;

        $texte = implode("\n", [
            'PREUVE — décision de réclamation',
            '================================',
            '',
            'Dossier            : #'.$dossier->id,
            'Bien (réf. publique) : '.($bien instanceof Asset ? $bien->public_ref : '—'),
            'Décision           : '.($dossier->decision?->label() ?? '—'),
            'Prononcée le       : '.($dossier->decided_at?->toIso8601String() ?? '—'),
            '',
            'PONDÉRATION DES PREUVES (grille §5.2)',
            'Réclamant          : '.$scores['claimant'].' points',
            'Détenteur enregistré : '.$scores['respondent'].' points',
            'Écart              : '.$scores['gap'].' points (seuil de décision : '.self::DECISION_GAP.')',
            '',
            'MOTIVATION',
            (string) $dossier->decision_reason,
            '',
            'PORTÉE DE CE DOCUMENT',
            "PREUVE est un registre déclaratif privé. Cette décision règle l'inscription au registre ;",
            'elle ne vaut pas jugement de propriété et ne prive aucune partie de son droit de saisir',
            'la justice. En cas de litige non tranché, ce document est précisément destiné à cet usage.',
            '',
            "Ce document ne contient l'identité d'aucune partie : le registre ne divulgue jamais",
            'les identités, y compris entre parties à un même dossier.',
        ]);

        $empreinte = hash('sha256', $texte);
        $chemin = 'claims/'.$dossier->id.'/decision-'.$empreinte.'.txt';

        Storage::disk($this->disk())->put($chemin, $texte);

        $dossier->forceFill(['export_sha256' => $empreinte, 'export_ref' => $chemin])->save();

        return $texte;
    }

    /**
     * Marque les frais comme remboursables. Le remboursement lui-même passe par
     * l'opérateur de paiement : la plateforme constate le droit, elle
     * n'exécute pas le virement.
     */
    private function markFeeRefundable(Claim $dossier): void
    {
        $dossier->forceFill(['fee_refunded' => true])->save();

        Payment::query()
            ->where('purpose', PaymentPurpose::ClaimFee->value)
            ->where('related_id', $dossier->id)
            ->where('status', PaymentStatus::Succeeded->value)
            ->update(['status' => PaymentStatus::Refunded->value]);
    }

    /**
     * Gèle le bien : « Litige en cours », invendable au regard de quiconque le
     * consulte, et ouverture du délai contradictoire.
     */
    private function freeze(Claim $dossier): void
    {
        $bien = $dossier->asset;

        if (! $bien instanceof Asset) {
            return;
        }

        if ($bien->life_status !== LifeStatus::Disputed) {
            $this->transitions->transitionTo(
                $bien,
                LifeStatus::Disputed,
                TriggerType::Claim,
                null,
                'Réclamation #'.$dossier->id.' jugée recevable',
            );
        }

        $dossier->forceFill([
            'status' => ClaimStatus::Contradictory,
            'respondent_deadline' => now()->addDays(self::CONTRADICTORY_DAYS),
        ])->save();

        $detenteur = $bien->owner;

        if ($detenteur instanceof User) {
            // Le mis en cause n'apprend RIEN du réclamant (règle métier absolue
            // n° 4) : ni son nom, ni son numéro. Il apprend qu'une réclamation
            // existe et ce qu'il doit produire.
            $this->notifications->notify(
                $detenteur,
                NotificationType::ClaimOpened,
                'Une réclamation vise votre bien',
                'Une personne conteste votre enregistrement. Votre bien est gelé le temps de l\'instruction. '.
                'Vous avez 15 jours pour produire vos justificatifs de propriété — sans quoi le dossier sera '.
                'instruit sur les seules pièces disponibles.',
                $bien,
                ['claim_id' => $dossier->id, 'deadline_days' => self::CONTRADICTORY_DAYS],
            );
        }
    }

    /** Applique la conséquence de la décision sur le bien. */
    private function applyDecision(Claim $dossier, User $agent, ClaimDecision $decision): void
    {
        $bien = $dossier->asset;

        if (! $bien instanceof Asset) {
            return;
        }

        if ($decision === ClaimDecision::TransferToClaimant) {
            // Transfert forcé : même point de passage que la cession
            // volontaire, donc mêmes garanties (règle métier absolue n° 3).
            $this->auditChain->transaction(function () use ($bien, $dossier, $agent): array {
                $nouveau = $this->transfers->handOver(
                    $bien,
                    $dossier->claimant_user_id,
                    TriggerType::Arbitration,
                    $agent->id,
                    'Transfert prononcé en arbitrage de la réclamation #'.$dossier->id,
                );

                return [
                    'result' => $nouveau,
                    'actorType' => ActorType::Agent,
                    'actorId' => $agent->id,
                    'action' => 'claim.ownership_transferred',
                    'entityType' => 'claim',
                    'entityId' => $dossier->id,
                    'payload' => ['archived_asset_id' => $bien->id, 'created_asset_id' => $nouveau->id],
                ];
            });
        }

        if ($decision === ClaimDecision::KeepCurrent) {
            // Le gel est levé : le détenteur confirmé retrouve un bien
            // vendable.
            $this->transitions->transitionTo(
                $bien,
                LifeStatus::Active,
                TriggerType::Arbitration,
                $agent->id,
                'Réclamation #'.$dossier->id.' écartée : détenteur confirmé',
            );
        }

        if ($decision === ClaimDecision::Unresolved) {
            // Le bien RESTE gelé : ne pas trancher n'est pas innocenter, et
            // laisser repartir un bien contesté à la vente reviendrait à
            // trancher en faveur du détenteur.
            $this->transitions->transitionTo(
                $bien,
                LifeStatus::Disputed,
                TriggerType::Arbitration,
                $agent->id,
                'Litige non tranché : écart de preuves inférieur à '.self::DECISION_GAP.' points',
            );
        }

        $this->export($dossier->fresh() ?? $dossier);
        $this->announceDecision($dossier, $bien, $decision);
    }

    private function announceDecision(Claim $dossier, Asset $bien, ClaimDecision $decision): void
    {
        $message = $decision->publicMessage();

        $this->notifications->notify(
            $dossier->claimantOrFail(),
            NotificationType::ClaimDecided,
            'Décision sur votre réclamation',
            $message,
            null,
            ['claim_id' => $dossier->id, 'decision' => $decision->value],
        );

        $detenteur = $bien->owner;

        if ($detenteur instanceof User && $detenteur->id !== $dossier->claimant_user_id) {
            $this->notifications->notify(
                $detenteur,
                NotificationType::ClaimDecided,
                'Décision sur la réclamation visant votre bien',
                $message,
                null,
                ['claim_id' => $dossier->id, 'decision' => $decision->value],
            );
        }
    }

    /** Vrai si le dossier porte une pièce qui ouvre d'emblée la recevabilité. */
    private function isAutomaticallyAdmissible(Claim $dossier): bool
    {
        foreach ($dossier->evidences as $piece) {
            if ($piece->evidence_type->grantsAdmissibility()) {
                return true;
            }
        }

        return false;
    }

    private function disk(): string
    {
        $disque = config('preuve.documents.disk');

        return is_string($disque) && $disque !== '' ? $disque : 's3';
    }
}
