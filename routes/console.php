<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * TOUTES PORTENT AUSSI `onOneServer()`, ET CE N'EST PAS DÉCORATIF.
 *
 * CONSTATÉ EN PRODUCTION LE 04/08/2026 : le planificateur était invoqué DEUX
 * FOIS, à trois secondes d'intervalle — deux sauvegardes à 01:30:07 et
 * 01:30:10, deux ancrages à 02:40:06 et 02:40:09. `withoutOverlapping()` n'y
 * peut rien : il empêche un chevauchement, or la première exécution était
 * terminée avant que la seconde ne commence. `onOneServer()` prend un verrou
 * porté par la tâche ET la minute, et le garde jusqu'à la fin de celle-ci :
 * c'est la seule primitive qui rende une double invocation inoffensive.
 *
 * La cause est en amont — une tâche cron déclarée deux fois chez l'hébergeur —
 * et elle ne nous appartient pas. Une protection qui dépend d'une console tierce
 * n'est pas une protection.
 *
 * Les tâches qui écrivent portent toutes `withoutOverlapping()` : elles
 * prennent le verrou nommé de la chaîne d'audit, dont le plafond mesuré est
 * d'une quinzaine d'actions simultanées. Deux passages qui se chevauchent se
 * disputeraient ce verrou et se rejetteraient l'un l'autre, en même temps que
 * les actions des utilisateurs.
 *
 * Leurs minutes de départ sont décalées pour la même raison : lancées ensemble,
 * elles entreraient en concurrence dès la première seconde.
 */

/*
 * Témoin de passage du planificateur (ST-0904). Toutes les cinq minutes : assez
 * souvent pour que son arrêt se voie vite, assez rare pour ne rien coûter.
 *
 * Il ne prend aucun verrou d'audit et n'écrit qu'un horodatage. Sa seule raison
 * d'être est de rendre VISIBLE un planificateur arrêté — panne constatée le
 * 03/08/2026 sur l'hébergement, sans une erreur ni un journal.
 */
/*
 * EXÉCUTÉ EN PROCESSUS, ET NON DANS UN SOUS-PROCESSUS.
 *
 * `Schedule::command()` lance chaque tâche via `proc_open` — un fork. Sur cet
 * hébergement mutualisé, le plafond de processus (`nproc`) est parfois atteint,
 * et le fork échoue : « proc_open(): Fork failed: Resource temporarily
 * unavailable » (constaté ~2 à 4 fois par jour). C'est le HEARTBEAT lui-même
 * qui en était victime — la sonde censée rendre visible un planificateur arrêté
 * ratait son passage à cause de la panne qu'elle existe pour détecter, et
 * inscrivait au passage un stacktrace en ERROR.
 *
 * `Artisan::call()` exécute la commande DANS le processus `schedule:run`
 * déjà en vie : aucun fork, donc plus rien à échouer de ce côté. La tâche est
 * légère et son seul appel sortant — le ping — est borné à cinq secondes ; la
 * passer en ligne n'expose pas `schedule:run` à un blocage. Cela ne supprime
 * pas la cause de fond (le `nproc` du mutualisé, hors de notre main), mais
 * retire la tâche la plus fréquente de la file des forks et, surtout, cesse de
 * faire dépendre la sonde de santé du fork qui peut la faire tomber.
 *
 * `name()` est requis : `onOneServer()` a besoin d'un identifiant de verrou,
 * que `command()` tirait du nom de la commande et qu'une closure n'a pas.
 */
Schedule::call(static fn () => Artisan::call('preuve:heartbeat'))
    ->name('preuve:heartbeat')
    ->everyFiveMinutes()
    ->onOneServer();

/*
 * Travailleur de la file `notifications` (ST-1003, ST-1004).
 *
 * L'hébergement mutualisé n'offre ni superviseur ni processus persistant : le
 * travailleur est donc RELANCÉ chaque minute par le planificateur, vide la file
 * et sort. `--stop-when-empty` évite qu'il attende pour rien, `--max-time=50`
 * qu'il déborde sur le passage suivant, et `withoutOverlapping()` qu'ils
 * s'empilent.
 *
 * Conséquence assumée : une alerte peut attendre jusqu'à une minute avant de
 * quitter la plateforme. C'est le prix du mutualisé, et il est sans commune
 * mesure avec ce qu'on y gagne — l'action métier ne paie plus l'attente d'une
 * passerelle, et un échec est désormais rejoué au lieu d'être perdu.
 */
