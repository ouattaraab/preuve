<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PlatformState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse les écritures pendant une maintenance en lecture seule (ST-0904).
 *
 * Ne s'applique QU'AUX routes d'écriture : la consultation publique n'en voit
 * jamais la couleur. Fermer la consultation pour migrer un schéma reviendrait à
 * ce que, ce jour-là, un vol passe inaperçu.
 *
 * Les administrateurs passent outre : c'est par une écriture — la bascule du
 * réglage — qu'on sort du mode, et s'en interdire l'accès ferait de la
 * maintenance un piège sans issue.
 */
final class EnsurePlatformIsWritable
{
    public function __construct(private readonly PlatformState $etat) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->etat->isReadOnly()) {
            return $next($request);
        }

        $utilisateur = $request->user();

        if ($utilisateur instanceof User && $utilisateur->isAdmin()) {
            return $next($request);
        }

        // 503 et non 403 : ce n'est pas un refus de droit mais une
        // indisponibilité temporaire, et un client mobile doit pouvoir la
        // distinguer pour réessayer plus tard plutôt que d'abandonner.
        abort(503, $this->etat->readOnlyMessage());
    }
}
