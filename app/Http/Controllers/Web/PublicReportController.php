<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\ReportAccessService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * Le rapport détaillé, lisible sur n'importe quel appareil (ST-0802).
 *
 * POURQUOI CETTE PAGE EXISTE. La règle métier n° 7 autorise l'achat SANS
 * COMPTE — un trio nom, courriel, téléphone suffit. Jusqu'ici, un tel acheteur
 * payait et recevait un jeton dans une réponse HTTP : ni application, ni page,
 * rien à ouvrir. De l'argent entrait, et rien d'utilisable n'en sortait. Le
 * commentaire de la route API l'annonçait pourtant depuis le début — « pour
 * qu'un rapport reçu par SMS s'ouvre sur n'importe quel appareil ».
 *
 * LE JETON EST UNE CAPACITÉ AU PORTEUR : quiconque l'a, lit. C'est voulu — un
 * rapport se transfère à son garagiste ou à sa banque sans créer de compte —
 * mais cela impose trois choses, appliquées ici :
 *
 * — la page n'est JAMAIS indexée, et n'est jamais mise en cache ;
 * — elle ne charge AUCUNE ressource tierce, donc le jeton ne peut fuir par un
 *   en-tête `Referer` vers un régisseur ;
 * — elle expire avec l'achat, et le dit plutôt que de rendre une page vide.
 *
 * ELLE NE NOMME PERSONNE. Le rapport compose déjà le nombre de détenteurs et
 * les dates de changement, jamais les personnes (règle métier n° 4) : cette
 * page ne fait que le mettre en forme, et n'a accès à rien d'autre.
 */
final class PublicReportController extends Controller
{
    public function __construct(private readonly ReportAccessService $rapports) {}

    public function show(string $token): View|Response
    {
        try {
            $rapport = $this->rapports->read($token);
        } catch (DomainException $e) {
            // 404 ET NON 403 : distinguer « jeton inconnu » de « jeton expiré »
            // par le code de statut donnerait à un automate de quoi éprouver
            // des jetons au hasard. Le MESSAGE, lui, distingue — parce qu'un
            // acheteur légitime a besoin de savoir s'il doit racheter ou s'il
            // s'est trompé de lien.
            return response()->view('public.rapport-absent', [
                'titre' => 'Rapport indisponible — Preuve',
                'message' => $e->getMessage(),
            ], 404)->withHeaders($this->entetes());
        }

        return response()->view('public.rapport', [
            'titre' => 'Rapport détaillé — Preuve',
            'rapport' => $rapport,
        ])->withHeaders($this->entetes());
    }

    /**
     * Les MÊMES en-têtes sur la page trouvée et sur la page absente.
     *
     * L'adresse d'un jeton EXPIRÉ porte encore un jeton réel : le 404 doit se
     * protéger comme le 200. Ne les poser que sur le succès laissait la page
     * d'échec retomber sur la politique par défaut du site — plus permissive,
     * et divergente de ce que le test vérifiait.
     *
     * @return array<string, string>
     */
    private function entetes(): array
    {
        return [
            // AUCUN CACHE, NULLE PART. La page porte un rapport payé, et son
            // adresse porte la capacité de le lire : un cache partagé — celui
            // d'un cybercafé, d'un proxy d'entreprise — le rendrait au suivant.
            'Cache-Control' => 'no-store, private, max-age=0',
            // Le jeton est DANS l'URL. Sans cet en-tête, le moindre lien
            // sortant l'expédierait au site visité.
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
