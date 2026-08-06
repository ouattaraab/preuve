<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Claim;
use App\Models\KycSubmission;
use App\Models\Lookup;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\OpsReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Le point hebdomadaire sur la plateforme (ST-0904).
 *
 * POURQUOI UN RÉSUMÉ EN PLUS DES ALERTES. Une alerte dit qu'une chose a cassé ;
 * elle ne dit jamais ce qui se dégrade lentement — une file de modération qui
 * s'allonge de trois dossiers par semaine, un ancrage qui n'a plus tourné
 * depuis dix jours, des travaux en échec qui s'accumulent sans que rien ne les
 * déclenche. Ces états-là ne produisent aucun événement : ils s'installent.
 *
 * IL DIT L'ANOMALIE DANS SON OBJET. C'est souvent la seule partie lue, et
 * parfois la seule visible sur un téléphone. Un rapport hebdomadaire qui
 * ressemble chaque semaine au précédent finit filtré, et le jour où il compte,
 * personne ne l'ouvre.
 *
 * IL NE NOMME PERSONNE. Ce sont des décomptes : combien de dossiers attendent,
 * depuis combien de jours. Le nom du plus ancien déposant n'aiderait pas à
 * décider, et ferait sortir une identité de la plateforme par courriel.
 */
final class WeeklyDigest extends Command
{
    protected $signature = 'preuve:weekly-digest';

    protected $description = 'Résume la semaine et signale ce qui se dégrade lentement';

    /** Au-delà, une file d'attente cesse d'être une file et devient un problème. */
    private const ANCIENNETE_ALERTE_JOURS = 3;

    /** L'ancrage est quotidien : deux jours sans, c'est qu'il ne tourne plus. */
    private const ANCRAGE_ALERTE_JOURS = 2;

    public function __construct(private readonly OpsReporter $rapporteur)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $depuis = now()->subWeek();
        $lignes = [];
        $anomalies = [];

        $lignes[] = 'Semaine du '.$depuis->translatedFormat('j F').' au '.now()->translatedFormat('j F Y');
        $lignes[] = '';

        $lignes[] = '— ACTIVITÉ —';
        $lignes[] = sprintf('  %d bien(s) enregistré(s), %d au total',
            Asset::where('created_at', '>=', $depuis)->count(), Asset::count());
        $lignes[] = sprintf('  %d consultation(s), %d compte(s) créé(s)',
            Lookup::where('created_at', '>=', $depuis)->count(),
            User::where('created_at', '>=', $depuis)->count());
        $lignes[] = '';

        $lignes[] = '— CE QUI ATTEND QUELQU\'UN —';

        foreach ($this->filesDAttente() as $nom => $file) {
            $lignes[] = sprintf('  %-28s %d en attente%s', $nom, $file['total'], $file['detail']);

            if ($file['anomalie'] !== null) {
                $anomalies[] = $file['anomalie'];
            }
        }

        $lignes[] = '';
        $lignes[] = '— MÉCANIQUE —';

        foreach ($this->mecanique() as $ligne => $etat) {
            $lignes[] = sprintf('  %-28s %s', $ligne, $etat['texte']);

            if ($etat['anomalie'] !== null) {
                $anomalies[] = $etat['anomalie'];
            }
        }

        if ($anomalies !== []) {
            $lignes[] = '';
            $lignes[] = '— À TRAITER —';

            foreach ($anomalies as $anomalie) {
                $lignes[] = '  • '.$anomalie;
            }
        }

        $corps = implode("\n", $lignes);
        $this->line($corps);

        $this->rapporteur->send(
            'Point hebdomadaire',
            $corps,
            anomalie: $anomalies !== [],
            journal: 'weekly-digest.log',
        );

