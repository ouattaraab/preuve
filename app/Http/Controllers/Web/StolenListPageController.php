<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\StolenListingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * La page publique des biens volés (ST-0805).
 *
 * INDEXABLE, ET C'EST TOUT SON INTÉRÊT. Contrairement à la page de résultat
 * d'une consultation — qui porte l'identifiant cherché et ne doit surtout pas
 * être indexée —, cette liste ne contient que ce que des détenteurs ont
 * DEMANDÉ à rendre public. La faire trouver par un moteur de recherche est
 * exactement le service qu'ils ont payé : quelqu'un qui tape un numéro de
 * plaque dans Google doit tomber dessus.
 *
 * ELLE NE NOMME PERSONNE. La liste sert à retrouver des biens, pas à désigner
 * des victimes.
 */
final class StolenListPageController extends Controller
{
    public function __construct(private readonly StolenListingService $liste) {}

    public function index(Request $request): Response
    {
        // UN PARAMÈTRE RÉPÉTÉ REND UN TABLEAU — `?q[]=a&q[]=b` — et ni
        // `trim()` ni `->string()` n'y survivent. Une page publique reçoit tout
        // ce qu'on veut bien lui envoyer, et une 500 sur une URL bricolée est
        // une porte ouverte à qui cherche les limites.
        $brut = $request->query('q');
        $recherche = is_string($brut) ? trim($brut) : '';
        $page = $this->liste->browse($recherche === '' ? null : $recherche, 20);

        return response()->view('public.voles', [
            'indexable' => true,
            'titre' => 'Biens volés déclarés · Preuve',
            'description' => 'La liste publique des motos, voitures et téléphones déclarés volés '
                .'en Côte d\'Ivoire. Cherchez un numéro avant d\'acheter.',
            'recherche' => $recherche,
            'biens' => array_map(
                fn (Asset $bien): array => $this->liste->present($bien),
                $page->items(),
            ),
            'pagination' => $page,
        ])->header('X-Robots-Tag', 'index, follow');
    }
}
