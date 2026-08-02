<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\NotificationService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Agrège les consultations de la période écoulée en une notification par bien
 * (ST-1002).
 *
 * L'agrégation n'est pas un confort d'implémentation mais une règle produit
 * (systemPatterns §5) : une notification par consultation apprendrait au
 * propriétaire le rythme exact des visites — donc, par recoupement avec ce
 * qu'il sait par ailleurs, qui regarde son bien et quand. Elle noierait aussi
 * le signal utile sous le bruit, jusqu'à faire désactiver l'alerte.
 *
 * La borne haute de la période est figée au début de l'exécution et retenue
 * comme curseur : sans elle, un passage lent recompterait les consultations
 * arrivées pendant son propre traitement, et le propriétaire recevrait deux
 * fois le même compte.
 */
final class AggregateLookupNotifications extends Command
{
    protected $signature = 'preuve:aggregate-lookups';

    protected $description = 'Notifie chaque propriétaire des consultations agrégées de la période écoulée';

    /** Curseur de la dernière période traitée. */
    private const CURSOR_KEY = 'notifications.lookups.last_run_at';

    /**
     * Période retenue au tout premier passage, quand aucun curseur n'existe.
     * Volontairement courte : à l'installation, il ne faut pas notifier
     * rétroactivement des mois de consultations.
     */
    private const FIRST_RUN_WINDOW_HOURS = 1;

    public function handle(SettingsRepository $settings, NotificationService $notifications): int
    {
        $fin = now();
        $curseur = $settings->fresh()->get(self::CURSOR_KEY);

        $debut = is_string($curseur)
            ? Carbon::parse($curseur)
            : $fin->copy()->subHours(self::FIRST_RUN_WINDOW_HOURS);

        // Le curseur est posé même si aucune notification n'est émise :
        // sinon un passage sans consultation ferait s'allonger indéfiniment la
        // période du passage suivant.
        $settings->set(self::CURSOR_KEY, $fin->format('Y-m-d H:i:s'));

        $comptes = DB::table('lookups')
            ->select('found_asset_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('found_asset_id')
            ->where('created_at', '>=', $debut->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $fin->format('Y-m-d H:i:s'))
            ->groupBy('found_asset_id')
            ->get();

        $notifies = 0;

        foreach ($comptes as $ligne) {
            $bien = Asset::query()->whereKey($ligne->found_asset_id)->first();

            if (! $bien instanceof Asset) {
                continue;
            }

            // Les consultations que le propriétaire fait de son propre bien ne
            // comptent pas : « votre bien a été consulté 3 fois » alors qu'il
            // s'agit de lui-même détruirait la confiance dans le signal.
            $brut = is_numeric($ligne->total) ? (int) $ligne->total : 0;
            $total = $brut - $this->selfLookups($bien, $debut, $fin);

            if ($total < 1) {
                continue;
            }

            if ($notifications->notifyLookupActivity($bien, $total, $debut, $fin) !== null) {
                $notifies++;
            }
        }

        $this->components->info(
            "{$notifies} propriétaire(s) notifié(s) pour la période du ".
            $debut->format('d/m/Y H:i').' au '.$fin->format('d/m/Y H:i').'.'
        );

        return self::SUCCESS;
    }

    private function selfLookups(Asset $bien, Carbon $debut, Carbon $fin): int
    {
        return DB::table('lookups')
            ->where('found_asset_id', $bien->id)
            ->where('user_id', $bien->owner_user_id)
            ->where('created_at', '>=', $debut->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $fin->format('Y-m-d H:i:s'))
            ->count();
    }
}
