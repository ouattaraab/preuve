<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActorType;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authentification sans mot de passe (ST-0101 / ST-0102) : un code envoyé au
 * téléphone, échangé contre un jeton Sanctum.
 *
 * Les deux stories partagent un seul parcours : au premier code vérifié le
 * compte est créé, aux suivants il est retrouvé. Séparer « inscription » et
 * « connexion » obligerait l'utilisateur à savoir s'il a déjà un compte — et
 * exposerait, par la différence de parcours, l'existence de ce compte.
 */
final class OtpAuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditChain $auditChain,
    ) {}

    /**
     * Émet un code. La réponse est délibérément identique que le numéro soit
     * connu ou non : toute différence observable ferait de cette route un
     * service d'énumération d'abonnés.
     */
    public function request(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'purpose' => ['required', Rule::enum(OtpPurpose::class)],
        ]);

        $defi = $this->otp->request(
            $request->string('phone')->toString(),
            OtpPurpose::from($request->string('purpose')->toString()),
        );

        return response()->json([
            'message' => 'Si ce numéro est joignable, un code vient de lui être envoyé.',
            'expires_in' => max(0, (int) now()->diffInSeconds($defi->expires_at, true)),
        ]);
    }

    /**
     * Vérifie le code et ouvre une session. Le compte est créé au premier
     * passage : c'est la vérification du code qui prouve la maîtrise du
     * numéro, donc le seul moment où la création est légitime.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'purpose' => ['required', Rule::enum(OtpPurpose::class)],
            'code' => ['required', 'string'],
            'revoke_other_devices' => ['sometimes', 'boolean'],
        ]);

        $motif = OtpPurpose::from($request->string('purpose')->toString());

        $this->otp->verify(
            $request->string('phone')->toString(),
            $request->string('code')->toString(),
            $motif,
        );

        $telephone = $this->otp->normalizeDestination($request->string('phone')->toString());
        $existant = User::where('phone', $telephone)->first();

        // La chaîne d'audit englobe la création du compte : sans transaction
        // commune, un compte pourrait exister sans trace d'audit, ou l'inverse.
        $utilisateur = $existant ?? $this->auditChain->transaction(
            function () use ($telephone): array {
                $nouveau = User::create(['phone' => $telephone]);
                $nouveau->forceFill(['phone_verified_at' => now()])->save();

                return [
                    'result' => $nouveau,
                    'actorType' => ActorType::User,
                    'actorId' => $nouveau->id,
                    'action' => 'auth.account_created',
                    'entityType' => 'user',
                    'entityId' => $nouveau->id,
                    // Jamais le numéro : audit_log est inaltérable et
                    // survivrait à tout exercice du droit à l'effacement
                    // (Loi 2013-450, ST-0105).
                    'payload' => ['channel' => 'sms'],
                ];
            }
        );

        if ($existant !== null && $utilisateur->phone_verified_at === null) {
            $utilisateur->forceFill(['phone_verified_at' => now()])->save();
        }

        if ($request->boolean('revoke_other_devices')) {
            $utilisateur->tokens()->delete();
        }

        $jeton = $utilisateur->createToken('mobile')->plainTextToken;

        $this->auditChain->append(
            ActorType::User,
            $utilisateur->id,
            'auth.session_opened',
            'user',
            $utilisateur->id,
            [
                'purpose' => $motif->value,
                'revoked_other_devices' => $request->boolean('revoke_other_devices'),
            ],
        );

        return response()->json([
            'token' => $jeton,
            'user' => [
                'id' => $utilisateur->id,
                'phone' => $utilisateur->phone,
                'full_name' => $utilisateur->full_name,
                'kyc_status' => $utilisateur->kyc_status,
            ],
        ]);
    }

    /** Profil du porteur du jeton. */
    public function me(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        return response()->json([
            'id' => $utilisateur?->getAuthIdentifier(),
            'phone' => $utilisateur instanceof User ? $utilisateur->phone : null,
            'full_name' => $utilisateur instanceof User ? $utilisateur->full_name : null,
            'kyc_status' => $utilisateur instanceof User ? $utilisateur->kyc_status : null,
        ]);
    }

    /** Ferme la seule session courante, sans toucher aux autres appareils. */
    public function logout(Request $request): JsonResponse
    {
        $jeton = $request->user()?->currentAccessToken();

        // Un jeton de session web (TransientToken) n'a rien à révoquer : seuls
        // les jetons personnels émis à un appareil sont persistés.
        if ($jeton instanceof PersonalAccessToken) {
            $jeton->delete();
        }

        return response()->json(['message' => 'Session fermée.']);
    }
}
