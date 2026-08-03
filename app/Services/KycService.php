<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\KycStatus;
use App\Enums\NotificationType;
use App\Models\Asset;
use App\Models\KycSubmission;
use App\Models\User;
use App\Services\Kyc\IdentityReader;
use DomainException;
use Illuminate\Http\UploadedFile;

/**
 * Vérification d'identité (ST-0103).
 *
 * C'est le point où la friction est légitimement forte (CT-06) : le KYC ouvre
 * le niveau « Documenté », le dépôt d'une réclamation et le transfert de
 * propriété. Il n'est jamais exigé pour enregistrer un bien ni pour consulter
 * un statut.
 *
 * LE NUMÉRO DE PIÈCE N'EST JAMAIS STOCKÉ EN CLAIR (règle métier absolue n° 8).
 * Il est haché dès l'extraction, par HMAC porté par APP_KEY — un SHA-256 nu se
 * renverserait par balayage : un numéro de CNI ivoirienne a une forme connue et
 * un espace de valeurs réduit. L'empreinte ne sert qu'à une chose, refuser que
 * deux comptes s'appuient sur la même pièce.
 *
 * L'EXTRACTION NE DÉCIDE PAS. Mindee propose des champs, un agent tranche. Une
 * identité validée automatiquement sur la foi d'un OCR ouvrirait la porte à des
 * pièces fabriquées que personne n'aurait regardées — et le KYC est justement
 * ce qui garantit qu'une facture nominative se rapporte bien au titulaire du
 * compte.
 */
final class KycService
{
    public function __construct(
        private readonly IdentityReader $reader,
        private readonly AuditChain $auditChain,
        private readonly NotificationService $notifications,
        private readonly TrustLevelEngine $trustLevel,
        private readonly DocumentVault $vault,
    ) {}

    /**
     * Dépose un dossier. Le compte passe « en cours de vérification » : rien
     * n'est acquis avant la revue.
     *
     * @throws DomainException si un dossier est déjà en cours, ou si la pièce
     *                         est déjà rattachée à un autre compte
     */
    public function submit(
        User $utilisateur,
        UploadedFile $recto,
        UploadedFile $verso,
        UploadedFile $selfie,
    ): KycSubmission {
        if (! $utilisateur->kycStatus()->allowsNewSubmission()) {
            throw new DomainException(
                'Une vérification est déjà en cours pour ce compte. Attendez son issue avant d\'en déposer '.
                'une nouvelle.'
            );
        }

        $extraction = $this->reader->read($recto, $verso);
        $empreinte = $extraction->documentNumber === null
            ? null
            : $this->hashDocumentNumber($extraction->documentNumber);

        // Une même pièce ne peut pas fonder deux identités vérifiées : c'est
        // la protection la plus directe contre l'usurpation, et elle doit
        // s'appliquer AVANT que le dossier n'entre en file.
        if ($empreinte !== null && $this->belongsToAnotherAccount($empreinte, $utilisateur)) {
            throw new DomainException(
                'Cette pièce d\'identité est déjà rattachée à un autre compte. Contactez le support.'
            );
        }

        $disque = $this->disk();

        $dossier = KycSubmission::create([
            'user_id' => $utilisateur->id,
            'status' => 'pending',
            // Les pièces d'identité sont les plus sensibles du bucket : elles
            // passent par le coffre, qui les chiffre au repos.
            'id_front_ref' => $this->vault->put('kyc/'.$utilisateur->id, $recto),
            'id_back_ref' => $this->vault->put('kyc/'.$utilisateur->id, $verso),
            'selfie_ref' => $this->vault->put('kyc/'.$utilisateur->id, $selfie),
            'id_front_sha256' => $this->fileHash($recto),
            'id_back_sha256' => $this->fileHash($verso),
            'selfie_sha256' => $this->fileHash($selfie),
            // Charge minimisée : le numéro de pièce en est absent par
            // construction (voir IdentityExtraction::toMinimizedPayload).
            'ocr_payload' => $extraction->toMinimizedPayload(),
        ]);

        $utilisateur->forceFill([
            'kyc_status' => KycStatus::Pending->value,
            'kyc_id_number_hash' => $empreinte ?? $utilisateur->kyc_id_number_hash,
        ])->save();

        $this->auditChain->append(
            ActorType::User,
            $utilisateur->id,
            'kyc.submitted',
            'kyc_submission',
            $dossier->id,
            [
                // Ni le numéro, ni le nom, ni la date de naissance : audit_log
                // est inaltérable et survivrait au droit à l'effacement.
                'extraction_failed' => $extraction->failed,
                'has_document_number' => $empreinte !== null,
            ],
        );

        return $dossier;
    }

