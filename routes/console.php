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

// Politique ARTCI : aucune consultation conservée au-delà de 12 mois
// (ST-0304). Aux heures creuses, la table pouvant être volumineuse.
Schedule::command('preuve:purge-lookups')->dailyAt('03:20');
