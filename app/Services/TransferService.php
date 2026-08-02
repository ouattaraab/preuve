<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\LifeStatus;
use App\Enums\NotificationType;
use App\Enums\OtpPurpose;
use App\Enums\TransferStatus;
use App\Enums\TriggerType;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\AssetStatusHistory;
use App\Models\Transfer;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Transfert de propriété à double validation (ST-0601 à ST-0603).
 *
 * LE POINT CRITIQUE est la finalisation : l'ancien enregistrement est archivé
 * et le nouveau créé DANS LA MÊME TRANSACTION, sous verrou de ligne (règle
 * métier absolue n° 3). Entre les deux, il existe un instant où l'identifiant
 * n'a aucun enregistrement actif ; si cet instant était visible, une
 * consultation y répondrait « bien inconnu » sur un bien parfaitement
 * enregistré, et un enregistrement concurrent pourrait s'y glisser.
 *
 * LE NOUVEAU BIEN REPART EN « DÉCLARÉ, NON VÉRIFIÉ ». Les justificatifs
 * appuyaient la propriété du VENDEUR : une carte grise à son nom ne prouve
 * rien sur l'acheteur. Hériter du niveau de fiabilité reviendrait à vendre
 * avec le bien une confiance qui n'a pas été vérifiée pour son nouveau
 * détenteur — exactement ce qu'un fraudeur chercherait à acheter.
 *
 * LE TRANSFERT S'ADRESSE À UN NUMÉRO, pas à un compte. Exiger que l'acheteur
 * soit déjà inscrit ferait échouer la vente au marché, là où elle se conclut.
 * Le compte est rattaché au moment où l'acheteur confirme.
 *
 * Le statut antérieur du bien est mémorisé : une annulation ou une expiration
 * doit y ramener. Sans cette mémoire, un véhicule de loueur reviendrait
 * « Actif » au lieu de « En location », c'est-à-dire vendable sans
 * avertissement.
 */
final class TransferService
{
    /** Fenêtre laissée à l'acheteur pour confirmer (§4.4). */
    private const EXPIRY_DAYS = 7;

