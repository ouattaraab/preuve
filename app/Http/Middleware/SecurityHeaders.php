<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité, posés sur TOUTE réponse.
 *
 * POSÉS PAR L'APPLICATION ET NON PAR LE SERVEUR : l'hébergement est mutualisé,
 * sa configuration ne nous appartient pas et une intervention de l'hébergeur
 * remettrait un `.htaccess` en l'état. Ce qui est dans le dépôt survit au
 * redéploiement ; ce qui est posé à la main disparaît sans prévenir — nous
 * l'avons déjà constaté avec la version de PHP.
 *
 * LE CLIQUE-DÉTOURNEMENT EST LE RISQUE PRINCIPAL, et il vise la console : un
 * cadre invisible superposé à l'écran des comptes ferait cliquer un agent sur
 * « Suspendre » en lui faisant croire qu'il accepte un cookie. `frame-ancestors
 * 'none'` l'interdit, `X-Frame-Options` le double pour les navigateurs anciens
 * — encore majoritaires sur un parc Android d'entrée de gamme.
 *
 * `script-src 'self'` SANS `'unsafe-inline'` : c'est ce qui a justifié de
 * retirer les attributs `onsubmit` des gabarits d'administration. Une console
 * qui affiche des pièces d'identité et des cartes grises n'a pas les moyens
 * d'autoriser le script en ligne, qui est précisément ce qu'une faille XSS
 * injecte.
 *
 * `style-src` GARDE `'unsafe-inline'`, et c'est un compromis assumé : la
 * maquette est écrite en styles en ligne, les réécrire en feuille externe
 * coûterait une réinterprétation complète pour un gain faible — un style ne
 * s'exécute pas et ne peut rien exfiltrer vers un tiers, `default-src 'none'`
 * fermant par ailleurs toute destination.
 */
final class SecurityHeaders
{
    /**
     * Marqueur posé par le contrôleur public quand la page affiche le défi
     * anti-automate. Le script de Cloudflare n'est autorisé QUE là : sur le
     * chemin nominal, aucune origine tierce n'est permise, et la politique
     * rend cette promesse vérifiable au lieu de la laisser déclarative.
     */
    public const ATTRIBUT_DEFI = 'csp.challenge';

    private const ORIGINE_DEFI = 'https://challenges.cloudflare.com';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $reponse = $next($request);

        $politique = $this->politique($request);

        $reponse->headers->set('Content-Security-Policy', $politique);

        // COPIE DE TRAVAIL, RECOPIÉE PAR `.htaccess` DANS L'EN-TÊTE RÉEL.
        //
        // LiteSpeed, chez l'hébergeur, REMPLACE `Content-Security-Policy` par
        // le sien (« upgrade-insecure-requests ») après le passage de PHP —
        // constaté le 04/08/2026 : tous les autres en-têtes posés ici
        // arrivaient, celui-là seul disparaissait. `mod_headers` s'exécute
        // après, et rétablit la politique depuis cette copie.
        //
        // Un en-tête statique dans `.htaccess` aurait suffi à écraser celui de
        // l'hébergeur, mais aurait figé la politique : l'origine du défi
        // anti-automate doit n'être ouverte que sur la page qui l'affiche.
        $reponse->headers->set('X-Preuve-CSP', $politique);
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('X-Frame-Options', 'DENY');
        // Ce que la plateforme n'a aucune raison de demander au navigateur.
        // La prise de vue du KYC se fait dans l'application mobile, jamais ici.
        $reponse->headers->set(
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()',
        );

        // Les pages publiques posent `no-referrer`, plus strict : ne pas
        // l'écraser. Ailleurs, l'origine suffit et reste utile au diagnostic.
        if (! $reponse->headers->has('Referrer-Policy')) {
            $reponse->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        // HSTS seulement sur une requête déjà chiffrée : l'annoncer en clair
        // n'aurait aucun effet, et le poser en développement local rendrait le
        // domaine de test inaccessible en HTTP pendant un an.
        //
        // SANS `includeSubDomains` : la directive engagerait des sous-domaines
        // que nous ne servons pas et dont nous ignorons la configuration —
        // une promesse qu'on ne peut pas tenir se paie en indisponibilité.
        if ($request->isSecure()) {
            $reponse->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        // La version exacte de PHP n'aide que celui qui cherche une faille
        // connue. Retiré ici plutôt que par `expose_php`, hors de notre portée
        // sur un mutualisé.
        $reponse->headers->remove('X-Powered-By');

        return $reponse;
    }

    private function politique(Request $request): string
    {
        $defi = $request->attributes->get(self::ATTRIBUT_DEFI) === true;

        $directives = [
            // Tout est fermé par défaut ; chaque ouverture est nommée.
            "default-src 'none'",
            $defi ? "script-src 'self' ".self::ORIGINE_DEFI : "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self'",
            "img-src 'self' data:",
            // La console interroge l'API sur la même origine ; rien d'autre.
            "connect-src 'self'",
            // Le défi s'affiche dans un cadre servi par Cloudflare.
            $defi ? 'frame-src '.self::ORIGINE_DEFI : "frame-src 'none'",
            // Un formulaire dont la destination serait détournée exfiltrerait
            // ce qu'un agent vient de saisir — un motif de suspension, une
            // référence de réquisition.
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            'upgrade-insecure-requests',
        ];

        return implode('; ', $directives);
    }
}