    /**
     * Tranche un dossier. C'est cette décision — et elle seule — qui fait
     * passer un compte en « identité vérifiée ».
     *
     * @throws DomainException si un refus est prononcé sans motif
     */
    public function review(
        KycSubmission $dossier,
        User $agent,
        bool $accepte,
        ?string $motif = null,
    ): KycSubmission {
        if ($dossier->status !== 'pending') {
            throw new DomainException('Ce dossier a déjà été tranché.');
        }

        if (! $accepte && ($motif === null || trim($motif) === '')) {
            throw new DomainException(
                'Un refus de vérification doit être motivé : sans raison, l\'utilisateur est bloqué sans savoir '.
                'quoi corriger, et sans recours.'
            );
        }

        $utilisateur = $dossier->user;

        if (! $utilisateur instanceof User) {
            throw new DomainException('Le compte rattaché à ce dossier est introuvable.');
        }

        $statut = $accepte ? KycStatus::Verified : KycStatus::Rejected;

        $this->auditChain->append(
            ActorType::Agent,
            $agent->id,
            $accepte ? 'kyc.verified' : 'kyc.rejected',
            'kyc_submission',
            $dossier->id,
            ['user_id' => $utilisateur->id, 'reason' => $motif],
        );

        $dossier->forceFill([
            'status' => $accepte ? 'verified' : 'rejected',
            'reviewed_by' => $agent->id,
            'reviewed_at' => now(),
            'review_reason' => $motif,
        ])->save();

        $utilisateur->forceFill([
            'kyc_status' => $statut->value,
            'kyc_verified_at' => $accepte ? now() : null,
            // Un refus détache l'empreinte : la garder empêcherait la personne
            // légitime de resoumettre la même pièce corrigée depuis son compte,
            // et bloquerait tout autre compte s'appuyant sur elle.
            'kyc_id_number_hash' => $accepte ? $utilisateur->kyc_id_number_hash : null,
        ])->save();

        $this->notifications->notify(
            $utilisateur,
            NotificationType::KycResult,
            $accepte ? 'Votre identité est vérifiée' : 'Vérification d\'identité refusée',
            $accepte
                ? 'Vous pouvez désormais documenter vos biens, ouvrir une réclamation et transférer la propriété.'
                : 'Votre dossier n\'a pas été retenu : '.((string) $motif),
        );

        if ($accepte) {
            $this->recalculateOwnedAssets($utilisateur, $agent);
        }

        return $dossier;
    }

    /**
     * Répercute une identité vérifiée sur les biens du compte : le niveau
     * « Documenté » dépend du KYC autant que du justificatif, et un bien déjà
     * appuyé par une pièce acceptée doit monter sans nouvelle démarche.
     *
     * Procède par petits lots : chaque recalcul qui change quelque chose prend
     * le verrou nommé de la chaîne d'audit, dont le plafond mesuré est d'une
     * quinzaine d'actions simultanées. Un loueur au parc important ferait
     * sinon échouer les actions des autres utilisateurs pendant tout le
     * traitement.
     */
    private function recalculateOwnedAssets(User $utilisateur, User $agent): void
    {
        Asset::query()
            ->where('owner_user_id', $utilisateur->id)
            ->whereNotNull('active_flag')
            ->orderBy('id')
            ->chunkById(50, function ($biens) use ($agent): void {
                foreach ($biens as $bien) {
                    $this->trustLevel->recalculate($bien, $agent->id);
                }
            });
    }

    /**
     * Empreinte du numéro de pièce. HMAC porté par APP_KEY, hors base : un dump
     * de la seule base ne permet donc pas de retrouver les numéros par
     * balayage, alors qu'un SHA-256 nu s'y prêterait — un numéro de CNI a une
     * forme connue et un espace de valeurs réduit.
     */
    public function hashDocumentNumber(string $numero): string
    {
        $cle = config('app.key');
        $normalise = mb_strtoupper(preg_replace('/[^A-Z0-9]/i', '', $numero) ?? '');

        return hash_hmac('sha256', $normalise, is_string($cle) ? $cle : '');
    }

    private function belongsToAnotherAccount(string $empreinte, User $utilisateur): bool
    {
        return User::query()
            ->where('kyc_id_number_hash', $empreinte)
            ->whereKeyNot($utilisateur->id)
            ->exists();
    }

    private function fileHash(UploadedFile $fichier): string
    {
        $empreinte = hash_file('sha256', $fichier->getRealPath());

        return is_string($empreinte) ? $empreinte : str_repeat('0', 64);
    }

    private function disk(): string
    {
        $disque = config('preuve.documents.disk');

        return is_string($disque) && $disque !== '' ? $disque : 's3';
    }
}
