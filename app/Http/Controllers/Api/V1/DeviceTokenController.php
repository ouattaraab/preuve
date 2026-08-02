<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Enregistrement des appareils pour le push (ST-1003).
 *
 * Le jeton appartient à l'APPAREIL, pas au compte : un téléphone revendu ou
 * prêté peut se présenter avec un jeton déjà connu sous un autre compte. Le
 * réenregistrement remplace donc le rattachement précédent — sans quoi l'ancien
 * propriétaire continuerait de recevoir les alertes du nouveau, ce qui est
 * exactement la fuite que la plateforme s'interdit ailleurs.
 */
final class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['sometimes', Rule::in(['android', 'ios'])],
        ]);

        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        $jeton = $request->string('token')->toString();

        DB::table('device_tokens')->updateOrInsert(
            ['token' => $jeton],
            [
                'user_id' => $utilisateur->id,
                'platform' => $request->string('platform')->toString() ?: 'android',
                'last_seen_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return response()->json(['message' => 'Appareil enregistré.'], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:255']]);

        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        DB::table('device_tokens')
            ->where('token', $request->string('token')->toString())
            ->where('user_id', $utilisateur->id)
            ->delete();

        return response()->json(['message' => 'Appareil retiré.']);
    }
}
