<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centre de notifications in-app (ST-1001) et préférences (ST-0107).
 *
 * Un utilisateur ne voit que SES notifications : la requête est toujours
 * bornée par le porteur du jeton, jamais par un identifiant soumis — sans quoi
 * il suffirait d'incrémenter un numéro pour lire le fil d'activité d'autrui,
 * et donc savoir quels biens sont consultés.
 */
final class NotificationController extends Controller
{
    private const PAGE_SIZE = 20;

    public function __construct(private readonly NotificationService $notifications) {}

    /** Fil chronologique avec badge de non-lus. */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $this->utilisateur($request);

        $fil = Notification::query()
            ->where('user_id', $utilisateur->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE);

        return response()->json([
            'unread_count' => Notification::where('user_id', $utilisateur->id)->unread()->count(),
            'notifications' => collect($fil->items())->map(
                fn (Notification $notification): array => $this->present($notification)
            )->all(),
            'meta' => [
                'current_page' => $fil->currentPage(),
                'last_page' => $fil->lastPage(),
                'total' => $fil->total(),
            ],
        ]);
    }

    /** Marque une notification comme lue. */
    public function markAsRead(Request $request, int $notification): JsonResponse
    {
        $utilisateur = $this->utilisateur($request);

        $ligne = Notification::query()
            ->where('user_id', $utilisateur->id)
            ->whereKey($notification)
            ->first();

        // 404 et non 403 : répondre « interdit » confirmerait l'existence de la
        // notification d'un autre, donc l'activité sur son bien.
        if ($ligne === null) {
            abort(404);
        }

        if ($ligne->read_at === null) {
            $ligne->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['notification' => $this->present($ligne)]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $utilisateur = $this->utilisateur($request);

        $marquees = Notification::query()
            ->where('user_id', $utilisateur->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['marked' => $marquees, 'unread_count' => 0]);
    }

    /** Préférences en vigueur et types réellement désactivables (ST-0107). */
    public function preferences(Request $request): JsonResponse
    {
        $utilisateur = $this->utilisateur($request);

        return response()->json([
            'preferences' => $this->notifications->preferencesOf($utilisateur),
            'available' => $this->catalogue(),
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $request->validate(['preferences' => ['required', 'array']]);

        $utilisateur = $this->utilisateur($request);
        $demandes = $request->array('preferences');

        /** @var array<string, mixed> $demandes */
        $retenues = $this->notifications->updatePreferences($utilisateur, $demandes);

        return response()->json([
            'preferences' => $retenues,
            'available' => $this->catalogue(),
        ]);
    }

    /**
     * Types que l'utilisateur peut réellement couper. Les événements critiques
     * n'y figurent pas : proposer une case à cocher qui ne fait rien serait
     * pire que de ne pas la proposer.
     *
     * @return list<array{type: string, label: string}>
     */
    private function catalogue(): array
    {
        $catalogue = [];

        foreach (NotificationType::cases() as $type) {
            if ($type->isOptional()) {
                $catalogue[] = ['type' => $type->value, 'label' => $type->label()];
            }
        }

        return $catalogue;
    }

    /** @return array<string, mixed> */
    private function present(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type->value,
            'title' => $notification->title,
            'body' => $notification->body,
            'payload' => $notification->payload,
            'asset_id' => $notification->asset_id,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }

    private function utilisateur(Request $request): User
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        return $utilisateur;
    }
}
