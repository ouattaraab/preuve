<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
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
Schedule::command('preuve:heartbeat')->everyFiveMinutes();

// Clôture des fenêtres de contestation arrivées à terme (ST-0402). Horaire
// plutôt que quotidien : un bien enregistré à 14 h ne doit pas attendre le
// lendemain matin pour devenir Actif au bout de ses 30 jours.
Schedule::command('preuve:promote-provisional')->hourlyAt(5)->withoutOverlapping();

// Agrégation horaire des consultations (ST-1002) : jamais de
// notification unitaire, qui apprendrait au propriétaire le rythme exact
// des visites.
Schedule::command('preuve:aggregate-lookups')->hourlyAt(20)->withoutOverlapping();

// Relances du contradictoire et clôture des délais échus (ST-0504). Quotidien :
// les rappels se comptent en jours, un passage horaire n'apporterait rien.
Schedule::command('preuve:remind-contradictory')->dailyAt('08:15')->withoutOverlapping();

// Clôture des transferts non confirmés à J+7 (ST-0601) : un transfert laissé
// ouvert maintiendrait le bien en « Transfert en cours », donc averti aux
// acheteurs, indéfiniment.
Schedule::command('preuve:expire-transfers')->hourlyAt(50)->withoutOverlapping();

// Détection des pics de consultation (ST-0405) : le signal arrive avant la
// transaction, seul moment où il a encore une valeur.
Schedule::command('preuve:detect-lookup-spikes')->hourlyAt(35)->withoutOverlapping();

// Ancrage quotidien du hash de tête hors de la plateforme (ST-0106).
// SANS LUI, LA CHAÎNE N'EST PAS OPPOSABLE : son algorithme est public et sans
// secret, donc reproductible par quiconque peut écrire en base. La commande
// sort en échec si aucun canal externe n'aboutit, ce que le planificateur
// remonte.
Schedule::command('preuve:anchor-audit-head')->dailyAt('02:40')->withoutOverlapping();

// Relances d'abonnement et suspension douce (ST-0805). Quotidien : les retards
// se comptent en jours, et aucune relance ne retire la protection acquise.
Schedule::command('preuve:subscription-dunning')->dailyAt('09:10')->withoutOverlapping();

// Sauvegarde chiffrée quotidienne, déposée hors machine (ST-0904). Ne fait
// rien tant qu'aucun disque de sauvegarde n'est configuré.
Schedule::command('preuve:backup')->dailyAt('01:30')->withoutOverlapping();

// Les pièces du bucket, sans lesquelles la base restaurée renverrait à des
// fichiers introuvables (ST-0904). Après le dump : une pièce sauvegardée sans
// la ligne qui la désigne ne se rattache à rien.
Schedule::command('preuve:backup-documents')->dailyAt('01:50')->withoutOverlapping();

// Contrôle d'intégrité des pièces, hebdomadaire et non quotidien : il RELIT
// tout le bucket, quand la sauvegarde ne lit que ce qui manque. C'est le seul
// chemin qui voit une pièce substituée après avoir été sauvegardée — celle que
// l'incrémental ne touche plus jamais.
Schedule::command('preuve:backup-documents --verify')
    ->weeklyOn(0, '04:30')
    ->withoutOverlapping();

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
 */
Schedule::command('preuve:reconcile-documents')
    ->monthlyOn(1, '05:00')
    ->appendOutputTo(storage_path('logs/reconciliation-documents.log'));

// Politique ARTCI : aucune consultation conservée au-delà de 12 mois
// (ST-0304). Aux heures creuses, la table pouvant être volumineuse.
Schedule::command('preuve:purge-lookups')->dailyAt('03:20');

// Envois différés abandonnés (ST-0206). Plus fréquent que les autres purges :
// ce sont des fichiers, sur le disque de travail que partagent les sessions et
// le cache — laissés à eux-mêmes, ils le remplissent.
Schedule::command('preuve:purge-uploads')->hourlyAt(40)->withoutOverlapping();
