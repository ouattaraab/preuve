<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlatformState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bascule du mode lecture seule (ST-0904).
 *
 * Réservé aux administrateurs, qui restent seuls à pouvoir écrire pendant la
 * maintenance : c'est par une écriture qu'on en sort.
 */
final class PlatformStateController extends Controller
{
    public function __construct(private readonly PlatformState $etat) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'read_only' => $this->etat->isReadOnly(),
            'reason' => $this->etat->reason(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'read_only' => ['required', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $administrateur = $request->user();
        $motif = $request->string('reason')->toString();

        $this->etat->setReadOnly(
            $request->boolean('read_only'),
            $administrateur instanceof User ? $administrateur->id : null,
            $motif === '' ? null : $motif,
        );

        return response()->json([
            'read_only' => $this->etat->isReadOnly(),
            'reason' => $this->etat->reason(),
        ]);
    }
}
