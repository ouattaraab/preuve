<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\LifeStatus;
use App\Http\Controllers\Controller;
use App\Services\TelemetryService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Vue d'ensemble de la console (ST-0902).
 *
 * ELLE COMMENCE PAR CE QUI ATTEND UNE DÉCISION HUMAINE, pas par des compteurs
 * flatteurs. Un tableau de bord qui ouvre sur « 12 400 biens enregistrés »
 * apprend une chose agréable et inutile ; celui qui ouvre sur « 7 dossiers KYC
 * en attente depuis 3 jours » change la journée de qui le lit. Les volumes
 * viennent ensuite, parce qu'ils servent à autre chose : décider s'il faut
 * embaucher, pas décider quoi faire ce matin.
 *
 * AUCUN CHIFFRE NE DÉSIGNE QUELQU'UN. Ce sont des dénombrements, jamais des
 * listes nominatives : l'anonymat vaut aussi à l'intérieur (règle métier
 * absolue n° 4), et une vue d'ensemble n'a aucun besoin de savoir QUI possède
 * quoi pour dire ce qui reste à faire.
 *
 * LES PROMESSES PRODUIT Y FIGURENT AU MÊME TITRE QUE LES FILES D'ATTENTE.
 * CT-01 et CT-02 ne sont pas des indicateurs techniques rangés dans un onglet
 * d'ingénieur : ce sont les deux engagements du produit envers ses
 * utilisateurs. Leur dérive doit se voir sans être cherchée.
 */
final class OverviewController extends Controller
{
    /** Au-delà, une file d'attente n'est plus un retard mais un problème. */
    private const SEUIL_ATTENTE = 10;

    public function __construct(private readonly TelemetryService $telemetrie) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'queues' => $this->files(),
            'registry' => $this->registre(),
            'promises' => $this->promesses(),
            'notice' => 'Ces chiffres sont des dénombrements : aucun ne désigne une personne '.
                'ni un bien en particulier.',
        ]);
    }

    /**
     * Ce qui attend une décision humaine, avec l'attente la plus ancienne.
     *
     * LE NOMBRE SEUL NE SUFFIT PAS. Trois dossiers déposés ce matin et trois
     * dossiers oubliés depuis douze jours donnent le même compteur et
     * n'appellent pas la même journée. C'est l'ancienneté qui dit lequel des
     * deux on regarde.
     *
     * @return list<array<string, mixed>>
     */
    private function files(): array
    {
        return [
            $this->file(
                'kyc',
                'Vérifications d\'identité',
                DB::table('kyc_submissions')->where('status', 'pending'),
                'admin.moderation',
            ),
            $this->file(
                'documents',
                'Justificatifs à revoir',
                DB::table('asset_documents')->where('review_status', 'pending'),
                'admin.moderation',
            ),
            $this->file(
                'claims',
                'Réclamations à instruire',
                DB::table('claims')->whereIn('status', ['submitted', 'admissible', 'under_review', 'appealed']),
                'admin.moderation',
            ),
            $this->file(
                'companies',
                'Entreprises à valider',
                DB::table('companies')->where('validation_status', 'pending'),
                'admin.moderation',
            ),
        ];
    }

    /**
     * @param  Builder  $requete
     * @return array<string, mixed>
     */
    private function file(string $cle, string $libelle, $requete, string $ecran): array
    {
        $total = (clone $requete)->count();
        $plusAncien = $total === 0 ? null : (clone $requete)->min('created_at');

        return [
            'key' => $cle,
            'label' => $libelle,
            'count' => $total,
            'oldest_at' => is_string($plusAncien) ? $plusAncien : null,
            // Le seuil rend le compteur lisible d'un coup d'œil sans qu'il
            // faille se souvenir de ce qui est normal.
            'over_threshold' => $total > self::SEUIL_ATTENTE,
            'screen' => $ecran,
        ];
    }

    /**
     * État du registre : volumes et statuts sensibles.
     *
     * @return array<string, mixed>
     */
    private function registre(): array
    {
        $parStatut = DB::table('assets')
            ->whereNotNull('active_flag')
            ->selectRaw('life_status, COUNT(*) as total')
            ->groupBy('life_status')
            ->pluck('total', 'life_status');

        $statuts = [];

        foreach (LifeStatus::cases() as $statut) {
            $nombre = $parStatut[$statut->value] ?? 0;

            $statuts[] = [
                'value' => $statut->value,
                // Langage courant, jamais le code (CT-04) : « Volé déclaré »,
                // pas « V-VOL ».
                'label' => $statut->label(),
                'count' => $this->entier($nombre),
            ];
        }

        return [
            'active_assets' => $this->entier($parStatut->sum()),
            'by_status' => $statuts,
            'accounts' => DB::table('users')->count(),
            'lookups_7d' => DB::table('lookups')
                ->where('created_at', '>=', now()->subDays(7)->format('Y-m-d H:i:s'))
                ->count(),
            // Les consultations sans correspondance : une part qui grimpe
            // signale un balayage du registre, pas un afflux d'acheteurs.
            'unknown_lookups_7d' => DB::table('lookups')
                ->where('created_at', '>=', now()->subDays(7)->format('Y-m-d H:i:s'))
                ->whereNull('found_asset_id')
                ->count(),
        ];
    }

    /** Un agrégat SQL revient typé `mixed` : une seule porte pour le convertir. */
    private function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    /**
     * CT-01 et CT-02, sur sept jours glissants.
     *
     * @return array<string, mixed>
     */
    private function promesses(): array
    {
        $depuis = now()->subDays(7);

        return [
            'ct01_lookup' => $this->telemetrie->lookupLatency($depuis),
            'ct02_registration' => $this->telemetrie->registrationDuration($depuis),
        ];
    }
}
