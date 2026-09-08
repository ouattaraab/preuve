<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\Delivery\FcmPushTransport;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Configuration du push et suivi du coût SMS (ST-1003, ST-1004).
 *
 * Mêmes règles que les autres secrets : chiffré au repos, jamais renvoyé,
 * conservé s'il n'est pas resoumis.
 */
final class PushProviderController extends Controller
{
    private const MASQUE = '••••••••';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditChain $auditChain,
    ) {}

    public function show(): JsonResponse
    {
        $cle = $this->settings->get(FcmPushTransport::SERVER_KEY_SETTING);

        return response()->json([
            'provider' => 'fcm',
            'configured' => is_string($cle) && $cle !== '',
            'server_key' => is_string($cle) && $cle !== '' ? self::MASQUE : null,
            'registered_devices' => DB::table('device_tokens')->count(),
            // Coût SMS des trente derniers jours (ST-1004) : par TYPE d'alerte,
            // jamais par destinataire.
            'sms_cost_30d' => $this->smsCost(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate(['server_key' => ['sometimes', 'nullable', 'string', 'max:255']]);

        $administrateur = $request->user();
        $administrateurId = $administrateur instanceof User ? $administrateur->id : null;

        // La PRÉSENCE de la clé distingue « conserver » d'« effacer » :
        // ConvertEmptyStringsToNull rendrait sinon les deux cas
        // indistinguables.
        if ($request->has('server_key')) {
            $valeur = $request->string('server_key')->toString();

            if ($valeur === '') {
                $this->settings->forget(FcmPushTransport::SERVER_KEY_SETTING);
            } else {
                $this->settings->setSecret(
                    FcmPushTransport::SERVER_KEY_SETTING,
                    $valeur,
                    $administrateurId,
                );
            }

            $this->auditChain->append(
                ActorType::Agent,
                $administrateurId,
                'admin.push_provider_changed',
                'app_setting',
                0,
                ['cleared' => $valeur === ''],
            );
        }

        return response()->json(['message' => 'Configuration du push enregistrée.']);
    }

    /** @return list<array{type: string, sent: int, failed: int}> */
    private function smsCost(): array
    {
        $lignes = DB::table('sms_deliveries')
            ->select('notification_type', 'status', DB::raw('COUNT(*) as total'))
            ->where('created_at', '>=', now()->subDays(30)->format('Y-m-d H:i:s'))
            ->groupBy('notification_type', 'status')
            ->get();

        $parType = [];

        foreach ($lignes as $ligne) {
            $type = is_string($ligne->notification_type) ? $ligne->notification_type : 'inconnu';
            $parType[$type] ??= ['type' => $type, 'sent' => 0, 'failed' => 0];
            $cle = $ligne->status === 'sent' ? 'sent' : 'failed';
            $parType[$type][$cle] = is_numeric($ligne->total) ? (int) $ligne->total : 0;
        }

        return array_values($parType);
    }
}
