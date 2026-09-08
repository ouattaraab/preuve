<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Témoin de passage du planificateur (ST-0904).
 *
 * ÉCRIT EN BASE, PAS EN CACHE. Le cache se vide — au déploiement, par
 * `cache:clear`, ou simplement parce qu'un magasin de cache a le droit d'oublier.
 * Un témoin qui disparaît tout seul se lit comme « le planificateur est
 * arrêté », et une alerte qui crie au loup finit ignorée. Ce témoin doit être
 * aussi durable que ce qu'il atteste.
 *
 * IL NE PASSE PAS PAR LA CHAÎNE D'AUDIT. Un passage de planificateur n'est pas
 * une action métier attestable, et le journaliser ferait passer un battement
 * régulier par le verrou nommé dont le plafond mesuré est d'une quinzaine
 * d'actions simultanées.
 */
final class SchedulerHeartbeatStore
{
    private const CLE = 'scheduler.last_run_at';

    /** Au-delà, on considère que le planificateur ne tourne plus. */
    private const SEUIL_MINUTES = 20;

    public function touch(): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::CLE],
            [
                'value' => json_encode(now()->toIso8601String()),
                'is_secret' => false,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function lastRunAt(): ?Carbon
    {
        try {
            $valeur = DB::table('app_settings')->where('key', self::CLE)->value('value');
        } catch (Throwable) {
            return null;
        }

        if (! is_string($valeur)) {
            return null;
        }

        $horodatage = json_decode($valeur, true);

        if (! is_string($horodatage)) {
            return null;
        }

        try {
            return Carbon::parse($horodatage);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * `never` et `stale` sont distingués à dessein : jamais démarré est un
     * défaut d'installation, s'être arrêté est un incident. Les confondre
     * enverrait chercher au mauvais endroit.
     *
     * @return 'ok'|'stale'|'never'
     */
    public function status(): string
    {
        $dernier = $this->lastRunAt();

        if ($dernier === null) {
            return 'never';
        }

        return $dernier->gt(now()->subMinutes(self::SEUIL_MINUTES)) ? 'ok' : 'stale';
    }
}
