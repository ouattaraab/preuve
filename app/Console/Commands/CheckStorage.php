<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Surveille la place qui reste (ST-0904).
 *
 * POURQUOI `disk_free_space` NE SERT À RIEN ICI. Sur ce mutualisé, elle rend
 * 14 To libres — le volume entier de l'hébergeur, partagé entre des milliers de
 * comptes. Le quota qui nous concerne, lui, est une limite comptable appliquée
 * au compte, et aucune fonction PHP ne la lit. Une supervision fondée sur cette
 * API annoncerait « tout va bien » jusqu'au jour où plus rien ne s'écrit.
 *
 * ON MESURE DONC AUTREMENT, ET SUR DEUX PLANS :
 *
 * 1. UNE ÉCRITURE RÉELLE. C'est le seul contrôle qui éprouve le vrai mode de
 *    panne : quota atteint, disque en lecture seule, permissions changées par
 *    l'hébergeur. Il ne mesure rien, il constate — et un constat vaut mieux
 *    qu'une estimation.
 *
 * 2. LA TAILLE DE CE QUE LA PLATEFORME FAIT GROSSIR : les journaux et les
 *    pièces. C'est l'alerte AVANCÉE, celle qui laisse le temps d'agir. Le seuil
 *    est un réglage, parce que le quota dépend du contrat d'hébergement et
 *    qu'aucune valeur codée en dur ne serait juste.
 *
 * CE QUI EST EN JEU. Le quota atteint n'arrête pas seulement les envois de
 * pièces : il arrête l'écriture du REGISTRE, celle de la chaîne d'audit, et
 * celle des journaux — donc de la trace qui aurait permis de comprendre.
 */
final class CheckStorage extends Command
{
    protected $signature = 'preuve:check-storage';

    protected $description = 'Vérifie que le stockage accepte encore les écritures, et alerte avant saturation';

    public function handle(): int
    {
        $anomalie = false;

        if (! $this->ecritureFonctionne()) {
            $anomalie = true;
        }

        if (! $this->tailleSousControle()) {
            $anomalie = true;
        }

        if (! $anomalie) {
            $this->info('Stockage : écriture possible, tailles sous les seuils.');
        }

        // ZÉRO MÊME EN ANOMALIE : le planificateur traite un code non nul comme
        // un échec de tâche, ce qui déclencherait une SECONDE alerte disant
        // seulement « la commande a échoué ». L'alerte utile est déjà partie,
        // avec son motif.
        return self::SUCCESS;
    }

    /**
     * Écrit, relit, efface.
     *
     * LES TROIS, ET DANS CET ORDRE. Une écriture qui « réussit » sans que la
     * relecture rende le même contenu est le symptôme d'un disque plein sur
     * certains systèmes de fichiers : l'appel ne se plaint pas, l'octet n'est
     * jamais arrivé.
     */
    private function ecritureFonctionne(): bool
    {
        $chemin = storage_path('app/.sonde-ecriture');
        $temoin = 'preuve-'.bin2hex(random_bytes(8));

        try {
            file_put_contents($chemin, $temoin);
            $relu = @file_get_contents($chemin);
            @unlink($chemin);
        } catch (Throwable $e) {
            Log::error('Le stockage n\'accepte plus les écritures', [
                'exception' => $e::class,
                'chemin' => 'storage/app',
            ]);

            return false;
        }

        if ($relu !== $temoin) {
            Log::error('Le stockage accepte les écritures mais ne les restitue pas', [
                'chemin' => 'storage/app',
                'indice' => 'Symptôme classique d\'un quota atteint : l\'appel ne se plaint pas.',
            ]);

            return false;
        }

        return true;
    }

    private function tailleSousControle(): bool
    {
        $seuil = $this->seuilMo();

        if ($seuil === null) {
            // On ne devine pas un quota. Le dire une fois vaut mieux qu'une
            // fausse assurance : sans seuil, il n'y a pas d'alerte avancée.
            $this->warn(
                'Aucun seuil de stockage configuré (PREUVE_STORAGE_ALERT_MB) : '
                .'seule l\'écriture est surveillée, sans alerte avancée.'
            );

            return true;
        }

        $mesures = [
            'journaux' => $this->tailleMo(storage_path('logs')),
            'pièces et envois' => $this->tailleMo(storage_path('app')),
        ];

        $total = array_sum($mesures);

        foreach ($mesures as $quoi => $mo) {
            $this->line(sprintf('  %-18s %6.1f Mo', $quoi, $mo));
        }

        if ($total < $seuil) {
            return true;
        }

        Log::error('Le stockage approche du seuil configuré', [
            'total_mo' => round($total, 1),
            'seuil_mo' => $seuil,
            'detail' => array_map(fn (float $mo): float => round($mo, 1), $mesures),
            'indice' => 'Un quota atteint arrête AUSSI l\'écriture du registre et de la chaîne d\'audit.',
        ]);

        return false;
    }

    /** Taille d'un dossier, en mégaoctets. */
    private function tailleMo(string $dossier): float
    {
        if (! is_dir($dossier)) {
            return 0.0;
        }

        $octets = 0;

        // Parcours et non `du` : la commande système n'existe pas partout, et
        // une supervision qui dépend d'un binaire absent ne supervise rien.
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterateur as $fichier) {
            if ($fichier instanceof \SplFileInfo && $fichier->isFile()) {
                $octets += $fichier->getSize();
            }
        }

        return $octets / 1048576;
    }

    private function seuilMo(): ?int
    {
        $seuil = config('preuve.storage.alert_mb');

        return is_numeric($seuil) && (int) $seuil > 0 ? (int) $seuil : null;
    }
}
