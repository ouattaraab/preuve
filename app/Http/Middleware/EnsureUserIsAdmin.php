<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve une route aux administrateurs.
 *
 * Répond 403 et non 404 : l'existence de l'espace d'administration n'est pas un
 * secret, et masquer la route à un utilisateur légitimement connecté mais mal
 * habilité ne ferait que compliquer le diagnostic. Ce qui est protégé, c'est
 * l'accès — pas la connaissance de son existence.
 */
final class EnsureUserIsAdmin
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof User || ! $utilisateur->isAdmin()) {
            abort(403, 'Cette action est réservée aux administrateurs de la plateforme.');
        }

        return $next($request);
    }
}
