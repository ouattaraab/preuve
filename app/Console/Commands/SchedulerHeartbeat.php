<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditAnchorService;
use App\Services\HeartbeatPing;
use App\Services\SchedulerHeartbeatStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 *
 * ELLE PRÉVIENT AUSSI UN SURVEILLANT EXTÉRIEUR. Le témoin local ne vaut que si
 * quelqu'un le lit, et tout ce qui pourrait le lire s'exécute sur la machine
 * qui, le jour venu, sera tombée. Le ping sortant renverse le montage : c'est
 * le SILENCE de la plateforme qui déclenche l'alerte, et un serveur mort ne
 * peut pas mentir par omission. Voir HeartbeatPing.
 */
final class SchedulerHeartbeat extends Command
{
    protected $signature = 'preuve:heartbeat';

    protected $description = 'Marque le passage du planificateur, pour que son arrêt devienne visible';

    public function handle(
        SchedulerHeartbeatStore $temoin,
        HeartbeatPing $surveillant,
        AuditAnchorService $ancrages,
    ): int {
        $temoin->touch();

        $this->components->info('Passage du planificateur enregistré.');

        if (! $surveillant->isConfigured()) {
            return self::SUCCESS;
        }

        $anomalies = $this->anomalies($ancrages);

        // ON SIGNALE L'ANOMALIE PAR UN CHEMIN QUI N'EST PAS LE NÔTRE. Le jour
        // où la passerelle de messagerie est en panne — celui où l'on a le plus
        // besoin d'être prévenu — c'est la seule voie qui reste.
        $surveillant->send(
            enPanne: $anomalies !== [],
            detail: $anomalies === []
                ? 'Planificateur actif, base joignable, ancrage récent.'
                : implode(' · ', $anomalies),
        );

        return self::SUCCESS;
    }

    /**
     * Ce qui ne va pas, en une phrase chacun.
     *
     * LES MÊMES CONTRÔLES QUE LA SONDE PUBLIQUE, et pas d'autres : deux
     * définitions de « en bonne santé » finiraient par diverger, et c'est
     * toujours celle qu'on ne regarde pas qui a raison.
     *
     * @return list<string>
     */
    private function anomalies(AuditAnchorService $ancrages): array
    {
        $anomalies = [];

        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            // La base est injoignable : inutile d'interroger l'ancrage, qui y
            // vit. Et c'est déjà l'anomalie la plus grave qu'on puisse signaler.
            return ['Base de données injoignable.'];
        }

        try {
            $dernier = $ancrages->lastSuccessful();

            if ($dernier === null || $dernier->created_at->lt(now()->subHours(48))) {
                $anomalies[] = 'Ancrage de la chaîne d\'audit trop ancien : elle n\'est plus opposable.';
            }
        } catch (Throwable) {
            $anomalies[] = 'État de l\'ancrage non vérifiable.';
        }

        return $anomalies;
    }
}
