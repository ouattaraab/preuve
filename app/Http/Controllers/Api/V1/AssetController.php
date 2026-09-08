<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\LifeStatus;
use App\Exceptions\DoublonActifException;
use App\Exceptions\QuotaEpuiseException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OwnedAssetResource;
use App\Http\Resources\PublicAssetResource;
use App\Models\Asset;
use App\Models\User;
use App\Services\AssetRegistrationService;
use App\Services\AssetScanService;
use App\Services\CategoryRegistry;
use App\Services\QuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Enregistrement express d'un bien (ST-0201). Toute écriture exige un compte
 * authentifié (règle métier absolue n° 2) ; la consultation, elle, n'en exige
 * jamais.
 */
final class AssetController extends Controller
{
    public function __construct(
        private readonly AssetRegistrationService $registration,
        private readonly CategoryRegistry $categories,
        private readonly QuotaService $quotas,
        private readonly AssetScanService $scans,
    ) {}

    /** Assez pour travailler, assez peu pour ne pas devenir un export. */
    private const PAR_PAGE = 25;

    /**
     * Inventaire du porteur du jeton — et de lui seul.
     *
     * SANS CETTE ROUTE, AUCUNE ACTION N'EST ATTEIGNABLE depuis un client :
     * déclarer un vol, céder ou réclamer passent tous par `/assets/{id}/…`, et
     * rien ne permettait à un particulier de connaître l'identifiant interne de
     * ses propres biens. La flotte avait son tableau de bord ; le particulier
     * n'avait rien.
     *
     * LE FILTRE EST LE PORTEUR DU JETON, jamais un paramètre de requête.
     * Accepter un `user_id` ferait de cette route l'inventaire de n'importe qui
     * — c'est-à-dire exactement ce que la règle métier absolue n° 4 interdit,
     * par le côté où l'on ne regarde pas.
     *
     * LES ARCHIVÉS SONT EXCLUS (`active_flag`). Un bien cédé appartient à
     * quelqu'un d'autre : le laisser dans la liste de l'ancien détenteur
     * lui laisserait croire qu'il peut encore le déclarer volé, et le serveur
     * le refuserait sans qu'il comprenne pourquoi.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        $proprietaire = $request->user();

        if (! $proprietaire instanceof User) {
            abort(401);
        }

        $page = Asset::query()
            ->where('owner_user_id', $proprietaire->id)
            ->whereNotNull('active_flag')
            // CE QUI ALARME PASSE DEVANT. Un bien volé enfoui sous onze autres
            // dans une liste triée par date ne se voit pas, et c'est justement
            // celui sur lequel il reste quelque chose à faire.
            ->orderByRaw('CASE WHEN life_status IN (?, ?) THEN 0 ELSE 1 END', [
                LifeStatus::Stolen->value,
                LifeStatus::Disputed->value,
            ])
            ->orderByDesc('registered_at')
            ->paginate(self::PAR_PAGE, ['*'], 'page', (int) $request->integer('page', 1));

        // Consultations des 30 derniers jours, en UNE requête groupée : un
        // compte par ligne ferait vingt-cinq requêtes par page, sur la table qui
        // grossit le plus vite de la base.
        //
        // C'EST UN NOMBRE, JAMAIS UNE LISTE. Le détenteur apprend que son bien
        // est regardé — ce qui est le signal utile, et parfois le seul indice
        // d'un vol qui se prépare — sans rien apprendre de qui regarde.
        // L'anonymat est symétrique : le consultant y a autant droit que lui
        // (règle métier absolue n° 4).
        $identifiants = [];

        foreach ($page->getCollection() as $bien) {
            $identifiants[] = $bien->identifier_normalized;
        }

        $consultations = $this->consultationsSur30Jours($identifiants);

        $biens = [];

        foreach ($page->getCollection() as $bien) {
            $biens[] = (new OwnedAssetResource($bien))->toArray($request) + [
                'lookups_30d' => $consultations[$bien->identifier_normalized] ?? 0,
            ];
        }

        return response()->json([
            'assets' => $biens,
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            // Le quota accompagne l'inventaire : l'utilisateur voit ce qu'il
            // lui reste AVANT d'ouvrir un formulaire, plutôt que de l'apprendre
            // au refus après quatre-vingt-dix secondes de saisie.
            'quota' => $this->quotas->forUser($proprietaire),
        ]);
    }

    /**
     * @param  list<string>  $identifiants
     * @return array<string, int>
     */
    private function consultationsSur30Jours(array $identifiants): array
    {
        if ($identifiants === []) {
            return [];
        }

        $lignes = DB::table('lookups')
            ->select('identifier_normalized', DB::raw('COUNT(*) as total'))
            ->whereIn('identifier_normalized', $identifiants)
            ->where('created_at', '>=', now()->subDays(30)->format('Y-m-d H:i:s'))
            ->groupBy('identifier_normalized')
            ->get();

        $compte = [];

        foreach ($lignes as $ligne) {
            $cle = $ligne->identifier_normalized ?? null;
            $total = $ligne->total ?? 0;

            if (is_string($cle) && is_numeric($total)) {
                $compte[$cle] = (int) $total;
            }
        }

        return $compte;
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['required', 'string', 'max:40'],
            'attributes' => ['required', 'array'],
            // Chronomètre côté client pour la télémétrie CT-02 (< 90 s au
            // médian). Facultatif : un client hors ligne qui rejoue sa file
            // d'envois différés ne mesurerait rien de comparable (CT-05).
            'client_elapsed_ms' => ['sometimes', 'integer', 'min:0'],
            // Scan ayant pré-rempli le formulaire (ST-0202). Facultatif : la
            // saisie manuelle reste le chemin nominal.
            'scan_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $categorie = $request->string('category')->toString();

        // Les champs attendus dépendent de la catégorie, servie par
        // configuration distante : la validation est donc dérivée du
        // catalogue, jamais codée en dur (décision D6).
        try {
            $request->validate($this->categories->validationRulesFor($categorie));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['category' => $e->getMessage()]);
        }

