<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AppRelease;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse les ÉCRITURES venues d'une application trop ancienne.
 *
 * IL N'EST POSÉ QUE SUR LES ÉCRITURES, et jamais sur la consultation : un
 * verdict reste gratuit, anonyme et sans condition, y compris depuis un vieux
 * téléphone (règle métier absolue n° 1). Ce qui est bloqué, ce sont les gestes
 * dont les règles ont pu changer entre deux versions — enregistrer, transférer,
 * réclamer, déclarer un vol.
 *
 * 426 et non 403 : le code dit précisément « mettez à jour », et la réponse
 * porte la version exigée. Un 403 laisserait l'utilisateur croire qu'on lui
 * refuse un droit, alors qu'on lui demande une action qu'il peut accomplir.
 */
final class EnsureAppIsSupported
{
    public function __construct(private readonly AppRelease $release) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        // Les lectures passent, même sur une version dépassée : l'application
        // doit pouvoir afficher l'état du compte et des dossiers en cours pour
        // expliquer POURQUOI elle ne peut plus écrire. Une coquille vide
        // ressemblerait à une panne.
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $annoncee = $request->header('X-App-Version');

        if (! $this->release->isOutdated(is_string($annoncee) ? $annoncee : null)) {
            return $next($request);
        }

        return new JsonResponse([
            'message' => 'Cette version de l\'application est trop ancienne pour enregistrer '.
                'ou modifier un bien. Mettez-la à jour, puis réessayez.',
            'minimum_version' => $this->release->minimum(),
            'latest_version' => $this->release->latest(),
            // Dit explicitement ce qui reste ouvert : sans cette phrase,
            // l'utilisateur croirait l'application hors service.
            'still_available' => 'La consultation d\'un identifiant reste accessible sans mise à jour.',
        ], Response::HTTP_UPGRADE_REQUIRED);
    }
}
