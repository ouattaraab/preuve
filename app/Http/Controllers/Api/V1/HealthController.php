<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditAnchorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sonde de supervision (ST-0904).
 *
 * Publique et sans authentification : une sonde qui exige un jeton ne peut pas
 * être interrogée par un superviseur externe le jour où l'authentification
 * elle-même est en panne — c'est-à-dire précisément quand on en a besoin.
 *
 * Elle ne révèle AUCUN détail exploitable : ni version, ni schéma, ni compteur
 * métier. Un attaquant n'y apprend rien qu'il ne puisse déduire en tentant une
 * requête, et un superviseur y trouve tout ce qu'il lui faut.
 *
 * L'ancrage figure parmi les contrôles parce que son absence est un défaut
 * SILENCIEUX : tout fonctionne, les utilisateurs ne voient rien, et la chaîne
 * d'audit cesse d'être opposable sans que personne ne s'en aperçoive.
 */
final class HealthController extends Controller
{
    public function show(AuditAnchorService $ancrages): JsonResponse
    {
        $baseDisponible = $this->databaseIsReachable();
        $dernierAncrage = $baseDisponible ? $ancrages->lastSuccessful() : null;

        // L'ancrage est quotidien : au-delà de 48 heures, deux passages ont
        // échoué et le silence a assez duré.
        $ancrageRecent = $dernierAncrage !== null
            && $dernierAncrage->created_at->gt(now()->subHours(48));

        $etat = $baseDisponible ? 'ok' : 'down';

        return response()->json([
            'status' => $etat,
            'checks' => [
                'database' => $baseDisponible ? 'ok' : 'down',
                'audit_anchor' => $ancrageRecent ? 'ok' : 'stale',
            ],
            'audit_chain_opposable' => $dernierAncrage !== null,
        ], $baseDisponible ? 200 : 503);
    }

    private function databaseIsReachable(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
