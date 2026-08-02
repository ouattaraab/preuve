<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\OpsReportMail;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Acheminement des rapports d'exploitation vers un destinataire humain
 * (ST-0904).
 *
 * UN RAPPORT QUE PERSONNE N'OUVRE NE VAUT PAS MIEUX QUE PAS DE RAPPORT. Les
 * tâches planifiées de PREUVE détectent des défauts silencieux — pièces
 * orphelines, chaîne non ancrée, sauvegarde amputée — c'est-à-dire des états où
 * tout paraît fonctionner. Un journal déposé sur le serveur ne lève rien tant
 * que personne ne va le lire, et personne ne va lire ce qu'il ne sait pas
 * attendre.
 *
 * IL PART MÊME QUAND TOUT VA BIEN, et c'est délibéré. Un rapport qui n'arrive
 * qu'en cas d'anomalie est indistinguable d'une tâche qui a cessé de tourner :
 * le silence devient ambigu, et l'ambiguïté se lit toujours comme « rien à
 * signaler ». Sur une cadence mensuelle, le coût du bruit est nul.
 *
 * IL NE FAIT JAMAIS ÉCHOUER CE QU'IL RAPPORTE. Une passerelle de messagerie
 * indisponible ne doit ni masquer un rapport sain, ni transformer un rapport
 * d'anomalie en trace d'exception. Le constat vaut par lui-même ; l'envoi n'en
 * est que l'acheminement.
 *
 * IL N'EXPÉDIE QU'UN EXTRAIT. Le détail complet reste dans le journal, sur le
 * serveur : une messagerie traverse des relais que la plateforme ne maîtrise
 * pas, et un rapport de plusieurs centaines de lignes y perdrait de toute façon
 * son lecteur. Le courriel dit quoi, combien, et où regarder.
 */
final class OpsReporter
{
    public const RECIPIENT_SETTING = 'ops.report_recipient';

    /** Au-delà, le détail appartient au journal, pas à la messagerie. */
    private const LIGNES_EXTRAITES = 40;

    public function __construct(private readonly SettingsRepository $settings) {}

    public function isConfigured(): bool
    {
        return $this->recipient() !== null;
    }

    public function recipient(): ?string
    {
        $adresse = $this->settings->get(self::RECIPIENT_SETTING);

        return is_string($adresse) && $adresse !== '' ? $adresse : null;
    }

    /**
     * Expédie un rapport. Rend vrai si un courriel est parti.
     *
     * @param  bool  $anomalie  vrai si le rapport signale quelque chose à traiter
     */
    public function send(string $titre, string $corps, bool $anomalie, ?string $journal = null): bool
    {
        $destinataire = $this->recipient();

        if ($destinataire === null) {
            return false;
        }

        try {
            Mail::to($destinataire)->send(new OpsReportMail(
                titre: $titre,
                anomalie: $anomalie,
                corps: $this->extrait($corps),
                journal: $journal,
            ));

            return true;
        } catch (Throwable $e) {
            // Silence volontaire vis-à-vis de l'appelant, mais pas du journal :
            // une passerelle muette qui le resterait ferait croire à des
            // rapports envoyés, donc à une surveillance qui n'existe pas.
            Log::warning('Rapport d\'exploitation non expédié', ['exception' => $e::class]);

            return false;
        }
    }

    /**
     * Tronque en disant qu'il tronque : un extrait silencieux se lirait comme
     * un rapport complet, et c'est précisément la lecture qu'il ne faut pas
     * induire dans un courriel d'anomalie.
     */
    private function extrait(string $corps): string
    {
        $lignes = preg_split('/\R/', trim($corps)) ?: [];

        if (count($lignes) <= self::LIGNES_EXTRAITES) {
            return implode(PHP_EOL, $lignes);
        }

        $reste = count($lignes) - self::LIGNES_EXTRAITES;

        return implode(PHP_EOL, array_slice($lignes, 0, self::LIGNES_EXTRAITES)).PHP_EOL.PHP_EOL.
            "[… {$reste} ligne(s) supplémentaire(s) : le détail complet est dans le journal sur le serveur.]";
    }
}