    public function __construct(
        private readonly StatusTransitionService $transitions,
        private readonly OtpService $otp,
        private readonly AuditChain $auditChain,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Ouvre un transfert et bascule le bien en « Transfert en cours ».
     *
     * @throws DomainException
     */
    public function initiate(Asset $bien, User $vendeur, string $telephoneAcheteur): Transfer
    {
        if ($bien->owner_user_id !== $vendeur->id) {
            throw new DomainException('Seul le détenteur enregistré peut céder ce bien.');
        }

        if ($bien->active_flag === null) {
            throw new DomainException('Cet enregistrement est archivé : il ne peut plus être transféré.');
        }

        // La friction est légitime ici (CT-06) : céder un bien engage la
        // propriété, et un transfert opéré depuis un compte non vérifié serait
        // le moyen le plus simple de blanchir un bien volé.
        if (! $vendeur->hasVerifiedIdentity()) {
            throw new DomainException(
                'Vérifiez votre identité avant de céder un bien : le transfert engage la propriété.'
            );
        }

        $destinataire = $this->otp->normalizeDestination($telephoneAcheteur);

        if ($destinataire === $vendeur->phone) {
            throw new DomainException('Le numéro de l\'acheteur ne peut pas être le vôtre.');
        }

        if ($this->hasOpenTransfer($bien)) {
            throw new DomainException('Un transfert est déjà en cours sur ce bien.');
        }

        $statutAnterieur = $bien->life_status;

        $transfert = $this->auditChain->transaction(
            function () use ($bien, $vendeur, $destinataire, $statutAnterieur): array {
                $transfert = Transfer::create([
                    'asset_id' => $bien->id,
                    'from_user_id' => $vendeur->id,
                    'to_phone' => $destinataire,
                    'status' => TransferStatus::Initiated,
                    'previous_life_status' => $statutAnterieur,
                    'expires_at' => now()->addDays(self::EXPIRY_DAYS),
                ]);

                return [
                    'result' => $transfert,
                    'actorType' => ActorType::User,
                    'actorId' => $vendeur->id,
                    'action' => 'transfer.initiated',
                    'entityType' => 'transfer',
                    'entityId' => $transfert->id,
                    // Jamais le numéro de l'acheteur : audit_log est
                    // inaltérable et désigne ici quelqu'un qui n'a peut-être
                    // même pas de compte.
                    'payload' => ['asset_id' => $bien->id, 'from_status' => $statutAnterieur->value],
                ];
            }
        );

        // Hors de la transaction d'audit : la bascule de statut écrit sa propre
        // entrée, et AuditChain refuse d'être imbriquée.
        $this->transitions->transitionTo(
            $bien,
            LifeStatus::Transferring,
            TriggerType::Transfer,
            $vendeur->id,
            'Transfert initié vers un acheteur',
        );

        // L'acheteur reçoit son code : c'est l'invitation elle-même. Un SMS
        // séparé « quelqu'un veut vous céder un bien » n'apporterait rien de
        // plus et doublerait le coût.
        $this->otp->request($destinataire, OtpPurpose::Transfer);

        return $transfert;
    }

    /** @throws DomainException */
    public function confirmBySeller(Transfer $transfert, User $vendeur, string $code): Transfer
    {
        $this->assertOpen($transfert);

        if ($transfert->from_user_id !== $vendeur->id) {
            throw new DomainException('Seul le vendeur peut confirmer de son côté.');
        }

        $this->otp->verify($vendeur->phone, $code, OtpPurpose::Transfer);

        $transfert->forceFill(['seller_otp_at' => now()])->save();

        return $this->completeIfReady($transfert);
    }

    /**
     * Confirmation de l'acheteur. C'est ici que son compte est rattaché : il
     * peut n'avoir été qu'un numéro jusque-là.
     *
     * @throws DomainException
     */
    public function confirmByBuyer(Transfer $transfert, User $acheteur, string $code): Transfer
    {
        $this->assertOpen($transfert);

        if ($acheteur->phone !== $transfert->to_phone) {
            throw new DomainException('Ce transfert ne vous est pas destiné.');
        }

        $this->otp->verify($acheteur->phone, $code, OtpPurpose::Transfer);

        $transfert->forceFill([
            'to_user_id' => $acheteur->id,
            'buyer_otp_at' => now(),
            'status' => TransferStatus::BuyerConfirmed,
        ])->save();

        return $this->completeIfReady($transfert);
    }

    /**
     * Annulation par le vendeur, tant que le transfert n'est pas finalisé.
     * L'acheteur, lui, n'annule pas : ne pas confirmer suffit, et le transfert
     * expirera de lui-même.
     *
     * @throws DomainException
     */
    public function cancel(Transfer $transfert, User $vendeur): Transfer
    {
        $this->assertOpen($transfert);

        if ($transfert->from_user_id !== $vendeur->id) {
            throw new DomainException('Seul le vendeur peut annuler ce transfert.');
        }

        $this->close($transfert, TransferStatus::Cancelled, $vendeur->id, 'Transfert annulé par le vendeur');

        return $transfert->fresh() ?? $transfert;
    }

    /** Expiration à J+7 : le bien revient à son statut antérieur. */
    public function expire(Transfer $transfert): Transfer
    {
        $this->close($transfert, TransferStatus::Expired, null, 'Transfert expiré sans confirmation');

        return $transfert->fresh() ?? $transfert;
    }

    /**
     * Chaîne des détenteurs (ST-0603) : la suite des enregistrements successifs
     * pour un même identifiant, du plus ancien au plus récent.
     *
     * Rendue en interne dans son intégralité ; l'exposition publique, elle, se
     * limite au NOMBRE de détenteurs et aux dates (règle métier absolue n° 4 —
     * l'acheteur d'un rapport n'apprend jamais qui a possédé le bien).
     *
     * @return list<Asset>
     */
    public function holderChain(Asset $bien): array
    {
        $chaine = Asset::query()
            ->where('identifier_normalized', $bien->identifier_normalized)
            ->orderBy('registered_at')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values($chaine);
    }

    private function completeIfReady(Transfer $transfert): Transfer
    {
        if (! $transfert->bothConfirmed()) {
            return $transfert;
        }

        return $this->complete($transfert);
    }

    /**
     * Finalisation : archivage de l'ancien et création du nouveau dans la MÊME
     * transaction, sous verrou de ligne (règle métier absolue n° 3).
     */
    private function complete(Transfer $transfert): Transfer
    {
        $acheteurId = $transfert->to_user_id;

        if ($acheteurId === null) {
            throw new DomainException('Aucun compte acheteur rattaché à ce transfert.');
        }

        return $this->auditChain->transaction(function () use ($transfert, $acheteurId): array {
            // Verrou de ligne sur l'enregistrement cédé : deux finalisations
            // concurrentes ne doivent pas produire deux actifs pour un même
            // identifiant.
            $ancien = Asset::whereKey($transfert->asset_id)->lockForUpdate()->firstOrFail();

            if ($ancien->active_flag === null) {
                throw new DomainException('Cet enregistrement a déjà été archivé.');
            }

            $maintenant = now();

            // Archivage d'abord : l'index unique (identifier_normalized,
            // active_flag) interdirait sinon l'insertion du nouvel actif.
            $ancien->forceFill(['active_flag' => null])->save();

            $nouveau = Asset::create([
                'public_ref' => $this->generatePublicRef(),
                'owner_user_id' => $acheteurId,
                'company_id' => null,
                'previous_asset_id' => $ancien->id,
                'asset_category_key' => $ancien->asset_category_key,
                'identifier_type' => $ancien->identifier_type,
                'identifier_raw' => $ancien->identifier_raw,
                'identifier_normalized' => $ancien->identifier_normalized,
                'active_flag' => 1,
                'attributes' => $ancien->getAttribute('attributes'),
                // Repart en « Déclaré, non vérifié » : les justificatifs
                // appuyaient la propriété du vendeur, pas celle de l'acheteur.
                'trust_level' => TrustLevel::Declared,
                // Pas de fenêtre de contestation : le transfert est lui-même
                // la preuve du changement de main, et il a été doublement
                // confirmé.
                'life_status' => LifeStatus::Active,
                'provisional_until' => null,
                'registered_at' => $maintenant,
            ]);

            AssetStatusHistory::create([
                'asset_id' => $nouveau->id,
                'from_status' => null,
                'to_status' => LifeStatus::Active,
                'to_trust' => TrustLevel::Declared,
                'trigger_type' => TriggerType::Transfer,
                'actor_user_id' => $acheteurId,
                'reason' => 'Enregistrement né du transfert #'.$transfert->id,
                'created_at' => $maintenant->format('Y-m-d H:i:s'),
            ]);

            $transfert->forceFill([
                'status' => TransferStatus::Completed,
                'completed_at' => $maintenant,
                'created_asset_id' => $nouveau->id,
            ])->save();

            return [
                'result' => $transfert,
                'actorType' => ActorType::User,
                'actorId' => $acheteurId,
                'action' => 'transfer.completed',
                'entityType' => 'transfer',
                'entityId' => $transfert->id,
                'payload' => [
                    'archived_asset_id' => $ancien->id,
                    'created_asset_id' => $nouveau->id,
                ],
            ];
        });
    }

    private function close(Transfer $transfert, TransferStatus $issue, ?int $acteurId, string $motif): void
    {
        $bien = $transfert->asset;

        $transfert->forceFill(['status' => $issue])->save();

        if (! $bien instanceof Asset || $bien->life_status !== LifeStatus::Transferring) {
            // Le bien a pu changer de statut entre-temps — une déclaration de
            // vol pendant le transfert, par exemple. On ne le ramène pas de
            // force à un statut périmé.
            return;
        }

        $this->transitions->transitionTo(
            $bien,
            $transfert->previous_life_status,
            $acteurId === null ? TriggerType::System : TriggerType::Owner,
            $acteurId,
            $motif,
        );

        $detenteur = $bien->owner;

        if ($detenteur instanceof User) {
            $this->notifications->notify(
                $detenteur,
                NotificationType::StatusChange,
                $issue === TransferStatus::Expired ? 'Transfert expiré' : 'Transfert annulé',
                $issue === TransferStatus::Expired
                    ? "L'acheteur n'a pas confirmé dans les 7 jours : votre bien retrouve son statut précédent."
                    : 'Le transfert a été annulé : votre bien retrouve son statut précédent.',
                $bien,
            );
        }
    }

    private function assertOpen(Transfer $transfert): void
    {
        if (! $transfert->status->isOpen()) {
            throw new DomainException('Ce transfert n\'est plus en cours.');
        }

        if ($transfert->expires_at->isPast()) {
            throw new DomainException('Ce transfert a expiré : le vendeur doit en initier un nouveau.');
        }
    }

    private function hasOpenTransfer(Asset $bien): bool
    {
        return Transfer::query()
            ->where('asset_id', $bien->id)
            ->whereIn('status', [TransferStatus::Initiated->value, TransferStatus::BuyerConfirmed->value])
            ->where('expires_at', '>', now())
            ->exists();
    }

    /** Même alphabet que l'enregistrement : lisible au téléphone, sans caractère confondable. */
    private function generatePublicRef(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

        do {
            $reference = 'PRV-';

            for ($i = 0; $i < 8; $i++) {
                $reference .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (DB::table('assets')->where('public_ref', $reference)->exists());

        return $reference;
    }
}
