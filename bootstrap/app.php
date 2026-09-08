<?php

declare(strict_types=1);

use App\Exceptions\OtpRefuseException;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * En-têtes de sécurité sur TOUTE réponse — API comprise.
         *
         * Posés par l'application et non par le serveur : sur un mutualisé, la
         * configuration ne nous appartient pas et une intervention de
         * l'hébergeur remet les fichiers en l'état. Ce qui est dans le dépôt
         * survit au redéploiement ; ce qui est posé à la main disparaît sans
         * prévenir.
         */
        $middleware->append(SecurityHeaders::class);

        /*
         * OÙ ENVOYER UN VISITEUR NON AUTHENTIFIÉ — et la réponse diffère selon
         * la porte qu'il pousse.
         *
         * Sur l'API, NULLE PART : par défaut le middleware construit une
         * redirection vers une route nommée `login`, et cet appel a lieu DANS
         * le middleware, avant que le moindre gestionnaire d'exception ne voie
         * quoi que ce soit. Le refus d'accès se transformait en 500 et en page
         * HTML. En rendant `null`, l'exception parvient au gestionnaire, qui
         * répond 401 en JSON.
         *
         * Sur la console d'administration, vers son écran de connexion : un
         * agent dont la session a expiré doit se retrouver devant un champ de
         * saisie, pas devant une erreur.
         */
        $middleware->redirectGuestsTo(static fn (Request $requete): ?string => $requete->is('api/*') || $requete->expectsJson()
            ? null
            : route('admin.login'));

        /*
         * La console d'administration s'authentifie par SESSION, pas par jeton.
         *
         * Ce middleware fait reconnaître le cookie de session par le garde
         * Sanctum lorsque la requête vient du domaine de la plateforme : la
         * console peut alors interroger les mêmes `/api/v1/admin/*` que
         * n'importe quel client, sans qu'aucun jeton ne transite par le
         * navigateur — où la première faille XSS le lirait.
         *
         * Les clients mobiles n'en sont pas affectés : le traitement ne
         * s'applique qu'aux requêtes portant une origine déclarée « stateful ».
         */
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * TOUT CE QUI EST SOUS `api/` RÉPOND EN JSON, que le client ait pensé
         * ou non à poser un en-tête `Accept`.
         *
         * Sans cela, une requête non authentifiée sans cet en-tête suit le
         * chemin web par défaut : Laravel tente de rediriger vers une route
         * `login`, qui n'existe pas dans une API — et le refus d'accès se
         * transforme en **500 accompagné d'une page HTML**. Un intégrateur y
         * lit une panne serveur là où il n'a qu'oublié son jeton, et cherche
         * du côté de la plateforme pendant que le défaut est chez lui.
         *
         * Constaté le 03/08/2026 sur `PUT /api/v1/admin/captcha-provider`.
         */
        $rendJson = static fn (Request $requete): bool => $requete->is('api/*') || $requete->expectsJson();

        $exceptions->shouldRenderJsonWhen($rendJson);

        // UN REFUS D'OTP N'EST PAS UNE ERREUR SERVEUR : ON NE LE JOURNALISE PAS.
        //
        // Un numéro mal saisi, un code périmé, un plafond de demandes atteint —
        // ce sont des réponses métier attendues, rendues proprement au client
        // (voir le `render` plus bas). Les laisser remonter au niveau ERROR
        // faisait qu'une simple faute de frappe d'un utilisateur inscrivait une
        // ligne d'erreur en production : sur une plateforme grand public, ce
        // bruit finit par noyer les vraies pannes, celles qu'un journal
        // d'erreurs existe pour rendre visibles. `normalizeDestination()` lève
        // cette exception AVANT le try des contrôleurs (dès la validation de la
        // destination), d'où sa remontée jusqu'ici.
        $exceptions->dontReport(OtpRefuseException::class);

        // Le refus d'authentification est traité EXPLICITEMENT, et non laissé
        // au comportement par défaut : celui-ci redirige vers une route
        // `login` que cette application n'a pas, et transforme un 401 en 500.
        // Le rendre ici ne dépend d'aucun détail interne du framework.
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($rendJson): ?Response {
            return $rendJson($request)
                ? response()->json(['message' => 'Authentification requise.'], 401)
                : null;
        });

        // Un refus d'OTP est une réponse métier, pas une erreur serveur : le
        // client mobile doit distinguer « saisie incorrecte » de « réessayez
        // plus tard » pour ne pas relancer une demande en boucle sur un
        // réseau 3G instable (CT-05). Le message est déjà en langage courant
        // et ne révèle ni le code attendu, ni l'existence d'un compte.
        $exceptions->render(function (OtpRefuseException $e, Request $request) use ($rendJson): ?Response {
            if (! $rendJson($request)) {
                return null;
            }

            $reponse = response()->json(
                ['message' => $e->getMessage(), 'reason' => $e->raison->value],
                $e->raison->httpStatus(),
            );

            $delai = $e->retryAfterSeconds();

            return $delai === null ? $reponse : $reponse->header('Retry-After', (string) $delai);
        });
    })->create();
