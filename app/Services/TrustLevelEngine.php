<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\DocumentReviewStatus;
use App\Enums\DocumentType;
use App\Enums\TriggerType;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetStatusHistory;
use App\Models\User;

/**
 * Moteur du niveau de fiabilité F1/F2/F3 (ST-0401).
 *
 * Le niveau affiché est ce sur quoi un acheteur fonde sa décision : il ne doit
 * jamais pouvoir monter d'un cran sans que quelqu'un ait réellement vérifié
 * quelque chose. Trois principes le tiennent.
 *
 * RIEN NE MONTE SUR UNE PIÈCE NON REVUE. Un justificatif en attente ne compte
 * pas : sinon il suffirait de téléverser n'importe quoi pour paraître documenté
 * le temps d'une vente.
 *
 * LE NIVEAU PEUT REDESCENDRE. Une falsification découverte après coup retire le
 * niveau qu'elle avait fait gagner. Un moteur qui ne saurait que monter
 * laisserait l'acheteur suivant se fier à une preuve qui n'existe plus — c'est
 * le défaut le plus coûteux qu'un système de réputation puisse avoir.
 *
 * F3 N'EST JAMAIS CALCULABLE. Il suppose qu'un agent a rapproché la pièce d'une
 * source extérieure ; le moteur ne fait que constater cette trace, il ne la
 * fabrique pas. Réciproquement, un agent ne peut pas suppléer les conditions de
 * F2 : cocher « vérifié » sur un bien sans justificatif ne crée aucune preuve.
 *
 * Les règles sont VERSIONNÉES (ST-0401) : la version qui a servi est journalisée
 * avec chaque changement. Sans cela, un niveau attribué sous des règles
 * anciennes serait indistinguable d'un niveau attribué sous les règles
 * actuelles, et donc incontestable.
 */
final class TrustLevelEngine
{
    /**
     * À incrémenter à toute modification des conditions ci-dessous, et à
     * documenter dans systemPatterns.md. La valeur est journalisée avec chaque
     * changement de niveau.
     */
    public const RULES_VERSION = '2026.08.1';

    public function __construct(private readonly AuditChain $auditChain) {}

    /**
     * Niveau mérité par l'état actuel du bien. Sans effet de bord : sert aussi
     * à la jauge de progression, qui doit pouvoir simuler sans rien écrire.
     */
    public function evaluate(Asset $bien): TrustLevel
    {
        if (! $this->meetsDocumented($bien)) {
            return TrustLevel::Declared;
        }

        return $this->hasBackOfficeVerification($bien)
            ? TrustLevel::Verified
            : TrustLevel::Documented;
    }

    /**
     * Applique le niveau mérité. N'écrit RIEN si le niveau ne change pas : un
     * recalcul est déclenché à chaque événement, et historiser à vide noierait
     * la chronologie du bien.
     */
    public function recalculate(Asset $bien, ?int $actorUserId = null): TrustLevel
    {
        $ancien = $bien->trust_level;
        $nouveau = $this->evaluate($bien);

        if ($nouveau === $ancien) {
            return $ancien;
        }

        return $this->auditChain->transaction(
            function () use ($bien, $ancien, $nouveau, $actorUserId): array {
                $bien->forceFill(['trust_level' => $nouveau])->save();

                // Le statut de vie est reporté inchangé : les deux dimensions
                // sont indépendantes, et la colonne to_status ne peut pas être
                // nulle.
                AssetStatusHistory::create([
                    'asset_id' => $bien->id,
                    'from_status' => $bien->life_status,
                    'to_status' => $bien->life_status,
                    'from_trust' => $ancien,
                    'to_trust' => $nouveau,
                    'trigger_type' => $actorUserId === null ? TriggerType::System : TriggerType::Backoffice,
                    'actor_user_id' => $actorUserId,
                    'reason' => 'Recalcul du niveau de fiabilité (règles '.self::RULES_VERSION.')',
                    'created_at' => now()->format('Y-m-d H:i:s'),
                ]);

                return [
                    'result' => $nouveau,
                    'actorType' => $actorUserId === null ? ActorType::System : ActorType::Agent,
                    'actorId' => $actorUserId,
                    'action' => 'asset.trust_level_changed',
                    'entityType' => 'asset',
                    'entityId' => $bien->id,
                    'payload' => [
                        'from' => $ancien->value,
                        'to' => $nouveau->value,
                        'rules_version' => self::RULES_VERSION,
                    ],
                ];
            }
        );
    }

