<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lecture de la piste d'audit (ST-0902).
 *
 * ELLE NE PROPOSE AUCUNE ÉCRITURE, et pas seulement parce que les déclencheurs
 * l'interdisent : offrir un bouton qui échouerait toujours enseignerait qu'une
 * modification est concevable. Ici, elle ne l'est pas.
 *
 * LE CONTENU EST DÉJÀ MINIMISÉ À L'ÉCRITURE. Aucune entrée ne contient de
 * numéro, d'adresse ni de nom : la chaîne est inaltérable et survivrait à tout
 * exercice du droit à l'effacement (Loi 2013-450). Cet écran n'a donc rien à
 * masquer — ce qui n'y est pas n'y a jamais été.
 *
 * L'EXPORT EST DIFFUSÉ EN FLUX, par lots. Un journal d'exploitation atteint
 * vite le million de lignes ; le charger en mémoire pour produire un fichier
 * épuiserait le processus sur un mutualisé, et l'export échouerait précisément
 * le jour où l'on en aurait besoin.
 */
final class AuditTrailController extends Controller
{
    private const PAR_PAGE = 50;

    /** Lots de lecture pour l'export : borne la mémoire, pas le résultat. */
    private const LOT_EXPORT = 500;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['sometimes', 'nullable', 'string', 'max:60'],
            'entity_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'actor_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = $this->filtrer($request)
            ->orderByDesc('id')
            ->paginate(self::PAR_PAGE, ['*'], 'page', (int) $request->integer('page', 1));

        return response()->json([
            'entries' => $page->getCollection()->map(fn (AuditLog $e): array => $this->presenter($e))->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'notice' => 'Journal append-only, protégé par des déclencheurs de base : aucune modification '.
                'n\'est possible, quel que soit le chemin emprunté.',
        ]);
    }

    /** Export CSV, diffusé en flux. */
    /**
     * Neutralise une cellule susceptible d'être lue comme une formule.
     *
     * Un tableur (Excel, LibreOffice, Sheets) exécute le contenu d'une cellule
     * qui commence par `=`, `+`, `-`, `@`, une tabulation ou un retour chariot.
     * Aujourd'hui les colonnes exportées sont des valeurs système ou du JSON
     * (préfixé par `{`), donc hors d'atteinte ; mais un motif saisi par un
     * agent transite par le payload, et toute colonne texte ajoutée demain
     * rouvrirait le vecteur. On préfixe d'une apostrophe : le tableur affiche
     * la valeur telle quelle au lieu de l'évaluer. Neutralisé à l'export, pas
     * en base : la donnée reste fidèle là où elle est lue par la plateforme.
     */
    private function neutraliserFormule(int|string|null $valeur): string
    {
        $texte = (string) $valeur;

        if ($texte !== '' && in_array($texte[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$texte;
        }

        return $texte;
    }

    public function export(Request $request): StreamedResponse
    {
        $requete = $this->filtrer($request);
        $nom = 'preuve-audit-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($requete): void {
            $sortie = fopen('php://output', 'w');

            if ($sortie === false) {
                return;
            }

            // BOM UTF-8 : sans lui, Excel affiche « Volé déclaré » en
            // « VolÃ© dÃ©clarÃ© », et l'export devient inexploitable pour qui
            // le reçoit.
            fwrite($sortie, "\xEF\xBB\xBF");

            fputcsv($sortie, ['id', 'horodatage', 'acteur_type', 'acteur_id', 'action', 'entite', 'entite_id', 'charge_utile', 'chain_hash'], ',', '"', '\\');

            /** @param Collection<int, AuditLog> $entrees */
            $requete->orderBy('id')->chunk(self::LOT_EXPORT, function (Collection $entrees) use ($sortie): void {
                foreach ($entrees as $entree) {
                    fputcsv($sortie, array_map($this->neutraliserFormule(...), [
                        $entree->id,
                        $entree->created_at->toIso8601String(),
                        $entree->actor_type->value,
                        $entree->actor_id,
                        $entree->action,
                        $entree->entity_type,
                        $entree->entity_id,
                        json_encode($entree->payload, JSON_UNESCAPED_UNICODE) ?: 'null',
                        // L'empreinte de chaînage accompagne chaque ligne :
                        // sans elle, l'export ne serait qu'un tableau, et
                        // personne ne pourrait vérifier qu'il correspond au
                        // journal dont il est tiré.
                        $entree->chain_hash,
                    ]), ',', '"', '\\');
                }
            });

            fclose($sortie);
        }, $nom, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return Builder<AuditLog> */
    private function filtrer(Request $request): Builder
    {
        $requete = AuditLog::query();

        foreach (['action' => 'action', 'entity_type' => 'entity_type'] as $parametre => $colonne) {
            $valeur = $request->string($parametre)->toString();

            if ($valeur !== '') {
                $requete->where($colonne, 'like', $valeur.'%');
            }
        }

        if ($request->filled('actor_id')) {
            $requete->where('actor_id', (int) $request->integer('actor_id'));
        }

        if ($request->filled('from')) {
            $requete->where('created_at', '>=', $request->date('from')?->startOfDay());
        }

        if ($request->filled('to')) {
            $requete->where('created_at', '<=', $request->date('to')?->endOfDay());
        }

        return $requete;
    }

    /** @return array<string, mixed> */
    private function presenter(AuditLog $entree): array
    {
        return [
            'id' => $entree->id,
            'at' => $entree->created_at->toIso8601String(),
            'actor_type' => $entree->actor_type->value,
            'actor_id' => $entree->actor_id,
            'action' => $entree->action,
            'entity_type' => $entree->entity_type,
            'entity_id' => $entree->entity_id,
            'payload' => $entree->payload,
            // Rendue au lecteur : c'est elle qui permet de rapprocher une ligne
            // d'un ancrage publié à l'extérieur.
            'chain_hash' => $entree->chain_hash,
        ];
    }
}
