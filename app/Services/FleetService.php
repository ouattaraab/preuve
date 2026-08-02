<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LifeStatus;
use App\Enums\TriggerType;
use App\Exceptions\DoublonActifException;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Lookup;
use App\Models\Notification;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Offre flotte B2B (ST-0701 à ST-0703).
 *
 * L'IMPORT DOIT ABOUTIR PARTIELLEMENT. Un loueur qui importe 80 véhicules et
 * voit tout échouer parce que la ligne 43 porte une plaque mal saisie
 * abandonnera — et son parc restera dehors. Chaque ligne est donc traitée pour
 * elle-même : les valides entrent, les autres sont rendues avec leur motif, et
 * un second import ne recrée pas ce qui existe déjà.
 *
 * L'IMPORT EST BORNÉ, et ce n'est pas une limite de confort. Chaque
 * enregistrement prend le verrou nommé de la chaîne d'audit, dont le plafond
 * mesuré est d'une quinzaine d'actions simultanées : un fichier de mille lignes
 * traité d'un bloc rejetterait les actions de tous les autres utilisateurs
 * pendant sa durée. Le loueur découpe donc son fichier — ou, quand Horizon sera
 * en place, l'import passera en file.
 *
 * Le format retenu est le CSV plutôt que le XLSX natif : c'est ce que tout
 * tableur exporte, et cela évite d'introduire une dépendance de lecture de
 * classeurs pour un besoin que trois colonnes suffisent à couvrir.
 */
final class FleetService
{
    /** Lignes traitées au plus par import (voir la docstring de classe). */
    public const MAX_ROWS = 200;

    /** Biens marqués au plus par opération de masse, pour la même raison. */
    public const MAX_BULK = 200;

    public function __construct(
        private readonly AssetRegistrationService $registration,
        private readonly StatusTransitionService $transitions,
    ) {}

    /**
     * Importe un fichier CSV de véhicules.
     *
     * @return array{imported: int, skipped: int, failed: int, errors: list<array{line: int, identifier: string, reason: string}>, truncated: bool}
     *
     * @throws DomainException
     */
    public function import(Company $societe, User $operateur, UploadedFile $fichier): array
    {
        // Suspension douce (ST-0805) : une flotte en lecture seule n'ajoute
        // plus de véhicules, mais ceux qu'elle a déjà enregistrés restent
        // protégés — couper la protection punirait les véhicules, pas le
        // débiteur.
        if (! $societe->subscription_status->allowsNewAssets()) {
            throw new DomainException($societe->subscription_status->message());
        }

        $lignes = $this->readCsv($fichier);

        if ($lignes === []) {
            throw new DomainException(
                'Le fichier ne contient aucune ligne exploitable. Attendu : une ligne d\'en-tête '.
                'category,identifier,brand_model puis une ligne par véhicule.'
            );
        }

        $tronque = count($lignes) > self::MAX_ROWS;
        $lignes = array_slice($lignes, 0, self::MAX_ROWS);

        $importes = 0;
        $ignores = 0;
        $erreurs = [];

        foreach ($lignes as $index => $ligne) {
            $numero = $index + 2; // +1 pour l'en-tête, +1 pour compter depuis 1
            $identifiant = $this->cellule($ligne['identifier'] ?? null);

            if ($identifiant === '') {
                $erreurs[] = $this->erreur($numero, '', 'Identifiant absent.');

                continue;
            }

            try {
                $this->registration->register(
                    $operateur,
                    $this->cellule($ligne['category'] ?? null) ?: 'voiture',
                    $this->attributs($ligne),
                    companyId: $societe->id,
                );

                $importes++;
            } catch (DoublonActifException $e) {
                // Déjà enregistré : un second import ne doit ni échouer ni
                // dupliquer. C'est le cas normal d'une reprise après
                // interruption.
                $ignores += $e->existant->company_id === $societe->id ? 1 : 0;

                if ($e->existant->company_id !== $societe->id) {
                    $erreurs[] = $this->erreur(
                        $numero,
                        $identifiant,
                        'Déjà enregistré par un autre détenteur. Ouvrez une réclamation si ce véhicule est à vous.',
                    );
                }
            } catch (Throwable $e) {
                $erreurs[] = $this->erreur($numero, $identifiant, $this->motifLisible($e));
            }
        }

        return [
            'imported' => $importes,
            'skipped' => $ignores,
            'failed' => count($erreurs),
            'errors' => $erreurs,
            'truncated' => $tronque,
        ];
    }

    /**
     * Marque ou démarque des véhicules « En location » (ST-0702).
     *
     * Le statut porte un avertissement public fort — « une vente est
     * frauduleuse » — et c'est tout son intérêt : il rend invendable un
     * véhicule confié à un tiers, qui est exactement le scénario de fraude que
     * les loueurs subissent.
     *
     * @param  list<int>  $assetIds
     * @return array{updated: int, failed: int, errors: list<array{asset_id: int, reason: string}>}
     */
    public function markRented(Company $societe, User $operateur, array $assetIds, bool $enLocation): array
    {
        $cible = $enLocation ? LifeStatus::Rented : LifeStatus::Active;
        $ids = array_slice(array_values(array_unique($assetIds)), 0, self::MAX_BULK);

        $biens = Asset::query()
            ->whereIn('id', $ids)
            ->where('company_id', $societe->id)
            ->whereNotNull('active_flag')
            ->get();

        $modifies = 0;
        $erreurs = [];

        foreach ($biens as $bien) {
            if ($bien->life_status === $cible) {
                // Déjà dans l'état voulu : une sélection large ne doit pas
                // échouer parce qu'elle englobe des véhicules déjà marqués.
                continue;
            }

            try {
                $this->transitions->transitionTo(
                    $bien,
                    $cible,
                    TriggerType::Owner,
                    $operateur->id,
                    $enLocation ? 'Marquage flotte : véhicule confié en location' : 'Retour de location',
                );

                $modifies++;
            } catch (Throwable $e) {
                $erreurs[] = ['asset_id' => $bien->id, 'reason' => $this->motifLisible($e)];
            }
        }

        return ['updated' => $modifies, 'failed' => count($erreurs), 'errors' => $erreurs];
    }

