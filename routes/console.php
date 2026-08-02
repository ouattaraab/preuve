<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Agrégation horaire des consultations (ST-1002) : jamais de
// notification unitaire, qui apprendrait au propriétaire le rythme exact
// des visites.
Schedule::command('preuve:aggregate-lookups')->hourly();

// Politique ARTCI : aucune consultation conservée au-delà de 12 mois
// (ST-0304). Aux heures creuses, la table pouvant être volumineuse.
Schedule::command('preuve:purge-lookups')->dailyAt('03:20');
