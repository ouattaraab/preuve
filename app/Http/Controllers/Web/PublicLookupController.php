<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use App\Services\Captcha\CaptchaVerifier;
use App\Services\LookupResult;
use App\Services\LookupService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Front public de consultation (ST-0306).
 *
 * IL PASSE PAR LE MÊME SERVICE QUE L'API, et ce n'est pas une commodité : un
 * second chemin de consultation qui contournerait le plafond horaire ferait de
 * cette page l'outil de balayage du registre que le plafond existe pour
 * empêcher. Même service, même empreinte d'adresse, même quota, même
 * échappatoire par défi.
 *
 * SEULES LES PAGES `/b/{référence}` SONT INDEXABLES. La page de résultat porte
 * l'identifiant réel dans son URL : la laisser indexer publierait, moteur de
 * recherche après moteur de recherche, l'annuaire des numéros de châssis
 * enregistrés — exactement ce que la référence publique opaque existe pour
 * éviter. Elle est donc en `noindex`, en balise ET en en-tête.
 *
 * IL N'Y A PAS DE PLAN DE SITE. Un sitemap énumérant les références publiques
 * publierait le registre sous forme de liste : chaque page prise isolément est
 * anodine, leur collection ne l'est pas. Les pages se découvrent par le lien
 * qu'un vendeur partage, jamais par énumération.
 *
 * AUCUN SCRIPT, AUCUNE RESSOURCE TIERCE. CT-05 impose l'utilisabilité en 3G ;
 * et une page qui n'appelle personne d'autre ne peut divulguer à personne
 * d'autre ce que le visiteur est venu vérifier.
 */
final class PublicLookupController extends Controller
{
    public function __construct(
        private readonly LookupService $lookups,
        private readonly CaptchaVerifier $captcha,
    ) {}

    /** Page d'accueil : un champ, un bouton (CT-01, deux interactions). */
    public function home(): Response
    {
        return response()->view('public.home');
    }

    /**
     * Résultat d'une saisie libre.
     *
     * `noindex` sans exception : l'URL porte l'identifiant réel.
     */
    public function verify(Request $request): Response
    {
        $saisie = trim((string) $request->query('q', ''));

        if ($saisie === '') {
            return response()->view('public.home', ['erreur' => 'Saisissez un identifiant à vérifier.']);
        }

        $resultat = $this->consulter($request, $saisie);
        $this->autoriserLeDefi($request, $resultat);

        return response()
            ->view('public.verdict', [
                'resultat' => $resultat,
                'saisie' => $saisie,
                'indexable' => false,
                ...$this->referencement($resultat),
                'captcha' => $resultat->rateLimited && $this->captcha->isConfigured()
                    ? $this->captcha->siteKey()
                    : null,
            ], $this->code($resultat))
            ->header('X-Robots-Tag', 'noindex, nofollow')
            // La page ne charge aucune ressource tierce ; l'en-tête garantit
            // qu'un lien suivi depuis elle n'emporte pas l'identifiant vérifié
            // dans le `Referer` d'un site qui n'a pas à le connaître.
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Page de statut indexable, adressée par la référence publique opaque.
     *
     * C'est l'URL qu'un vendeur partage et qu'un moteur de recherche peut
     * garder : elle ne laisse rien deviner de l'identifiant réel du bien, et
     * encore moins de son propriétaire.
     */
    public function asset(Request $request, string $publicRef): Response
    {
        $resultat = $this->consulter($request, $publicRef);
        $this->autoriserLeDefi($request, $resultat);

        return response()
            ->view('public.verdict', [
                'resultat' => $resultat,
                'saisie' => $publicRef,
                'indexable' => $resultat->found,
                ...$this->referencement($resultat),
                'captcha' => $resultat->rateLimited && $this->captcha->isConfigured()
                    ? $this->captcha->siteKey()
                    : null,
            ], $this->code($resultat))
            // Une référence inconnue ne doit pas s'installer dans un index :
            // le bien peut avoir été archivé, et la page ne dit alors plus rien
            // d'utile.
            ->header('X-Robots-Tag', $resultat->found ? 'index, follow' : 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer')
            // Court, mais suffisant pour absorber les rafales d'une page
            // partagée. Au-delà d'une minute, une déclaration de vol mettrait
            // trop de temps à devenir visible — c'est l'information la plus
            // urgente du produit.
            ->header('Cache-Control', $resultat->found ? 'public, max-age=60' : 'no-store, private');
    }

    /**
     * Ouvre la politique de sécurité à l'origine du défi, et seulement quand
     * la page l'affiche réellement.
     *
     * L'autoriser en permanence rendrait la promesse « aucune ressource tierce
     * sur le chemin nominal » invérifiable : elle ne tiendrait plus qu'à ce que
     * personne n'ajoute un jour la balise.
     */
    private function autoriserLeDefi(Request $request, LookupResult $resultat): void
    {
        if ($resultat->rateLimited && $this->captcha->isConfigured()) {
            $request->attributes->set(SecurityHeaders::ATTRIBUT_DEFI, true);
        }
    }

    /**
     * Titre et description de la page.
     *
     * Composés ICI et non dans le gabarit : une section Blade multiligne
     * emporte ses retours à la ligne dans la balise `<title>`, ce qui ne se
     * voit qu'en regardant le HTML rendu — et se voit ensuite dans les
     * résultats de recherche.
     *
     * @return array<string, string>
     */
    private function referencement(LookupResult $resultat): array
    {
        $bien = $resultat->asset;

        if ($bien === null) {
            return [
                'titre' => "Vérifier un bien avant d'acheter · Preuve",
                'description' => 'Vérifiez gratuitement, sans compte et en deux gestes si un véhicule '.
                    'ou un téléphone est déclaré volé, en litige ou en location.',
            ];
        }

        return [
            'titre' => sprintf('%s — bien %s · Preuve', $bien->life_status->label(), $bien->public_ref),
            'description' => sprintf(
                'Statut déclaré du bien %s au registre Preuve : %s',
                $bien->public_ref,
                $bien->life_status->publicMessage(),
            ),
        ];
    }

    private function consulter(Request $request, string $saisie): LookupResult
    {
        $consultant = $request->user('sanctum');

        return $this->lookups->lookup(
            $saisie,
            $request->ip() ?? '0.0.0.0',
            $consultant instanceof User ? $consultant : null,
            // Distingué de l'application dans les statistiques : les deux
            // publics n'arrivent pas par le même chemin et ne se pilotent pas
            // de la même façon.
            'web',
            $this->jetonDeDefi($request),
        );
    }

    /**
     * Le code HTTP suit le verdict : un plafond atteint reste un 429, y
     * compris servi en HTML. Un moteur de recherche qui verrait un 200 sur une
     * page de refus l'indexerait à la place du verdict.
     */
    private function code(LookupResult $resultat): int
    {
        if ($resultat->rateLimited) {
            return Response::HTTP_TOO_MANY_REQUESTS;
        }

        if ($resultat->invalidIdentifier) {
            return Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $resultat->found ? Response::HTTP_OK : Response::HTTP_NOT_FOUND;
    }

    private function jetonDeDefi(Request $request): ?string
    {
        $jeton = $request->input('cf-turnstile-response');

        return is_string($jeton) && $jeton !== '' ? $jeton : null;
    }
}