        return self::SUCCESS;
    }

    /**
     * Les files où quelqu'un attend une décision humaine.
     *
     * L'ANCIENNETÉ DU PLUS VIEUX, PAS SEULEMENT LE NOMBRE. Douze dossiers
     * déposés ce matin ne posent aucun problème ; un seul qui attend depuis
     * trois semaines en est un, et le décompte seul ne le distingue pas.
     *
     * @return array<string, array{total: int, detail: string, anomalie: string|null}>
     */
    private function filesDAttente(): array
    {
        $files = [];

        foreach ([
            'Identités à vérifier' => KycSubmission::where('status', 'pending'),
            'Réclamations ouvertes' => Claim::whereNotIn('status', ['resolved', 'rejected', 'withdrawn']),
        ] as $nom => $requete) {
            try {
                $total = (clone $requete)->count();
                $plusAncien = (clone $requete)->orderBy('created_at')->value('created_at');
            } catch (Throwable) {
                // Une table absente ou renommée ne doit pas emporter le
                // rapport entier : les autres lignes ont encore de la valeur.
                $files[$nom] = ['total' => 0, 'detail' => ' (non mesurable)', 'anomalie' => null];

                continue;
            }

            $jours = $this->joursDepuis($plusAncien);

            $files[$nom] = [
                'total' => $total,
                'detail' => $total === 0 ? '' : sprintf(', le plus ancien depuis %d jour(s)', $jours),
                'anomalie' => $total > 0 && $jours >= self::ANCIENNETE_ALERTE_JOURS
                    ? sprintf('%s : un dossier attend depuis %d jours.', $nom, $jours)
                    : null,
            ];
        }

        return $files;
    }

    /**
     * Les sauvegardes existent-elles ailleurs que sur cette machine ?
     *
     * @return array{texte: string, anomalie: string|null}
     */
    private function sauvegardes(): array
    {
        $absents = [];

        foreach ([
            'base' => config('preuve.backup.disk'),
            'pièces' => config('preuve.documents.backup_disk'),
        ] as $quoi => $disque) {
            if (! is_string($disque) || $disque === '' || $disque === 'local') {
                $absents[] = $quoi;
            }
        }

        if ($absents === []) {
            return ['texte' => 'configurées', 'anomalie' => null];
        }

        return [
            'texte' => 'AUCUNE ('.implode(', ', $absents).')',
            'anomalie' => sprintf(
                'Aucune copie hors serveur pour : %s. Une perte du disque emporterait '
                .'les pièces d\'identité, les preuves de réclamation et le registre. '
                .'Renseigner un stockage distant (s3, r2).',
                implode(' et ', $absents),
            ),
        ];
    }

    /**
     * Âge en jours d'un horodatage rendu par la base.
     *
     * Le type venant de la base n'est pas garanti — chaîne selon le pilote,
     * objet selon la configuration : le normaliser ici évite de le supposer à
     * trois endroits.
     */
    private function joursDepuis(mixed $quand): int
    {
        if (! is_string($quand) && ! $quand instanceof \DateTimeInterface) {
            return 0;
        }

        return (int) Carbon::parse($quand)->diffInDays(now());
    }

    /**
     * L'état de ce qui doit tourner tout seul.
     *
     * @return array<string, array{texte: string, anomalie: string|null}>
     */
    private function mecanique(): array
    {
        $etats = [];

        // L'ANCRAGE est ce qui rend la chaîne d'audit opposable à un tiers.
        // Sans lui, elle reste vérifiable — mais seulement par nous, ce qui ne
        // vaut rien devant un juge.
        try {
            $dernier = DB::table('audit_anchors')->max('created_at');
            $jours = is_string($dernier) || $dernier instanceof \DateTimeInterface
                ? $this->joursDepuis($dernier)
                : null;

            $etats['Dernier ancrage'] = [
                'texte' => $dernier === null ? 'jamais' : sprintf('il y a %d jour(s)', $jours),
                'anomalie' => $jours === null || $jours >= self::ANCRAGE_ALERTE_JOURS
                    ? 'L\'ancrage quotidien ne tourne plus : la chaîne d\'audit n\'est plus opposable à un tiers.'
                    : null,
            ];
        } catch (Throwable) {
            $etats['Dernier ancrage'] = ['texte' => 'non mesurable', 'anomalie' => null];
        }

        // LES TRAVAUX EN ÉCHEC ne se rejouent pas tout seuls. Une alerte de vol
        // qui n'est pas partie y dort sans que personne ne le sache.
        try {
            $echoues = DB::table('failed_jobs')->count();

            $etats['Travaux en échec'] = [
                'texte' => (string) $echoues,
                'anomalie' => $echoues > 0
                    ? sprintf('%d travail/travaux en échec : une alerte n\'est peut-être jamais partie.', $echoues)
                    : null,
            ];
        } catch (Throwable) {
            $etats['Travaux en échec'] = ['texte' => 'non mesurable', 'anomalie' => null];
        }

        // RELECTURE INTÉGRALE DE LA CHAÎNE. C'est coûteux — elle est parcourue
        // ligne à ligne — et c'est précisément pour cela que le contrôle est
        // hebdomadaire et non horaire. Une vérification qu'on n'ose pas lancer
        // parce qu'elle coûte cher ne se lance jamais.
        try {
            $verdict = app(AuditChain::class)->verify();
            $intacte = $verdict['valid'];

            $etats['Chaîne d\'audit'] = [
                'texte' => $intacte
                    ? 'vérifiée, intacte'
                    : sprintf('ROMPUE à la ligne %s', $verdict['broken_at'] ?? '?'),
                'anomalie' => $intacte
                    ? null
                    : 'La chaîne d\'audit ne se vérifie plus. À traiter en priorité absolue : '
                        .'c\'est la garantie que « qui a fait quoi » n\'a pas été réécrit.',
            ];
        } catch (Throwable $e) {
            $etats['Chaîne d\'audit'] = [
                'texte' => 'non vérifiable',
                'anomalie' => 'La chaîne d\'audit n\'a pas pu être vérifiée ('.$e::class.').',
            ];
        }

        // LES SAUVEGARDES, ET CE QU'ELLES VALENT VRAIMENT.
        //
        // Une copie posée à côté de l'original protège d'une fausse manœuvre et
        // de rien d'autre. Le dire chaque semaine évite de le découvrir le jour
        // où le serveur est perdu — c'est-à-dire le jour où c'est irréparable.
        $etats['Sauvegardes hors serveur'] = $this->sauvegardes();

        // LA SURVEILLANCE SE SURVEILLE ELLE-MÊME. Le canal d'alerte n'est actif
        // que s'il figure dans `LOG_STACK` : un déploiement qui l'omet perd
        // TOUTES les alertes d'erreur, en silence, et personne ne s'en aperçoit
        // — c'est précisément le défaut qu'on cherchait à corriger.
        $pile = config('logging.channels.stack.channels');
        $arme = is_array($pile) && in_array('alerte-ops', $pile, true);

        $etats['Alertes par courriel'] = [
            'texte' => $arme ? 'armées' : 'ÉTEINTES',
            'anomalie' => $arme
                ? null
                : 'Le canal d\'alerte est absent de LOG_STACK : aucune erreur ne sera signalée. '
                    .'Ce rapport hebdomadaire est alors la seule chose qui vous parvienne.',
        ];

        return $etats;
    }
}
