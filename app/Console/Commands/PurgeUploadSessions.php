<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\UploadSessionService;
use Illuminate\Console\Command;

/**
 * Purge des envois différés abandonnés ou aboutis (ST-0206).
 *
 * Ce n'est pas de l'entretien de confort. Chaque parcours interrompu en 3G
 * laisse un fichier partiel sur le disque de travail — le même disque que les
 * sessions et le cache. Sans purge, un mutualisé se remplit tout seul, et ce
 * qui tombe alors n'est pas l'envoi de photos mais la plateforme entière.
 */
final class PurgeUploadSessions extends Command
{
    protected $signature = 'preuve:purge-uploads';

    protected $description = 'Efface les envois différés expirés et les fichiers de travail restants';

    public function handle(UploadSessionService $uploads): int
    {
        $purgees = $uploads->purgeExpired();

        $this->components->info("{$purgees} session(s) d'envoi purgée(s).");

        return self::SUCCESS;
    }
}
