<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve une route au back-office : agents comme administrateurs.
 *
 * Distinct de EnsureUserIsAdmin, qui protège la configuration de la
 * plateforme. Instruire un dossier — revoir un justificatif, arbitrer une
 * réclamation — n'est pas configurer la plateforme, et un agent n'a aucune
 * raison de pouvoir rerouter les SMS.
 */
final class EnsureUserHasBackOfficeAccess
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        // Le rôle est toujours défini : le modèle reprend le défaut de la
        // colonne, si bien qu'une instance jamais relue depuis la base vaut
        // « user » plutôt que null.
        if (! $utilisateur instanceof User || ! $utilisateur->role->hasBackOfficeAccess()) {
            abort(403, 'Cette action est réservée aux agents de la plateforme.');
        }

        return $next($request);
    }
}
