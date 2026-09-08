<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\UploadStatus;
use App\Exceptions\UploadOffsetMismatch;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\UploadSession;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Envoi différé des photos, avec reprise (ST-0206, CT-05).
 *
 * L'ENREGISTREMENT N'ATTEND JAMAIS LES PHOTOS. Une session porte un bien qui
 * existe déjà : le parcours de 90 secondes se boucle sur la seule saisie, et
 * les justificatifs rattrapent ensuite, à leur rythme. Lier la création du bien
 * à la fin d'un envoi reviendrait à faire dépendre la protection d'un bien de
 * la qualité du réseau au moment où l'on en a besoin.
 *
 * L'IDENTITÉ DE LA SESSION VIENT DU CLIENT. Une application qui perd le réseau
 * au moment de la réponse ne sait pas si sa demande a abouti ; si le serveur
 * attribuait l'identifiant, chaque réessai ouvrirait un nouvel envoi et le même
 * document partirait cinq fois. Rouvrir une session existante rend simplement
 * la position déjà atteinte.
 *
 * L'EMPREINTE EST VÉRIFIÉE AVANT QUE LE JUSTIFICATIF EXISTE. Un fichier
 * recomposé morceau par morceau sur un réseau instable peut être corrompu sans
 * que rien ne le signale. Un justificatif illisible qu'un agent accepterait
 * ferait tenir un niveau de fiabilité sur un document que personne n'a pu lire.
 * Empreinte fausse : la session échoue, elle n'est jamais acceptée « au
 * bénéfice du doute ».
 *
 * LE CONTENU EST CONTRÔLÉ À L'ARRIVÉE. Un envoi en morceaux échappe
 * entièrement à la validation `mimes:` de Laravel — aucune requête ne contient
 * le fichier entier. Sans contrôle final, déclarer « photo.jpg » et pousser
 * n'importe quoi serait un dépôt libre sur le bucket de la plateforme.
 */
final class UploadSessionService
{
    /** Au-delà, un morceau perdu coûte trop cher à renvoyer en 3G. */
    private const CHUNK_MAX_BYTES = 2_097_152;

