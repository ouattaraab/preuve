<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\FraudSignalsService;
use App\Services\TelemetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Observabilité du back-office (ST-0902, ST-0903).
 *
 * Deux tableaux : celui qui dit si les promesses produit sont tenues, et celui
 * qui dit par où commencer les contrôles. Aucun des deux ne décide quoi que ce
 * soit — ils préparent des décisions humaines.
 */
final class ObservabilityController extends Controller
{
    public function __construct(
        private readonly TelemetryService $telemetrie,
        private readonly FraudSignalsService $fraude,
    ) {}

    public function telemetry(Request $request): JsonResponse
    {
        $jours = $request->has('days') ? (int) $request->integer('days') : 7;

        return response()->json($this->telemetrie->report($jours));
    }

    public function fraud(Request $request): JsonResponse
    {
        $jours = $request->has('days') ? (int) $request->integer('days') : 30;

        return response()->json($this->fraude->dashboard($jours));
    }
}
