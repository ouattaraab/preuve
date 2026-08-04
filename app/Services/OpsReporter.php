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
 * SA VALEUR VIENT DE SA RARETÉ. Toutes les tâches n'ont pas à écrire ici : une
 * purge qui purge n'apprend rien à personne, et un canal alimenté par tout
 * finirait filtré — emportant avec lui les rapports qui comptent. N'y écrivent
 * que les tâches capables de détecter un défaut SILENCIEUX, celui qu'aucun
 * utilisateur ne verra jamais : chaîne non ancrée, sauvegarde absente, pièce
 * disparue, pièce orpheline.
 *
 * DEUX CADENCES, DEUX RÈGLES. Les contrôles espacés — hebdomadaire, mensuel —
 * écrivent dans TOUS les cas : un rapport qui n'arriverait qu'en cas d'anomalie
 * est indistinguable d'une tâche qui a cessé de tourner, et l'ambiguïté se lit
 * toujours comme « rien à signaler ». Les tâches quotidiennes, elles, n'écrivent
 * qu'en cas d'anomalie (voir `alert()`) : leur accusé quotidien serait le bruit
 * qui ferait perdre l'habitude d'ouvrir les autres.
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
 *
 * ET C'EST LUI QUI ÉCRIT CE JOURNAL. Le chemin annoncé au lecteur n'était
 * qu'une chaîne de caractères : aucune commande ne créait le fichier, et
 * l'exploitant qui allait chercher le détail promis ne trouvait rien — pire
 * qu'aucune indication, puisqu'il cherchait. Celui qui annonce le chemin est
 * désormais celui qui l'écrit ; la promesse ne peut plus être fausse pour une
 * commande qui aurait oublié.
 *
 * LE JOURNAL EST ÉCRIT MÊME QUAND LE COURRIEL NE PART PAS. Un destinataire non
 * réglé, une passerelle en panne : ce sont précisément les moments où la trace
 * locale est la seule qui reste.
 */
final class OpsReporter
{
    public const RECIPIENT_SETTING = 'ops.report_recipient';

    /** Au-delà, le détail appartient au journal, pas à la messagerie. */
    private const LIGNES_EXTRAITES = 40;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly OpsJournal $journalLocal,
    ) {}

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
     * Alerte : n'expédie QUE s'il y a quelque chose à traiter.
     *
     * Réservée aux tâches QUOTIDIENNES. Leur envoyer un accusé chaque jour
     * produirait trois cent soixante-cinq messages par an, et le destinataire
     * apprendrait à les archiver sans les ouvrir — y compris celui qui compte.
     *
     * Leur silence n'est pas ambigu pour autant : les contrôles hebdomadaire et
     * mensuel, eux, écrivent dans tous les cas. Si RIEN n'arrive pendant une
     * semaine, ce n'est pas que tout va bien — c'est que le planificateur est
     * arrêté.
     */
    public function alert(string $titre, string $corps, bool $anomalie, ?string $journal = null): bool
    {
        return $anomalie && $this->send($titre, $corps, true, $journal);
    }

    /**
     * Expédie un rapport. Rend vrai si un courriel est parti.
     *
     * Réservé aux tâches ESPACÉES — hebdomadaires, mensuelles — pour lesquelles
     * l'accusé de bonne santé vaut la peine d'être reçu : il atteste que la
     * mécanique tourne.
     *
     * @param  bool  $anomalie  vrai si le rapport signale quelque chose à traiter
     */
    public function send(string $titre, string $corps, bool $anomalie, ?string $journal = null): bool
    {
        // AVANT l'envoi, et quel que soit son sort : un destinataire non réglé
        // ou une passerelle en panne sont précisément les moments où la trace
        // locale est la seule qui reste.
        if ($journal !== null) {
            $this->journalLocal->append($journal, $titre, $corps);
        }

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
