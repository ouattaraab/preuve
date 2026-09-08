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
        // LES LECTURES PASSENT, y compris authentifiées. « Lecture seule » doit
        // vouloir dire ce que son nom annonce : refuser à quelqu'un l'accès à
        // son propre inventaire, à ses notifications ou à son dossier de
        // réclamation pendant une maintenance ne protège rien — aucune de ces
        // requêtes n'écrit — et transforme une indisponibilité partielle en
        // panne apparente, précisément le jour où l'on a le plus besoin de
        // paraître fiable. Même raisonnement, et même primitive, que
        // EnsureAppIsSupported : l'application doit pouvoir EXPLIQUER pourquoi
        // elle ne peut plus écrire, et une coquille vide ne l'explique pas.
        if ($request->isMethodSafe()) {
            return $next($request);
        }

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