        $proprietaire = $request->user();

        if (! $proprietaire instanceof User) {
            abort(401);
        }

        // Seuls les champs déclarés par la catégorie sont retenus : la colonne
        // `attributes` est un JSON libre en base, et tout ce qui y entrerait
        // sans filtre y resterait — charge arbitraire stockée aux frais de la
        // plateforme, et données personnelles jamais déclarées.
        $attributs = [];

        foreach ($this->categories->fieldKeys($categorie) as $cle) {
            $valeur = $request->input('attributes.'.$cle);

            if ($valeur !== null) {
                $attributs[$cle] = $valeur;
            }
        }

        $chrono = $request->has('client_elapsed_ms') ? (int) $request->integer('client_elapsed_ms') : null;

        // Constaté AVANT l'enregistrement, et donc quelle qu'en soit l'issue :
        // un doublon refusé signifie que la lecture était bonne, et ne le
        // compter qu'en cas de succès fausserait la mesure dans le sens qui
        // arrange (ST-0202, « taux de pré-remplissage mesuré »).
        if ($request->has('scan_id')) {
            $identifiantSoumis = $attributs[$this->categories->canonicalFieldKey($categorie)] ?? null;

            if (is_string($identifiantSoumis)) {
                $this->scans->recordUse((int) $request->integer('scan_id'), $proprietaire, $identifiantSoumis);
            }
        }

        try {
            $bien = $this->registration->register(
                // Le propriétaire est le porteur du jeton, jamais une valeur
                // soumise : accepter un owner_user_id de la requête
                // permettrait d'enregistrer un bien au nom d'autrui.
                $proprietaire,
                $categorie,
                $attributs,
                clientElapsedMs: $chrono,
            );
        } catch (DoublonActifException $e) {
            // Jamais de création : la seule issue est la fiche existante et le
            // parcours de réclamation (règle métier absolue n° 3).
            return response()->json([
                'message' => $e->getMessage(),
                'asset' => new PublicAssetResource($e->existant),
                // La route réellement servie, et la référence à lui passer.
                // Elle annonçait jusqu'ici un `GET` qui n'a jamais existé : un
                // client qui l'aurait suivi aurait mené la victime vers un 404,
                // au moment précis où on lui apprend que son bien est au nom de
                // quelqu'un d'autre.
                'claim_url' => '/api/v1/claims',
                'claim_public_ref' => $e->existant->public_ref,
            ], 409);
        } catch (QuotaEpuiseException $e) {
            // 402 et non 422 : il n'y a rien à corriger dans la demande, elle
            // est valide et le restera. Le client doit router vers le paiement,
            // pas vers le formulaire.
            return response()->json([
                'message' => $e->getMessage(),
                'quota' => $e->quota,
            ], 402);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['attributes' => $e->getMessage()]);
        }

        // LA VUE DU DÉTENTEUR, PAS LA VUE PUBLIQUE : cette réponse part à celui
        // qui vient d'enregistrer le bien. Sans son identifiant interne, il ne
        // pourrait rattacher NI photo, NI justificatif à ce qu'il vient de
        // créer — il faudrait relire tout l'inventaire pour retrouver un bien
        // dont on sort à l'instant. Le 409 juste au-dessus, lui, part à
        // quelqu'un qui n'est pas le détenteur : il garde la vue publique.
        //
        // Le quota accompagne la réponse : l'utilisateur voit ce qu'il lui
        // reste avant d'être arrêté, plutôt que de le découvrir au refus.
        return response()->json([
            'asset' => new OwnedAssetResource($bien),
            'quota' => $this->quotas->forUser($proprietaire),
        ], 201);
    }
}
