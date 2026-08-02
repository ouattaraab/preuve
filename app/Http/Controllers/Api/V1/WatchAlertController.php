<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WatchAlert;
use App\Services\WatchAlertService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Veille sur un identifiant (ST-0403).
 *
 * Une veille ne se pose que sur un identifiant que l'on a soi-même enregistré :
 * sans cette borne, la veille deviendrait un outil de surveillance du bien
 * d'autrui — savoir quand il est consulté, donc quand il est mis en vente.
 */
final class WatchAlertController extends Controller
{
    public function __construct(private readonly WatchAlertService $watchers) {}

    public function index(Request $request): JsonResponse
    {
        $utilisateur = $this->utilisateur($request);

        $veilles = WatchAlert::query()
            ->where('user_id', $utilisateur->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (WatchAlert $veille): array => $this->present($veille))
            ->all();

        return response()->json(['watch_alerts' => $veilles]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'identifier' => ['required', 'string', 'max:64'],
            'channel' => ['sometimes', Rule::in(['push', 'sms', 'both'])],
        ]);

        try {
            $veille = $this->watchers->watch(
                $this->utilisateur($request),
                $request->string('identifier')->toString(),
                $request->string('channel')->toString() ?: 'push',
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['identifier' => $e->getMessage()]);
        }

        return response()->json(['watch_alert' => $this->present($veille)], 201);
    }

    public function destroy(Request $request, string $identifier): JsonResponse
    {
        $retiree = $this->watchers->unwatch($this->utilisateur($request), $identifier);

        if (! $retiree) {
            abort(404);
        }

        return response()->json(['message' => 'Veille désactivée.']);
    }

    private function utilisateur(Request $request): User
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        return $utilisateur;
    }

    /** @return array<string, mixed> */
    private function present(WatchAlert $veille): array
    {
        return [
            'id' => $veille->id,
            'identifier' => $veille->identifier_normalized,
            'channel' => $veille->channel,
            'active' => $veille->is_active,
            'last_triggered_at' => $veille->last_triggered_at?->toIso8601String(),
        ];
    }
}
