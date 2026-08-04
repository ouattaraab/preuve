<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

/**
 * Journal d'exploitation déposé sur le serveur.
 *
 * IL EXISTE PARCE QUE LE COURRIEL PROMETTAIT UN FICHIER QUI N'ÉTAIT JAMAIS
 * ÉCRIT. Le rapport d'exploitation tronque au-delà de quarante lignes et
 * renvoie au « détail complet dans le journal sur le serveur » ; ce chemin
 * n'était qu'une chaîne de caractères. L'exploitant qui recevait un rapport
 * d'anomalie et allait chercher le détail ne trouvait rien — pire qu'aucune
 * indication, puisqu'il cherchait.
 *
 * L'ÉCRITURE EST FAITE PAR CELUI QUI ANNONCE LE CHEMIN, jamais par l'appelant.
 * Confier le fichier à chaque commande, c'est accepter qu'une commande sur cinq
 * oublie, et que la promesse du courriel devienne fausse pour celle-là seule —
 * défaut invisible tant que tout va bien, c'est-à-dire jusqu'au jour où l'on en
 * a besoin.
 *
 * LA ROTATION EST FAITE ICI, PAS PAR LE SYSTÈME. L'hébergement est mutualisé :
 * ni `logrotate`, ni `cron` système, ni accès à la configuration. Un journal non
 * borné finirait par remplir le quota du compte, et un quota atteint arrête
 * TOUTE écriture — y compris celle du registre. Deux générations suffisent : ce
 * journal se lit dans les jours qui suivent un rapport, pas des mois après.
 *
 * IL N'ÉCHOUE JAMAIS BRUYAMMENT. Un disque plein ou un droit manquant ne doit
 * pas transformer un rapport d'anomalie en trace d'exception : le constat vaut
 * par lui-même, le journal n'en est que la conservation.
 */
final class OpsJournal
{
    /** Au-delà, le fichier est basculé. 2 Mio tiennent des mois de rapports. */
    private const TAILLE_MAX = 2 * 1024 * 1024;

    /**
     * Ajoute un rapport daté au journal, après rotation si nécessaire.
     *
     * @return bool Vrai si le rapport a bien été conservé.
     */
    public function append(string $chemin, string $titre, string $corps): bool
    {
        try {
            $dossier = dirname($chemin);

            if (! is_dir($dossier) && ! @mkdir($dossier, 0775, true) && ! is_dir($dossier)) {
                return false;
            }

            $this->rotate($chemin);

            $entree = sprintf(
                '===== %s — %s =====%s%s%s%s',
                now()->format('Y-m-d H:i:s'),
                $titre,
                PHP_EOL,
                trim($corps),
                PHP_EOL,
                PHP_EOL,
            );

            return @file_put_contents($chemin, $entree, FILE_APPEND | LOCK_EX) !== false;
        } catch (Throwable) {
            // Le constat vaut par lui-même ; sa conservation n'en est que le
            // confort.
            return false;
        }
    }

    /**
     * Bascule le journal quand il dépasse la taille retenue.
     *
     * UNE SEULE GÉNÉRATION CONSERVÉE. Empiler les générations sur un
     * hébergement dont le quota est partagé avec les pièces justificatives
     * reviendrait à faire tenir la survie du registre à la discipline d'un
     * ménage qui n'a personne pour le faire.
     */
    private function rotate(string $chemin): void
    {
        if (! is_file($chemin) || filesize($chemin) < self::TAILLE_MAX) {
            return;
        }

        @rename($chemin, $chemin.'.1');
    }
}