Schedule::command('queue:work --queue=notifications --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()->onOneServer();

// Clôture des fenêtres de contestation arrivées à terme (ST-0402). Horaire
// plutôt que quotidien : un bien enregistré à 14 h ne doit pas attendre le
// lendemain matin pour devenir Actif au bout de ses 30 jours.
Schedule::command('preuve:promote-provisional')->hourlyAt(5)->withoutOverlapping()->onOneServer();

// Agrégation horaire des consultations (ST-1002) : jamais de
// notification unitaire, qui apprendrait au propriétaire le rythme exact
// des visites.
Schedule::command('preuve:aggregate-lookups')->hourlyAt(20)->withoutOverlapping()->onOneServer();

// Relances du contradictoire et clôture des délais échus (ST-0504). Quotidien :
// les rappels se comptent en jours, un passage horaire n'apporterait rien.
Schedule::command('preuve:remind-contradictory')->dailyAt('08:15')->withoutOverlapping()->onOneServer();

// Clôture des transferts non confirmés à J+7 (ST-0601) : un transfert laissé
// ouvert maintiendrait le bien en « Transfert en cours », donc averti aux
// acheteurs, indéfiniment.
Schedule::command('preuve:expire-transfers')->hourlyAt(50)->withoutOverlapping()->onOneServer();

// Détection des pics de consultation (ST-0405) : le signal arrive avant la
// transaction, seul moment où il a encore une valeur.
Schedule::command('preuve:detect-lookup-spikes')->hourlyAt(35)->withoutOverlapping()->onOneServer();

// Ancrage quotidien du hash de tête hors de la plateforme (ST-0106).
// SANS LUI, LA CHAÎNE N'EST PAS OPPOSABLE : son algorithme est public et sans
// secret, donc reproductible par quiconque peut écrire en base. La commande
// sort en échec si aucun canal externe n'aboutit, ce que le planificateur
// remonte.
Schedule::command('preuve:anchor-audit-head')->dailyAt('02:40')->withoutOverlapping()->onOneServer();

// Relances d'abonnement et suspension douce (ST-0805). Quotidien : les retards
// se comptent en jours, et aucune relance ne retire la protection acquise.
Schedule::command('preuve:subscription-dunning')->dailyAt('09:10')->withoutOverlapping()->onOneServer();

// Sauvegarde chiffrée quotidienne, déposée hors machine (ST-0904). Ne fait
// rien tant qu'aucun disque de sauvegarde n'est configuré.
Schedule::command('preuve:backup')->dailyAt('01:30')->withoutOverlapping()->onOneServer();

// Les pièces du bucket, sans lesquelles la base restaurée renverrait à des
// fichiers introuvables (ST-0904). Après le dump : une pièce sauvegardée sans
// la ligne qui la désigne ne se rattache à rien.
Schedule::command('preuve:backup-documents')->dailyAt('01:50')->withoutOverlapping()->onOneServer();

// Contrôle d'intégrité des pièces, hebdomadaire et non quotidien : il RELIT
// tout le bucket, quand la sauvegarde ne lit que ce qui manque. C'est le seul
// chemin qui voit une pièce substituée après avoir été sauvegardée — celle que
// l'incrémental ne touche plus jamais.
Schedule::command('preuve:backup-documents --verify')
    ->weeklyOn(0, '04:30')
    ->withoutOverlapping()->onOneServer();

/*
 * Réconciliation du bucket et de la base, le 1er du mois (ST-0904).
 *
 * Mensuelle et non hebdomadaire : elle LISTE le bucket entier, ce que les
 * autres commandes évitent délibérément. Passée trop souvent, elle coûterait
 * plus qu'elle ne rapporte — les divergences qu'elle attrape s'installent sur
 * des semaines, pas sur des heures.
 *
 * Son rapport est ÉCRIT SUR DISQUE, et c'est indispensable : elle est la seule
 * commande qui voit les pièces orphelines, et elle sort en échec dès qu'il y en
 * a. Sans trace, un passage mensuel finirait dans /dev/null et la seule
 * détection possible de ces pièces n'aurait jamais lieu — exactement le défaut
 * silencieux qu'elle existe pour lever.
 *
 * Elle ne prend aucun verrou d'audit : elle ne fait que lire, d'où l'absence de
 * `withoutOverlapping()` ailleurs systématique.
 *
 * SON JOURNAL N'EST PLUS ÉCRIT PAR `appendOutputTo()`, mais par le rapporteur
 * d'exploitation lui-même. Deux raisons. La première : `appendOutputTo()` n'agit
 * que sous le planificateur — un passage manuel laissait le courriel renvoyer à
 * un fichier qui n'existait pas. La seconde : il n'a AUCUNE rotation, et sur un
 * hébergement dont le quota est partagé avec les pièces justificatives, un
 * fichier qui ne cesse jamais de grossir finit par arrêter toute écriture.
 */
Schedule::command('preuve:reconcile-documents')
    ->monthlyOn(1, '05:00')
    ->onOneServer();

// Politique ARTCI : aucune consultation conservée au-delà de 12 mois
// (ST-0304). Aux heures creuses, la table pouvant être volumineuse.
/*
 * LA PLACE QUI RESTE, tous les jours.
 *
 * `disk_free_space` ment sur un mutualisé — elle rend le volume entier de
 * l'hébergeur. Cette tâche éprouve donc l'ÉCRITURE elle-même, ce qui constate
 * le vrai mode de panne au lieu de l'estimer. Un quota atteint n'arrête pas
 * seulement les envois de pièces : il arrête l'écriture du registre, de la
 * chaîne d'audit, et des journaux — donc de la trace qui aurait permis de
 * comprendre.
 *
 * Tôt le matin, avant les sauvegardes : savoir que le disque est plein AVANT
 * de tenter d'y écrire une sauvegarde vaut mieux que de l'apprendre par son
 * échec.
 */
Schedule::command('preuve:check-storage')->dailyAt('01:10')->onOneServer();

/*
 * LE POINT HEBDOMADAIRE.
 *
 * Les alertes disent qu'une chose a cassé ; elles ne disent jamais ce qui se
 * dégrade lentement — une file de modération qui s'allonge, un ancrage qui ne
 * tourne plus, des travaux en échec qui s'accumulent. Ces états ne produisent
 * aucun événement : ils s'installent.
 *
 * Lundi matin, avant la semaine de travail, et pas vendredi soir.
 */
Schedule::command('preuve:weekly-digest')->weeklyOn(1, '07:00')->onOneServer();

Schedule::command('preuve:purge-lookups')->dailyAt('03:20')->onOneServer();

// Envois différés abandonnés (ST-0206). Plus fréquent que les autres purges :
// ce sont des fichiers, sur le disque de travail que partagent les sessions et
// le cache — laissés à eux-mêmes, ils le remplissent.
Schedule::command('preuve:purge-uploads')->hourlyAt(40)->withoutOverlapping()->onOneServer();
