<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Signale au surveillant EXTÉRIEUR que la plateforme tourne (ST-0904).
 *
 * POURQUOI L'EXTÉRIEUR. Tout ce que PREUVE sait de sa propre santé s'exécute
 * DANS PREUVE : le témoin du planificateur, la sonde `/health`, les alertes par
 * courriel. Aucun de ces dispositifs ne peut signaler que le serveur est tombé,
 * puisqu'il tombe avec lui. Il faut donc quelqu'un dehors, et le seul montage
 * qui fonctionne est l'INVERSE d'une supervision ordinaire : ce n'est pas le
 * surveillant qui interroge la plateforme, c'est la plateforme qui donne
 * régulièrement signe de vie, et son SILENCE déclenche l'alerte. Un serveur
 * mort ne peut pas mentir par omission.
 *
 * DEUX SIGNAUX, ET C'EST CE QUI FAIT SA VALEUR :
 *
 * — le ping ordinaire dit « je tourne » ;
 * — `/fail` dit « je tourne, mais quelque chose ne va pas ».
 *
 * Le second compte autant que le premier : il emprunte un chemin entièrement
 * distinct de nos alertes par courriel. Le jour où la passerelle de messagerie
 * est en panne — c'est-à-dire précisément le jour où l'on a le plus besoin
 * d'être prévenu — c'est la seule voie qui reste.
 *
 * L'URL EST UNE CAPACITÉ AU PORTEUR : qui la détient peut maintenir la sonde au
 * vert pendant que le serveur est mort. Elle est donc rangée avec les secrets
 * d'exploitation, jamais dans le dépôt.
 *
 * ELLE NE PEUT RIEN CASSER. Délai court, et toute erreur avalée : un
 * surveillant injoignable ne doit pas retarder le planificateur ni faire échouer
 * les tâches qu'il observe. Une supervision qui casse ce qu'elle surveille est
 * pire que pas de supervision.
 */
final class HeartbeatPing
{
    public const URL_SETTING = 'ops.heartbeat_url';

    /**
     * Court, et volontairement : ce ping s'exécute toutes les cinq minutes
     * dans le planificateur, devant des tâches qui, elles, ont du travail.
     */
    private const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly SettingsRepository $settings) {}

    public function isConfigured(): bool
    {
        return $this->url() !== null;
    }

    /**
     * Donne signe de vie.
     *
     * @param  bool  $enPanne  vrai pour signaler une anomalie plutôt qu'un
     *                         simple « je tourne »
     * @param  string  $detail  ce que le surveillant affichera à côté du ping,
     *                          pour qu'un courriel d'alerte dise QUOI et pas
     *                          seulement QUAND
     */
    public function send(bool $enPanne = false, string $detail = ''): bool
    {
        $url = $this->url();

        if ($url === null) {
            return false;
        }

        try {
            $reponse = Http::timeout(self::TIMEOUT_SECONDS)
                ->withBody($detail, 'text/plain')
                ->post($enPanne ? rtrim($url, '/').'/fail' : $url);

            return $reponse->successful();
        } catch (Throwable) {
            // Volontairement muet, et sans journalisation : une erreur
            // journalisée ici déclencherait notre propre canal d'alerte à
            // chaque coupure réseau passagère, toutes les cinq minutes.
            return false;
        }
    }

    private function url(): ?string
    {
        $url = $this->settings->get(self::URL_SETTING);

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}
