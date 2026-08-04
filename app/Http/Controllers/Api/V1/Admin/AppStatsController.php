<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AppRelease;
use App\Services\AuditChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques d'usage de l'application (ST-0902).
 *
 * CE QUI EST MESURÉ EST CE QUE LA PLATEFORME SAIT RÉELLEMENT. La maquette
 * annonçait des « téléchargements » : ce chiffre appartient aux magasins
 * d'applications et n'est accessible qu'en branchant leurs API. Le fabriquer
 * à partir des comptes créés donnerait un nombre plausible et faux — un
 * indicateur faux est pire qu'un indicateur absent, parce qu'on décide dessus.
 * Ce que la plateforme connaît, c'est le PARC D'APPAREILS qui se sont annoncés
 * pour recevoir des notifications : une installation qui vit, et non un
 * téléchargement qui a peut-être été désinstallé le lendemain.
 *
 * LA CARTE DE CHALEUR EST LE SEUL INDICATEUR QUI CHANGE UNE ORGANISATION.
 * Savoir que les consultations culminent le samedi entre 10 h et 13 h — jour
 * de marché — dit quand tenir la permanence d'instruction et quand ne pas
 * déployer. C'est la seule statistique de cet écran dont on fait quelque chose
 * le lendemain.
 *
 * ELLE NE COMPTE QUE DES ÉVÉNEMENTS, JAMAIS DES PERSONNES. Les consultations
 * sont majoritairement anonymes et le resteront : aucune ligne de cet écran ne
 * remonte à un consultant.
 */
final class AppStatsController extends Controller
{
    public function __construct(
        private readonly AppRelease $release,
        private readonly AuditChain $auditChain,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $jours = max(7, min(90, $request->has('days') ? (int) $request->integer('days') : 30));
        $depuis = now()->subDays($jours);

        return response()->json([
            'window' => ['days' => $jours, 'from' => $depuis->toIso8601String()],
            'devices' => $this->parc(),
            'heatmap' => $this->carteDeChaleur($depuis->format('Y-m-d H:i:s')),
            'sources' => $this->sources($depuis->format('Y-m-d H:i:s')),
            'release' => $this->versions(),
            'notice' => 'Le nombre de téléchargements appartient aux magasins d\'applications et '.
                'n\'est pas mesurable ici. Le parc ci-dessous compte les appareils qui se sont '.
                'annoncés à la plateforme — des installations vivantes, pas des téléchargements.',
        ]);
    }

    /**
     * Parc d'appareils, par système, et part encore active.
     *
     * @return array<string, mixed>
     */
    private function parc(): array
    {
        $limite = now()->subDays(30)->format('Y-m-d H:i:s');

        $lignes = DB::table('device_tokens')
            ->selectRaw('platform, COUNT(*) as total, SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) as actifs', [$limite])
            ->groupBy('platform')
            ->get();

        $parPlateforme = [];
        $total = 0;
        $actifs = 0;

        foreach ($lignes as $ligne) {
            $parPlateforme[] = [
                'platform' => is_string($ligne->platform) ? $ligne->platform : 'inconnue',
                'total' => $this->entier($ligne->total),
                'active_30d' => $this->entier($ligne->actifs),
            ];

            $total += $this->entier($ligne->total);
            $actifs += $this->entier($ligne->actifs);
        }

        return [
            'total' => $total,
            // Un parc qui grossit pendant que la part active fond est une
            // application qu'on installe et qu'on abandonne : les deux chiffres
            // ne disent la vérité qu'ensemble.
            'active_30d' => $actifs,
            'by_platform' => $parPlateforme,
        ];
    }

