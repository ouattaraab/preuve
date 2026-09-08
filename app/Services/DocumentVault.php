<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Dépôt et lecture des pièces, chiffrées au repos (Loi 2013-450).
 *
 * POINT DE PASSAGE UNIQUE. Justificatifs de biens, pièces de réclamation et
 * documents d'identité entrent et sortent par ici. Trois services écrivaient
 * auparavant chacun leur fichier : la moindre divergence — un chemin non
 * chiffré, une empreinte calculée sur autre chose — n'aurait été découverte
 * qu'à la lecture, c'est-à-dire le jour où un agent doit trancher.
 *
 * LE CHIFFREMENT EST INSCRIT DANS LE NOM DU FICHIER, par un suffixe `.enc`, et
 * non dans un réglage. Un drapeau de configuration rendrait illisibles toutes
 * les pièces déjà déposées le jour où quelqu'un le bascule ; le suffixe, lui,
 * voyage avec le fichier et survit à un changement de disque comme à une
 * restauration. Chaque pièce sait ce qu'elle est.
 *
 * L'EMPREINTE PORTE SUR LE CLAIR, TOUJOURS. `file_sha256` atteste du document
 * qui a été déposé et revu : la sauvegarde, le remontage et la réconciliation
 * s'y réfèrent tous. La calculer sur le chiffré la rendrait dépendante d'un
 * détail de stockage — et deux dépôts du même document donneraient des
 * empreintes différentes, le chiffrement étant salé.
 *
 * CE QUE LE CHIFFREMENT VAUT, ET CE QU'IL NE VAUT PAS. Il ne remplace pas un
 * bucket privé : il limite les dégâts quand le disque est partagé. La cible est
 * un mutualisé où neuf autres sites cohabitent ; une lecture de fichier depuis
 * chez un voisin rend alors du chiffré, et non des cartes grises. La clé vit
 * dans `.env`, hors du document root — la même lecture ne la donne pas.
 * Perdre APP_KEY, en revanche, rend les pièces définitivement illisibles.
 */
final class DocumentVault
{
    private const SUFFIXE_CHIFFRE = '.enc';

    /**
     * Dépose une pièce et rend la référence à stocker en base.
     *
     * @param  string  $prefixe  dossier logique, ex. « assets/12 »
     */
    public function put(string $prefixe, File|UploadedFile $fichier): string
    {
        $reel = $fichier->getRealPath();

        if ($reel === false) {
            throw new RuntimeException('Fichier illisible : le dépôt est annulé plutôt qu\'enregistré à vide.');
        }

        if (! $this->chiffreAuRepos()) {
            $chemin = Storage::disk($this->disk())->putFile($prefixe, $fichier);

            if (! is_string($chemin) || $chemin === '') {
                throw new RuntimeException(
                    'La pièce n\'a pas pu être stockée : le dépôt est annulé plutôt qu\'enregistré à vide.'
                );
            }

            return $chemin;
        }

        $extension = $fichier->guessExtension() ?? 'bin';
        $chemin = $prefixe.'/'.Str::random(40).'.'.$extension.self::SUFFIXE_CHIFFRE;

        $ecrit = Storage::disk($this->disk())->put(
            $chemin,
            Crypt::encryptString((string) file_get_contents($reel)),
        );

        if ($ecrit === false) {
            throw new RuntimeException(
                'La pièce n\'a pas pu être stockée : le dépôt est annulé plutôt qu\'enregistré à vide.'
            );
        }

        return $chemin;
    }

    /**
     * Contenu EN CLAIR d'une pièce, quelle que soit la façon dont elle a été
     * déposée.
     *
     * @throws RuntimeException si la pièce est introuvable ou indéchiffrable
     */
    public function get(string $reference): string
    {
        if (! Storage::disk($this->disk())->exists($reference)) {
            throw new RuntimeException("Pièce introuvable : {$reference}");
        }

        $contenu = (string) Storage::disk($this->disk())->get($reference);

        if (! $this->estChiffree($reference)) {
            return $contenu;
        }

        try {
            return Crypt::decryptString($contenu);
        } catch (Throwable $e) {
            // Le cas le plus probable est une rotation d'APP_KEY sans reprise
            // des pièces. Le dire vaut mieux que rendre un contenu illisible
            // qu'un agent prendrait pour un document corrompu.
            throw new RuntimeException(
                'Pièce indéchiffrable ('.$e::class.') : APP_KEY a-t-elle changé depuis son dépôt ?'
            );
        }
    }

    /**
     * Écrit un contenu déjà connu en clair, sous une référence donnée. Sert au
     * remontage après sinistre : la référence attendue par la base doit être
     * reproduite à l'identique, chiffrement compris.
     */
    public function putRaw(string $reference, string $clair): void
    {
        Storage::disk($this->disk())->put(
            $reference,
            $this->estChiffree($reference) ? Crypt::encryptString($clair) : $clair,
        );
    }

    public function exists(string $reference): bool
    {
        return Storage::disk($this->disk())->exists($reference);
    }

    public function estChiffree(string $reference): bool
    {
        return str_ends_with($reference, self::SUFFIXE_CHIFFRE);
    }

    /** Type de contenu, deviné sur le CLAIR : le chiffré n'en a aucun. */
    public function mimeOf(string $clair): string
    {
        $detecte = (new \finfo(FILEINFO_MIME_TYPE))->buffer($clair);

        return is_string($detecte) && $detecte !== '' ? $detecte : 'application/octet-stream';
    }

    public function disk(): string
    {
        $disque = config('preuve.documents.disk');

        return is_string($disque) && $disque !== '' ? $disque : 's3';
    }

    /**
     * Le chiffrement est le défaut. Il ne se désactive que pour un stockage
     * objet privé qui chiffre déjà au repos ET dont on veut garder les URL
     * signées — une URL signée vers un fichier chiffré rendrait du charabia.
     */
    private function chiffreAuRepos(): bool
    {
        $reglage = config('preuve.documents.encrypt_at_rest');

        return ! is_bool($reglage) || $reglage;
    }
}
