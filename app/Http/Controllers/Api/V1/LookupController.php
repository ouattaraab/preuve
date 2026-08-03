<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Captcha\CaptchaVerifier;
use App\Services\LookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consultation publique du statut d'un bien (EP-03).
 *
 * Cette route n'exige AUCUNE authentification, et ne doit jamais en exiger
 * (règle métier absolue n° 1) : c'est la promesse du produit — vérifier un bien
 * au marché, avant de payer, sans rien installer ni créer de compte.
 *
 * Le garde Sanctum est interrogé directement plutôt que posé en middleware :
 * un porteur de jeton est ainsi reconnu — ce qui le dispense du plafond
 * anonyme — sans que le jeton devienne jamais nécessaire. Un middleware
 * d'authentification, même « facultatif », finirait tôt ou tard par être
 * durci par erreur.
 */
final class LookupController extends Controller
{
    public function __construct(
        private readonly LookupService $lookups,
        private readonly CaptchaVerifier $captcha,
    ) {}

    public function show(Request $request, string $identifier): JsonResponse
    {
        $consultant = $request->user('sanctum');

        $resultat = $this->lookups->lookup(
            $identifier,
            $request->ip() ?? '0.0.0.0',
            $consultant instanceof User ? $consultant : null,
            $request->query('source') === 'web' ? 'web' : 'app',
            // Présenté par le client APRÈS un premier refus : le chemin
            // nominal ne transporte aucun jeton et ne paie aucun aller-retour.
            $this->captchaToken($request),
        );

        if ($resultat->invalidIdentifier) {
            return response()->json($resultat->toPublicArray(), 422);
        }

        if ($resultat->rateLimited) {
            // Le CAPTCHA est l'échappatoire prévue par §8 : le plafond ne doit
            // pas condamner un visiteur légitime derrière une adresse
            // partagée — cas courant en Côte d'Ivoire, où un cybercafé ou un
            // partage de connexion mobile mutualise une seule adresse
            // publique. Le client doit donc présenter un défi, pas abandonner.
            return response()->json([
                ...$resultat->toPublicArray(),
                'captcha_required' => true,
                // La clé publique voyage AVEC le refus, et seulement là : le
                // client n'a besoin d'afficher un défi qu'à ce moment, et rien
                // ne l'oblige à charger un script tiers sur le chemin nominal.
                'captcha' => $this->captcha->isConfigured()
                    ? ['provider' => 'turnstile', 'site_key' => $this->captcha->siteKey()]
                    // Non configuré : le refus tient, mais on ne promet pas une
                    // échappatoire qui n'existe pas.
                    : null,
            ], 429)->header('Retry-After', '3600');
        }

        return response()
            ->json($resultat->toPublicArray())
            // Court, mais suffisant pour absorber les rafales d'une page de
            // statut partagée (CT-01 : moins d'une seconde au 95e centile en
            // 3G). Au-delà d'une minute, une déclaration de vol mettrait trop
            // de temps à devenir visible — c'est précisément l'information la
            // plus urgente du produit.
            ->header('Cache-Control', 'public, max-age=60');
    }

    /** En-tête ou paramètre : un client web pose l'un, un client mobile l'autre. */
    private function captchaToken(Request $request): ?string
    {
        foreach ([$request->header('X-Captcha-Token'), $request->query('captcha_token')] as $valeur) {
            if (is_string($valeur) && $valeur !== '') {
                return $valeur;
            }
        }

        return null;
    }
}