    /**
     * Consultations par jour de la semaine et par heure.
     *
     * @return array<string, mixed>
     */
    private function carteDeChaleur(string $depuis): array
    {
        // Agrégé par la base : une carte de chaleur sur trente jours porte sur
        // des centaines de milliers de lignes, et les rapatrier pour les
        // compter en PHP épuiserait la mémoire d'un mutualisé.
        $lignes = DB::table('lookups')
            ->where('created_at', '>=', $depuis)
            ->selectRaw('DAYOFWEEK(created_at) as jour, HOUR(created_at) as heure, COUNT(*) as total')
            ->groupBy('jour', 'heure')
            ->get();

        // 7 jours × 24 heures, à zéro : une grille trouée se lirait comme une
        // absence de mesure là où il n'y a qu'une absence de consultation.
        $grille = array_fill(0, 7, array_fill(0, 24, 0));
        $maximum = 0;

        foreach ($lignes as $ligne) {
            // DAYOFWEEK rend 1 pour dimanche ; la semaine se lit du lundi au
            // dimanche en Côte d'Ivoire.
            $jour = ($this->entier($ligne->jour) + 5) % 7;
            $heure = $this->entier($ligne->heure);
            $total = $this->entier($ligne->total);

            $grille[$jour][$heure] = $total;
            $maximum = max($maximum, $total);
        }

        return [
            'days' => ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'],
            'grid' => $grille,
            // Rendu au client : sans lui, chaque cellule devrait être comparée
            // à toutes les autres pour choisir son intensité.
            'max' => $maximum,
            'timezone' => config('app.timezone'),
        ];
    }

    /**
     * Répartition application / web des consultations.
     *
     * @return list<array<string, mixed>>
     */
    private function sources(string $depuis): array
    {
        $lignes = DB::table('lookups')
            ->where('created_at', '>=', $depuis)
            ->selectRaw('source, COUNT(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        $sources = [];

        foreach (['app' => 'Application mobile', 'web' => 'Site public'] as $cle => $libelle) {
            $sources[] = [
                'key' => $cle,
                'label' => $libelle,
                'count' => $this->entier($lignes[$cle] ?? 0),
            ];
        }

        return $sources;
    }

    /** Un agrégat SQL revient typé `mixed` : une seule porte pour le convertir. */
    private function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    /** @return array<string, mixed> */
    private function versions(): array
    {
        $minimum = $this->release->minimum();

        return [
            'minimum_version' => $minimum,
            'latest_version' => $this->release->latest(),
            'enforced' => $minimum !== null,
            'note' => $minimum === null
                ? 'Aucune version minimale n\'est exigée : toutes les applications installées peuvent écrire.'
                : sprintf(
                    'Les applications antérieures à la version %s ne peuvent plus enregistrer, transférer '.
                    'ni réclamer. La consultation d\'un identifiant leur reste ouverte.',
                    $minimum,
                ),
        ];
    }

    /**
     * Règle la version minimale exigée — le « forçage de mise à jour ».
     */
    public function setRelease(Request $request): JsonResponse
    {
        $request->validate([
            // `nullable` et non `required` : rendre le champ vide lève
            // l'exigence, et c'est la manœuvre de repli si un forçage se révèle
            // trop large une fois posé.
            'minimum_version' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d{1,4}(\.\d{1,4}){0,2}$/'],
            'latest_version' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d{1,4}(\.\d{1,4}){0,2}$/'],
        ]);

        $administrateur = $request->user();
        $administrateurId = $administrateur instanceof User ? $administrateur->id : null;

        $ancien = $this->release->minimum();
        $nouveau = $request->has('minimum_version') ? $request->string('minimum_version')->toString() : $ancien;
        $nouveau = $nouveau === '' ? null : $nouveau;

        // Journalisé avec l'ancienne ET la nouvelle exigence : un forçage met
        // hors service une part du parc installé, et « qui a décidé quoi, quand »
        // est la première question posée le jour où le support est submergé.
        $this->auditChain->append(
            ActorType::Agent,
            $administrateurId,
            'admin.app_release_changed',
            'app_release',
            0,
            ['from' => $ancien, 'to' => $nouveau],
        );

        $this->release->setMinimum($nouveau, $administrateurId);

        if ($request->has('latest_version')) {
            $derniere = $request->string('latest_version')->toString();
            $this->release->setLatest($derniere === '' ? null : $derniere, $administrateurId);
        }

        return response()->json([
            'message' => $nouveau === null
                ? 'Exigence de version levée : toutes les applications installées peuvent de nouveau écrire.'
                : sprintf('Version minimale portée à %s.', $nouveau),
            'release' => $this->versions(),
        ]);
    }
}
