<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\Asset;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WatchAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Détection des pics de consultation (ST-0405) et déclenchement des veilles
 * (ST-0403).
 *
 * Un bien soudain très consulté est un bien qu'on montre à plusieurs acheteurs.
 * Pour son propriétaire, c'est soit une vente qu'il conduit lui-même — et
 * l'alerte ne le surprendra pas —, soit une vente que quelqu'un d'autre
 * conduit à sa place. Le second cas est exactement la fraude que la plateforme
 * existe pour attraper, et il se produit AVANT la transaction : c'est le seul
 * moment où l'alerte a encore une valeur.
 *
 * L'alerte reste ANONYME et AGRÉGÉE, comme toute notification de consultation :
 * un compteur et une période, jamais qui a consulté (règle métier absolue
 * n° 4). Elle dit qu'il se passe quelque chose, pas qui le fait.
 *
 * Le silence entre deux alertes évite qu'un pic durable produise une alerte à
 * chaque passage : une alerte répétée finit ignorée, et c'est précisément
 * celle-là qu'il ne faut pas manquer.
 */
final class DetectLookupSpikes extends Command
{
    protected $signature = 'preuve:detect-lookup-spikes
                            {--threshold= : Consultations à partir desquelles alerter}
                            {--window= : Fenêtre d\'observation, en heures}';

    protected $description = 'Repère les biens anormalement consultés et déclenche les veilles';

    public function handle(NotificationService $notifications, WatchAlertService $watchers): int
    {
        $seuil = $this->intOption('threshold', 'preuve.lookup_spike.threshold', 5);
        $fenetre = $this->intOption('window', 'preuve.lookup_spike.window_hours', 24);
        $silence = $this->configInt('preuve.lookup_spike.cooldown_hours', 24);

        $depuis = now()->subHours($fenetre);

        $pics = DB::table('lookups')
            ->select('found_asset_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('found_asset_id')
            ->where('created_at', '>=', $depuis->format('Y-m-d H:i:s'))
            ->groupBy('found_asset_id')
            ->havingRaw('COUNT(*) >= ?', [$seuil])
            ->get();

        $alertes = 0;

        foreach ($pics as $ligne) {
            $bien = Asset::query()->whereKey($ligne->found_asset_id)->first();

            if (! $bien instanceof Asset) {
                continue;
            }

            $total = is_numeric($ligne->total) ? (int) $ligne->total : 0;

            // Les consultations du propriétaire sur son propre bien ne font
            // pas un pic : il connaît déjà son bien.
            $total -= $this->selfLookups($bien, $depuis);

            if ($total < $seuil) {
                continue;
            }

            $alertes += $this->emettreAlertes($notifications, $watchers, $bien, $total, $fenetre, $silence);
        }

        $this->components->info(
            "{$alertes} alerte(s) de pic émise(s) (seuil {$seuil} sur {$fenetre} h)."
        );

        return self::SUCCESS;
    }

    /**
     * Prévient le détenteur courant et les veilleurs de l'identifiant. Les deux
     * ne se recouvrent pas : le détenteur enregistré peut être celui qui a
     * dépossédé le veilleur.
     */
    private function emettreAlertes(
        NotificationService $notifications,
        WatchAlertService $watchers,
        Asset $bien,
        int $consultations,
        int $fenetre,
        int $silence,
    ): int {
        $emises = 0;
        $prevenus = [];

        $detenteur = $bien->owner;

        if ($detenteur instanceof User && $this->holderMayBeAlerted($bien, $silence)) {
            $notifications->notify(
                $detenteur,
                NotificationType::LookupSpike,
                'Votre bien est très consulté en ce moment',
                $this->body($consultations, $fenetre),
                $bien,
                ['count' => $consultations, 'window_hours' => $fenetre],
            );

            DB::table('assets')->where('id', $bien->id)->update(['spike_alerted_at' => now()]);
            $prevenus[] = $detenteur->id;
            $emises++;
        }

        foreach ($watchers->activeWatchersFor($bien->identifier_normalized, $silence) as $veille) {
            if (in_array($veille->user_id, $prevenus, true)) {
                // Déjà prévenu comme détenteur : une seconde notification pour
                // le même événement n'apprendrait rien.
                $veille->fill(['last_triggered_at' => now()])->save();

                continue;
            }

            $veilleur = $veille->user;

            if (! $veilleur instanceof User) {
                continue;
            }

            $notifications->notify(
                $veilleur,
                NotificationType::LookupSpike,
                'Un identifiant que vous surveillez est très consulté',
                $this->body($consultations, $fenetre),
                null,
                ['count' => $consultations, 'window_hours' => $fenetre],
            );

            $veille->fill(['last_triggered_at' => now()])->save();
            $emises++;
        }

        return $emises;
    }

    private function body(int $consultations, int $fenetre): string
    {
        return "Ce bien a été consulté {$consultations} fois en {$fenetre} heures, bien plus que d'habitude. ".
            "Si vous n'êtes pas en train de le vendre, vérifiez qu'il est toujours en votre possession. ".
            'Les consultations sont anonymes : PREUVE ne vous dira jamais qui consulte.';
    }

    /** Silence propre au bien, pour ne pas renotifier le détenteur à chaque passage. */
    private function holderMayBeAlerted(Asset $bien, int $silence): bool
    {
        $derniere = DB::table('assets')->where('id', $bien->id)->value('spike_alerted_at');

        if (! is_string($derniere)) {
            return true;
        }

        return now()->subHours($silence)->gt($derniere);
    }

    private function selfLookups(Asset $bien, Carbon $depuis): int
    {
        return DB::table('lookups')
            ->where('found_asset_id', $bien->id)
            ->where('user_id', $bien->owner_user_id)
            ->where('created_at', '>=', $depuis->format('Y-m-d H:i:s'))
            ->count();
    }

    private function intOption(string $option, string $cle, int $defaut): int
    {
        $valeur = $this->option($option);

        return is_numeric($valeur) ? max(1, (int) $valeur) : $this->configInt($cle, $defaut);
    }

    private function configInt(string $cle, int $defaut): int
    {
        $valeur = config($cle, $defaut);

        return is_numeric($valeur) && (int) $valeur > 0 ? (int) $valeur : $defaut;
    }
}