    /**
     * Contenus réellement acceptés, constatés sur le fichier assemblé — et non
     * sur ce que le client a déclaré.
     */
    private const MIME_AUTORISES = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'image/heif',
    ];

    public function __construct(private readonly DocumentReviewService $documents) {}

    /**
     * Ouvre une session, ou rend celle qui existe déjà sous cet identifiant.
     *
     * @throws DomainException
     */
    public function open(
        User $utilisateur,
        Asset $bien,
        DocumentType $type,
        string $uuid,
        string $filename,
        int $tailleOctets,
        string $empreinte,
    ): UploadSession {
        $existante = UploadSession::where('uuid', $uuid)->first();

        if ($existante instanceof UploadSession) {
            // Réessai après une réponse perdue : la session appartient-elle
            // bien à celui qui la rouvre ? Sinon, connaître un identifiant
            // suffirait à pousser des octets dans l'envoi d'un autre.
            if ($existante->user_id !== $utilisateur->id) {
                throw new DomainException('Cet envoi ne vous appartient pas.');
            }

            return $existante;
        }

        $maximum = $this->tailleMaximale();

        if ($tailleOctets < 1 || $tailleOctets > $maximum) {
            throw new DomainException(
                'Ce fichier dépasse la taille acceptée ('.(int) round($maximum / 1024).' Ko).'
            );
        }

        Storage::disk($this->stagingDisk())->makeDirectory('uploads');

        return UploadSession::create([
            'uuid' => $uuid,
            'user_id' => $utilisateur->id,
            // Figé ici : laisser le bien changer en cours d'envoi permettrait
            // de rattacher une pièce au bien d'un tiers.
            'asset_id' => $bien->id,
            'doc_type' => $type->value,
            'filename' => Str::limit($filename, 250, ''),
            'byte_size' => $tailleOctets,
            'received_bytes' => 0,
            'checksum' => mb_strtolower($empreinte),
            'status' => UploadStatus::Open,
            'expires_at' => now()->addHours($this->dureeDeVieHeures()),
        ]);
    }

    /**
     * Reçoit un morceau à la position indiquée.
     *
     * @throws UploadOffsetMismatch si la position ne suit pas ce qui est reçu
     * @throws DomainException
     */
    public function append(UploadSession $session, string $morceau, int $position): UploadSession
    {
        if ($session->status->isTerminal()) {
            // Un client qui rejoue sa file après coup ne doit pas repartir de
            // zéro sur un envoi déjà abouti.
            return $session;
        }

        if ($session->isExpired()) {
            throw new DomainException('Cet envoi a expiré. Recommencez-le.');
        }

        $taille = mb_strlen($morceau, '8bit');

        if ($taille === 0 || $taille > self::CHUNK_MAX_BYTES) {
            throw new DomainException('Morceau vide ou trop volumineux.');
        }

        $chemin = $this->stagingPath($session);

        $termine = DB::transaction(function () use ($session, $morceau, $position, $taille, $chemin): bool {
            /** @var UploadSession $verrouillee */
            $verrouillee = UploadSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->status->isTerminal()) {
                return false;
            }

            if ($position !== $verrouillee->received_bytes) {
                throw new UploadOffsetMismatch($verrouillee->received_bytes);
            }

            if ($verrouillee->received_bytes + $taille > $verrouillee->byte_size) {
                throw new DomainException('Ce morceau dépasse la taille annoncée pour ce fichier.');
            }

            file_put_contents($chemin, $morceau, FILE_APPEND | LOCK_EX);

            // La position enregistrée est celle du FICHIER, pas celle qu'on a
            // calculée : si une écriture s'est faite à moitié, la base doit
            // dire la vérité pour que le client reprenne au bon endroit.
            clearstatcache(true, $chemin);
            $reel = filesize($chemin);
            $reel = $reel === false ? $verrouillee->received_bytes : $reel;

            $verrouillee->forceFill([
                'received_bytes' => $reel,
                // Posé sous verrou : c'est ce drapeau qui empêche deux requêtes
                // simultanées de constituer deux fois le même justificatif.
                'status' => $reel >= $verrouillee->byte_size ? UploadStatus::Assembling : UploadStatus::Open,
            ])->save();

            return $reel >= $verrouillee->byte_size;
        });

        $session->refresh();

        // Hors transaction : la constitution écrit sur le stockage objet, et
        // n'a rien à faire dans un verrou de ligne.
        return $termine ? $this->finalize($session) : $session;
    }

    /**
     * Vérifie et constitue le justificatif.
     *
     * Rien n'est accepté « au bénéfice du doute » : une empreinte fausse ou un
     * contenu refusé fait échouer la session, et le fichier partiel est effacé.
     */
    private function finalize(UploadSession $session): UploadSession
    {
        $chemin = $this->stagingPath($session);

        $empreinte = is_file($chemin) ? hash_file('sha256', $chemin) : false;

        if (! is_string($empreinte) || ! hash_equals($session->checksum, $empreinte)) {
            return $this->fail(
                $session,
                'Le fichier reçu ne correspond pas à son empreinte : il a été altéré en chemin.'
            );
        }

        $mime = $this->detectMime($chemin);

        if ($mime === null || ! in_array($mime, self::MIME_AUTORISES, true)) {
            return $this->fail($session, 'Ce type de fichier n\'est pas accepté comme justificatif.');
        }

        $bien = $session->asset;

        if (! $bien instanceof Asset) {
            return $this->fail($session, 'Le bien rattaché à cet envoi n\'existe plus.');
        }

        $deposant = User::find($session->user_id);

        if (! $deposant instanceof User) {
            return $this->fail($session, 'Le compte à l\'origine de cet envoi n\'existe plus.');
        }

        $document = $this->documents->submitFromPath(
            $bien,
            $deposant,
            DocumentType::from($session->doc_type),
            $chemin,
        );

        $session->forceFill([
            'status' => UploadStatus::Completed,
            'document_id' => $document->id,
        ])->save();

        $this->discard($session);

        return $session;
    }

    private function fail(UploadSession $session, string $motif): UploadSession
    {
        $session->forceFill([
            'status' => UploadStatus::Failed,
            'failure_reason' => $motif,
            // Remis à zéro : le client recommencera sous un nouvel identifiant,
            // et ne doit pas croire qu'il lui reste des octets valides.
            'received_bytes' => 0,
        ])->save();

        $this->discard($session);

        return $session;
    }

    /** Efface le fichier de travail. Il n'a aucune raison de survivre. */
    private function discard(UploadSession $session): void
    {
        Storage::disk($this->stagingDisk())->delete('uploads/'.$session->uuid.'.part');
    }

    /**
     * Purge les envois abandonnés. Sans elle, chaque parcours interrompu
     * laisserait des mégaoctets sur le disque de travail, jusqu'à le remplir —
     * et ce disque est aussi celui des sessions et du cache.
     */
    public function purgeExpired(): int
    {
        $purgees = 0;

        UploadSession::query()
            ->where(function ($requete): void {
                $requete->where('expires_at', '<', now())
                    ->orWhere('status', UploadStatus::Completed->value)
                    ->orWhere('status', UploadStatus::Failed->value);
            })
            ->chunkById(200, function ($sessions) use (&$purgees): void {
                foreach ($sessions as $session) {
                    $this->discard($session);
                    $session->delete();
                    $purgees++;
                }
            });

        return $purgees;
    }

    /** Session d'un utilisateur, pour reprendre après un redémarrage de l'application. */
    public function find(User $utilisateur, string $uuid): ?UploadSession
    {
        $session = UploadSession::where('uuid', $uuid)->first();

        if (! $session instanceof UploadSession || $session->user_id !== $utilisateur->id) {
            return null;
        }

        return $session;
    }

    public function document(UploadSession $session): ?AssetDocument
    {
        return $session->document_id === null ? null : AssetDocument::find($session->document_id);
    }

    private function stagingPath(UploadSession $session): string
    {
        return Storage::disk($this->stagingDisk())->path('uploads/'.$session->uuid.'.part');
    }

    /**
     * Le contenu réel, jamais l'extension annoncée. Un client qui déclare
     * « photo.jpg » peut pousser tout autre chose.
     */
    private function detectMime(string $chemin): ?string
    {
        $mime = mime_content_type($chemin);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    private function stagingDisk(): string
    {
        $disque = config('preuve.uploads.staging_disk');

        return is_string($disque) && $disque !== '' ? $disque : 'local';
    }

    private function tailleMaximale(): int
    {
        $ko = config('preuve.documents.max_kb');

        return (is_numeric($ko) ? (int) $ko : 8192) * 1024;
    }

    private function dureeDeVieHeures(): int
    {
        $heures = config('preuve.uploads.session_ttl_hours');

        return max(1, is_numeric($heures) ? (int) $heures : 48);
    }
}
