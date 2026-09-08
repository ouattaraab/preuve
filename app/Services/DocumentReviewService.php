<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\DocumentReviewStatus;
use App\Enums\DocumentType;
use App\Enums\NotificationType;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\User;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Dépôt et revue des justificatifs (ST-0207, ST-0208).
 *
 * Le dépôt ne fait rien monter : une pièce arrive « en attente », et c'est la
 * revue d'un agent qui déclenche le recalcul du niveau de fiabilité. Sans ce
 * passage obligé, il suffirait de téléverser n'importe quoi pour paraître
 * documenté le temps d'une vente.
 *
 * Un rejet exige un motif écrit (ST-0208) : un refus sans raison est
 * incontestable, donc arbitraire — et le déposant ne saurait pas quoi corriger.
 */
final class DocumentReviewService
{
    public function __construct(
        private readonly TrustLevelEngine $trustLevel,
        private readonly AuditChain $auditChain,
        private readonly NotificationService $notifications,
        private readonly DocumentVault $vault,
    ) {}

    /**
     * Dépose une pièce. Le fichier va sur le bucket chiffré au repos, jamais
     * sur un disque public : une carte grise ou une facture nominative sont des
     * données personnelles.
     *
     * L'empreinte du fichier est figée au dépôt : sans elle, une pièce acceptée
     * puis remplacée dans le stockage ferait tenir un niveau de fiabilité sur
     * un document qui n'est plus celui qui a été revu.
     */
    public function submit(Asset $bien, User $deposant, DocumentType $type, UploadedFile $fichier): AssetDocument
    {
        return $this->store($bien, $deposant, $type, $fichier);
    }

    /**
     * Dépose une pièce déjà constituée sur le disque de travail, à l'issue d'un
     * envoi différé (ST-0206).
     *
     * Passe par le MÊME chemin que le dépôt direct : sans quoi une pièce
     * arrivée en morceaux pourrait échapper au bucket chiffré ou à l'empreinte
     * figée au dépôt, selon la route empruntée.
     */
    public function submitFromPath(
        Asset $bien,
        User $deposant,
        DocumentType $type,
        string $cheminLocal,
    ): AssetDocument {
        return $this->store($bien, $deposant, $type, new File($cheminLocal));
    }

    private function store(
        Asset $bien,
        User $deposant,
        DocumentType $type,
        File|UploadedFile $fichier,
    ): AssetDocument {
        $chemin = $this->vault->put('assets/'.$bien->id, $fichier);

        $reel = $fichier->getRealPath();
        $empreinte = $reel === false ? false : hash_file('sha256', $reel);

        return AssetDocument::create([
            'asset_id' => $bien->id,
            'uploaded_by' => $deposant->id,
            'doc_type' => $type,
            'file_ref' => $chemin,
            'file_sha256' => is_string($empreinte) ? $empreinte : str_repeat('0', 64),
            'review_status' => DocumentReviewStatus::Pending,
        ]);
    }

    /**
     * Tranche une revue et répercute l'effet sur le niveau de fiabilité.
     *
     * @throws InvalidArgumentException si un rejet est prononcé sans motif
     */
    public function review(
        AssetDocument $document,
        User $agent,
        DocumentReviewStatus $issue,
        ?string $motif = null,
    ): AssetDocument {
        if (! $issue->isFinal()) {
            throw new InvalidArgumentException(
                'Une revue doit trancher : « en attente » n\'est pas une décision.'
            );
        }

        if ($issue->requiresReason() && ($motif === null || trim($motif) === '')) {
            throw new InvalidArgumentException(
                'Un refus doit être motivé : sans motif, la décision est incontestable et le déposant ne sait '.
                'pas quoi corriger.'
            );
        }

        $this->auditChain->append(
            ActorType::Agent,
            $agent->id,
            'asset_document.reviewed',
            'asset_document',
            $document->id,
            [
                'asset_id' => $document->asset_id,
                'doc_type' => $document->doc_type->value,
                'review_status' => $issue->value,
                // Le motif est repris tel quel : il fait partie de la décision,
                // et c'est lui qui la rend contestable.
                'reason' => $motif,
            ],
        );

        $document->forceFill([
            'review_status' => $issue,
            'reviewed_by' => $agent->id,
            'reviewed_at' => now(),
            'review_reason' => $motif,
        ])->save();

        $bien = $document->asset;

        if ($bien instanceof Asset) {
            $ancien = $bien->trust_level;
            $nouveau = $this->trustLevel->recalculate($bien->fresh() ?? $bien, $agent->id);

            if ($nouveau !== $ancien) {
                $this->announceTrustChange($bien, $nouveau->value, $ancien->value);
            }
        }

        return $document;
    }

    /**
     * Accorde ou retire le contrôle croisé du back-office, qui conditionne le
     * niveau « Vérifié » (F3). Le moteur constate cette trace, il ne la
     * fabrique jamais.
     */
    public function setBackOfficeVerification(Asset $bien, User $agent, bool $verifie): Asset
    {
        $this->auditChain->append(
            ActorType::Agent,
            $agent->id,
            $verifie ? 'asset.backoffice_verified' : 'asset.backoffice_verification_revoked',
            'asset',
            $bien->id,
            ['rules_version' => TrustLevelEngine::RULES_VERSION],
        );

        $bien->forceFill([
            'trust_verified_at' => $verifie ? now() : null,
            'trust_verified_by' => $verifie ? $agent->id : null,
        ])->save();

        $ancien = $bien->trust_level;
        $nouveau = $this->trustLevel->recalculate($bien->fresh() ?? $bien, $agent->id);

        if ($nouveau !== $ancien) {
            $this->announceTrustChange($bien, $nouveau->value, $ancien->value);
        }

        return $bien->fresh() ?? $bien;
    }

    /**
     * Prévient le détenteur d'un changement de niveau. Une baisse le concerne
     * au premier chef : elle change ce que voient les acheteurs de son bien.
     */
    private function announceTrustChange(Asset $bien, string $nouveau, string $ancien): void
    {
        $detenteur = $bien->owner;

        if (! $detenteur instanceof User) {
            return;
        }

        $monte = $nouveau > $ancien;

        $this->notifications->notify(
            $detenteur,
            NotificationType::StatusChange,
            $monte ? 'Votre bien gagne en fiabilité' : 'Le niveau de fiabilité de votre bien a baissé',
            $monte
                ? 'Le niveau de fiabilité affiché aux acheteurs vient de passer à un cran supérieur.'
                : 'Un justificatif n\'a pas été retenu : le niveau affiché aux acheteurs a été revu à la baisse.',
            $bien,
            ['from' => $ancien, 'to' => $nouveau],
        );
    }

    /**
     * Contenu en clair d'une pièce, pour la revue par un agent.
     *
     * Il n'y a plus d'URL signée. Une pièce chiffrée au repos servie par un
     * lien direct rendrait du charabia, et un lien signé est de toute façon une
     * capacité au porteur : recopié, il ouvre la pièce à qui n'est pas agent.
     * Le téléchargement authentifié vérifie le rôle à chaque requête.
     *
     * @throws \RuntimeException si la pièce est introuvable ou indéchiffrable
     */
    public function readable(AssetDocument $document): string
    {
        return $this->vault->get($document->file_ref);
    }
}
