<?php

declare(strict_types=1);

namespace App\Logging;

use App\Services\OpsReporter;
use Illuminate\Support\Facades\Cache;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Prévient l'exploitant quand quelque chose casse (ST-0904).
 *
 * POURQUOI. La sauvegarde des pièces échouait chaque nuit depuis le 4 août :
 * la ligne partait dans un fichier de journal, sur un mutualisé à quota, que
 * personne ne lit. Une panne qu'on ne découvre qu'en la cherchant n'est pas
 * surveillée — elle est ignorée avec méthode.
 *
 * TROIS PRÉCAUTIONS, ET AUCUNE N'EST FACULTATIVE :
 *
 * 1. LE DÉBIT EST BORNÉ. Une erreur en boucle — une base injoignable, un
 *    travailleur qui redémarre — produirait des milliers de messages, ferait
 *    classer l'expéditeur en indésirable, et l'alerte suivante, la vraie, se
 *    perdrait. Un même défaut n'est signalé qu'une fois par heure, et le total
 *    est plafonné.
 *
 * 2. LE CONTEXTE N'EST PAS RECOPIÉ. Il porte des charges utiles — une adresse,
 *    le corps d'une requête, parfois une image. Seules les CLÉS partent :
 *    elles suffisent à savoir où regarder, et le journal complet reste sur le
 *    serveur, où il est déjà protégé.
 *
 * 3. ELLE NE PEUT PAS FAIRE ÉCHOUER CE QU'ELLE OBSERVE. Toute erreur d'envoi
 *    est avalée : une alerte qui casserait la requête qu'elle surveille serait
 *    une panne de plus, causée par l'outil censé les révéler.
 */
final class OpsAlertHandler extends AbstractProcessingHandler
{
    /** Un même défaut ne se signale qu'une fois par heure. */
    private const REPEAT_MINUTES = 60;

    /** Au-delà, on se tait : le journal reste, la boîte survit. */
    private const MAX_PER_HOUR = 12;

    public function __construct(Level|int|string $level = Level::Error, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $rapporteur = app(OpsReporter::class);

            if ($rapporteur->recipient() === null) {
                return;
            }

            if (! $this->passeLeFiltre($record)) {
                return;
            }

            $rapporteur->send(
                'Anomalie technique',
                $this->corps($record),
                anomalie: true,
            );
        } catch (Throwable) {
            // Volontairement muet. Voir la précaution n° 3 : une alerte qui
            // échoue ne doit pas emporter la requête qu'elle observait, et
            // journaliser ici rappellerait ce même gestionnaire.
        }
    }

    /**
     * Vrai si ce défaut mérite un message maintenant.
     *
     * L'empreinte porte sur le NIVEAU et le MESSAGE, jamais sur le contexte :
     * la même panne répétée avec un identifiant différent à chaque fois doit
     * compter pour une, sinon le plafond ne protège de rien.
     */
    private function passeLeFiltre(LogRecord $record): bool
    {
        $empreinte = 'preuve.alerte.'.md5($record->level->getName().'|'.$record->message);

        if (Cache::get($empreinte) !== null) {
            return false;
        }

        $compte = Cache::get('preuve.alerte.compteur');
        $total = is_numeric($compte) ? (int) $compte : 0;

        if ($total >= self::MAX_PER_HOUR) {
            return false;
        }

        Cache::put($empreinte, true, now()->addMinutes(self::REPEAT_MINUTES));
        Cache::put('preuve.alerte.compteur', $total + 1, now()->addHour());

        return true;
    }

    private function corps(LogRecord $record): string
    {
        $lignes = [
            $record->level->getName().' — '.$record->message,
            '',
            'Survenu le '.$record->datetime->format('d/m/Y à H:i:s'),
            'Canal : '.$record->channel,
        ];

        if ($record->context !== []) {
            // LES CLÉS SEULEMENT. Les valeurs portent des charges utiles :
            // adresses, corps de requêtes, parfois une pièce d'identité.
            $lignes[] = 'Contexte (clés) : '.implode(', ', array_keys($record->context));
        }

        $lignes[] = '';
        $lignes[] = 'Le détail complet est dans storage/logs, sur le serveur.';
        $lignes[] = 'Les messages identiques sont regroupés : un même défaut ne '
            .'se signale qu\'une fois par heure.';

        return implode("\n", $lignes);
    }
}
