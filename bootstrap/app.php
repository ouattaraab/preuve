<?php

declare(strict_types=1);

use App\Exceptions\OtpRefuseException;
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
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Un refus d'OTP est une réponse métier, pas une erreur serveur : le
        // client mobile doit distinguer « saisie incorrecte » de « réessayez
        // plus tard » pour ne pas relancer une demande en boucle sur un
        // réseau 3G instable (CT-05). Le message est déjà en langage courant
        // et ne révèle ni le code attendu, ni l'existence d'un compte.
        $exceptions->render(function (OtpRefuseException $e, Request $request): ?Response {
            if (! $request->expectsJson()) {
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