    /**
     * Tableau de bord d'une flotte (ST-0703).
     *
     * Les consultations sont rendues AGRÉGÉES par véhicule, comme partout
     * ailleurs : un loueur n'apprend pas plus qu'un particulier sur qui
     * consulte ses biens (règle métier absolue n° 4).
     *
     * @return array<string, mixed>
     */
    public function dashboard(Company $societe): array
    {
        $biens = Asset::query()
            ->where('company_id', $societe->id)
            ->whereNotNull('active_flag')
            ->get();

        $parStatut = [];

        foreach (LifeStatus::cases() as $statut) {
            $compte = $biens->where('life_status', $statut)->count();

            if ($compte > 0) {
                $parStatut[] = [
                    'code' => $statut->value,
                    'label' => $statut->label(),
                    'count' => $compte,
                    'warning' => $statut->isPublicWarning(),
                ];
            }
        }

        $identifiants = $biens->pluck('id');

        $consultations = Lookup::query()
            ->whereIn('found_asset_id', $identifiants)
            ->where('created_at', '>', now()->subDays(30))
            ->select('found_asset_id', DB::raw('COUNT(*) as total'))
            ->groupBy('found_asset_id')
            ->pluck('total', 'found_asset_id');

        return [
            'fleet_size' => $biens->count(),
            'by_status' => $parStatut,
            // Les véhicules qui portent un avertissement public : c'est la
            // première chose qu'un loueur doit voir en ouvrant son tableau.
            'needs_attention' => $biens
                ->filter(fn (Asset $bien): bool => $bien->life_status->isPublicWarning())
                ->map(fn (Asset $bien): array => [
                    'asset_id' => $bien->id,
                    'public_ref' => $bien->public_ref,
                    'status' => $bien->life_status->value,
                    'status_label' => $bien->life_status->label(),
                ])->values()->all(),
            'lookups_30d' => [
                'total' => $this->entier($consultations->sum()),
                'most_viewed' => $biens
                    ->map(fn (Asset $bien): array => [
                        'asset_id' => $bien->id,
                        'public_ref' => $bien->public_ref,
                        'lookups' => $this->entier($consultations[$bien->id] ?? 0),
                    ])
                    ->sortByDesc('lookups')
                    ->take(5)
                    ->values()
                    ->all(),
            ],
            'recent_alerts' => Notification::query()
                ->whereIn('asset_id', $identifiants)
                ->whereIn('type', ['duplicate_attempt', 'lookup_spike'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (Notification $alerte): array => [
                    'type' => $alerte->type->value,
                    'asset_id' => $alerte->asset_id,
                    'title' => $alerte->title,
                    'at' => $alerte->created_at->toIso8601String(),
                ])->all(),
        ];
    }

    private function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    /** Valeur d'une cellule, ramenée à une chaîne propre. */
    private function cellule(mixed $valeur): string
    {
        return is_scalar($valeur) ? trim((string) $valeur) : '';
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @return array<string, mixed>
     */
    private function attributs(array $ligne): array
    {
        $attributs = [];

        foreach ($ligne as $colonne => $valeur) {
            // `category` désigne la catégorie du bien, pas un de ses champs :
            // la laisser passer la stockerait en double dans `attributes`.
            if ($colonne === 'category') {
                continue;
            }

            $attributs[$colonne] = is_scalar($valeur) ? trim((string) $valeur) : $valeur;
        }

        // L'identifiant est recopié sous le nom du champ canonique attendu par
        // la catégorie, pour que le loueur n'ait pas à connaître nos clés
        // internes : « identifier » suffit dans son fichier.
        $identifiant = $this->cellule($ligne['identifier'] ?? null);

        foreach (['plate', 'chassis', 'imei', 'serial'] as $champ) {
            $attributs[$champ] = $identifiant;
        }

        return $attributs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readCsv(UploadedFile $fichier): array
    {
        $chemin = $fichier->getRealPath();

        if (! is_readable($chemin)) {
            return [];
        }

        $flux = fopen($chemin, 'r');

        if ($flux === false) {
            return [];
        }

        $entetes = fgetcsv($flux, 0, ',', '"', '\\');

        if (! is_array($entetes)) {
            fclose($flux);

            return [];
        }

        $colonnes = [];

        foreach ($entetes as $entete) {
            $colonnes[] = mb_strtolower($this->cellule($entete));
        }

        $lignes = [];

        while (($valeurs = fgetcsv($flux, 0, ',', '"', '\\')) !== false) {
            if ($valeurs === [null]) {
                continue;
            }

            $ligne = [];

            foreach ($colonnes as $index => $colonne) {
                $ligne[$colonne] = $valeurs[$index] ?? null;
            }

            $lignes[] = $ligne;
        }

        fclose($flux);

        return $lignes;
    }

    /**
     * @return array{line: int, identifier: string, reason: string}
     */
    private function erreur(int $ligne, string $identifiant, string $motif): array
    {
        return ['line' => $ligne, 'identifier' => $identifiant, 'reason' => $motif];
    }

    /**
     * Motif destiné au loueur, qui devra corriger son fichier : le message
     * d'origine s'il est exploitable, jamais une trace technique.
     */
    private function motifLisible(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return $message === '' ? 'Ligne refusée.' : $message;
    }
}
