<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SchedulerHeartbeatStore;
use Illuminate\Console\Command;

/**
 * Marque que le planificateur tourne (ST-0904).
 *
 * ELLE EXISTE PARCE QU'UN PLANIFICATEUR ARRÊTÉ NE SE VOIT PAS. Le 03/08/2026,
 * les tâches cron de l'hébergement ne s'exécutaient pas : aucune erreur, aucun
 * journal, aucun symptôme — l'interface affichait une tâche bien créée. Il a
 * fallu écrire un fichier témoin et l'observer pour s'en apercevoir. Sans
 * témoin, le défaut aurait tenu jusqu'au jour où l'on aurait constaté que des
 * biens provisoires ne devenaient jamais actifs, que les transferts n'expiraient
 * plus, et que la chaîne d'audit n'avait plus été ancrée depuis des semaines.
 *
 * C'est le défaut le plus coûteux d'une plateforme de ce genre : tout paraît
 * fonctionner, et ce qui devait se produire dans le temps ne se produit pas.
 *
 * Elle ne fait qu'écrire un horodatage — assez peu pour tourner souvent, assez
 * pour que la sonde de santé distingue « le planificateur tourne » de « il ne
 * tourne plus ». Elle ne prend aucun verrou d'audit : ce n'est pas une action
 * métier, et elle ne doit rien disputer aux vraies.
 */
final class SchedulerHeartbeat extends Command
{
    protected $signature = 'preuve:heartbeat';

    protected $description = 'Marque le passage du planificateur, pour que son arrêt devienne visible';

    public function handle(SchedulerHeartbeatStore $temoin): int
    {
        $temoin->touch();

        $this->components->info('Passage du planificateur enregistré.');

        return self::SUCCESS;
    }
}
