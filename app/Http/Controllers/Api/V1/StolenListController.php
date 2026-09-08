<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\StolenListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La liste publique des biens volés (ST-0805).
 *
 * PUBLIQUE, ANONYME, SANS COMPTE, comme la consultation — et pour la même
 * raison : elle ne sert que si on la parcourt. Un garagiste à qui l'on apporte
 * une moto, un policier à un contrôle, un acheteur au marché : aucun d'eux
 * n'ouvrira un compte pour vérifier une intuition.
 *
 * ELLE NE MONTRE QUE CE QUI A ÉTÉ PUBLIÉ, jamais tous les biens volés. Déclarer
 * un vol rend le bien invendable pour qui vérifie son numéro ; y figurer est un
 * geste distinct, que le détenteur pose lui-même. Sans cette distinction, la
 * plateforme exposerait des identifiants que personne ne lui a demandé
 * d'exposer.
 *
 * ELLE NE NOMME PERSONNE. Ni le détenteur dépouillé — un annuaire de victimes
 * serait une liste de gens à démarcher, ou à viser une seconde fois — ni qui
 * consulte.
 */
final class StolenListController extends Controller
{
    /** Au-delà, la page devient illisible sur un téléphone. */
    private const PAR_PAGE = 20;

    /** Ce que l'accueil montre : assez pour alerter, pas pour noyer. */
    private const APERCU = 10;

    public function __construct(private readonly StolenListingService $liste) {}

    /**
     * La liste complète, paginée et cherchable.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:64'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $page = $this->liste->browse(
            $request->string('q')->toString() ?: null,
            (int) $request->integer('per_page', self::PAR_PAGE),
        );

        return response()->json([
            'stolen' => array_map(
                fn (Asset $bien): array => $this->liste->present($bien),
                $page->items(),
            ),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ])->header('Cache-Control', 'public, max-age=60');
    }

    /**
     * L'aperçu de l'accueil.
     *
     * DIX AU PLUS, ET LE TOTAL À CÔTÉ. Une liste sans fin sur un écran
     * d'accueil se fait ignorer ; dix biens et « 47 au total » donne une raison
     * d'ouvrir la liste entière.
     *
     * SÉPARÉ DE `index()` À DESSEIN : cette réponse part sur le chemin le plus
     * fréquenté du produit, et elle doit rester petite. La faire dériver de la
     * pagination générale ferait grossir l'accueil au premier réglage changé.
     */
    public function preview(): JsonResponse
    {
        $page = $this->liste->browse(null, self::APERCU);

        return response()->json([
            'stolen' => array_map(
                fn (Asset $bien): array => $this->liste->present($bien),
                $page->items(),
            ),
            'total' => $page->total(),
            'has_more' => $page->total() > self::APERCU,
        ])->header('Cache-Control', 'public, max-age=60');
    }
}