    /**
     * Jauge de progression (ST-0207) : ce qui est acquis, ce qui manque, et ce
     * que le niveau suivant apporte.
     *
     * Nomme les conditions manquantes plutôt qu'un pourcentage : « ajoutez
     * votre carte grise » indique quoi faire, « 60 % » n'indique rien.
     *
     * @return array{current: string, current_label: string, next: string|null, next_label: string|null, benefit: string|null, missing: list<string>, rules_version: string}
     */
    public function progress(Asset $bien): array
    {
        $actuel = $this->evaluate($bien);
        $suivant = match ($actuel) {
            TrustLevel::Declared => TrustLevel::Documented,
            TrustLevel::Documented => TrustLevel::Verified,
            TrustLevel::Verified => null,
        };

        return [
            'current' => $actuel->value,
            'current_label' => $actuel->label(),
            'next' => $suivant?->value,
            'next_label' => $suivant?->label(),
            'benefit' => match ($suivant) {
                TrustLevel::Documented => 'Un bien documenté rassure l\'acheteur et vous ouvre le transfert '.
                    'de propriété et la réclamation.',
                TrustLevel::Verified => 'Un bien vérifié porte le niveau de confiance le plus élevé affiché '.
                    'aux acheteurs.',
                default => null,
            },
            'missing' => $this->missingFor($bien, $suivant),
            'rules_version' => self::RULES_VERSION,
        ];
    }

    /**
     * Conditions non satisfaites pour atteindre le niveau visé.
     *
     * @return list<string>
     */
    private function missingFor(Asset $bien, ?TrustLevel $vise): array
    {
        if ($vise === null) {
            return [];
        }

        $manquants = [];

        if (! $this->hasAcceptedOwnershipProof($bien)) {
            $manquants[] = 'proof_of_ownership';
        }

        if (! $this->hasVerifiedKyc($bien)) {
            $manquants[] = 'kyc_verified';
        }

        if ($vise === TrustLevel::Verified && ! $this->hasBackOfficeVerification($bien)) {
            $manquants[] = 'backoffice_review';
        }

        return $manquants;
    }

    private function meetsDocumented(Asset $bien): bool
    {
        return $this->hasAcceptedOwnershipProof($bien) && $this->hasVerifiedKyc($bien);
    }

    /**
     * Une pièce ACCEPTÉE et qui atteste réellement la propriété. Une photo
     * montre le bien, un récépissé de plainte atteste un incident : ni l'une ni
     * l'autre ne dit à qui il appartient.
     */
    private function hasAcceptedOwnershipProof(Asset $bien): bool
    {
        $types = [];

        foreach (DocumentType::cases() as $type) {
            if ($type->provesOwnership()) {
                $types[] = $type->value;
            }
        }

        return AssetDocument::query()
            ->where('asset_id', $bien->id)
            ->whereIn('doc_type', $types)
            ->where('review_status', DocumentReviewStatus::Accepted->value)
            ->exists();
    }

    /**
     * KYC complet du détenteur. Une facture acceptée sans savoir à qui
     * appartient le compte ne prouve rien : c'est le rapprochement des deux qui
     * fait la preuve.
     */
    private function hasVerifiedKyc(Asset $bien): bool
    {
        $detenteur = $bien->owner;

        return $detenteur instanceof User && $detenteur->hasVerifiedIdentity();
    }

    private function hasBackOfficeVerification(Asset $bien): bool
    {
        return $bien->trust_verified_at !== null;
    }
}
