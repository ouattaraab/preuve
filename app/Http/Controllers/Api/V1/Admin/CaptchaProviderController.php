<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\Captcha\TurnstileVerifier;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuration du défi anti-automate (Cloudflare Turnstile).
 *
 * Réglable en exploitation comme les autres fournisseurs : une clé se révoque,
 * un compte Cloudflare change de main, et rien de tout cela ne doit demander
 * une livraison.
 *
 * LA CLÉ PUBLIQUE EST RENDUE EN CLAIR, le secret jamais. La première n'ouvre
 * rien — le client ne peut pas afficher le widget sans elle — et la masquer
 * empêcherait de vérifier qu'elle correspond bien au site déclaré chez
 * Cloudflare, ce qu'un administrateur vient précisément faire ici.
 *
 * Le changement est journalisé : désactiver le défi rouvre le balayage du
 * registre à un automate, et cela ne doit pas pouvoir se faire sans trace.
 */
final class CaptchaProviderController extends Controller
{
    private const MASQUE = '••••••••';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditChain $auditChain,
    ) {}

    public function show(): JsonResponse
    {
        $cle = $this->settings->get(TurnstileVerifier::SITE_KEY_SETTING);
        $secret = $this->settings->get(TurnstileVerifier::SECRET_SETTING);

        return response()->json([
            'provider' => 'turnstile',
            'configured' => is_string($cle) && $cle !== '' && is_string($secret) && $secret !== '',
            'site_key' => is_string($cle) && $cle !== '' ? $cle : null,
            'secret_key' => is_string($secret) && $secret !== '' ? self::MASQUE : null,
            'grant_lookups' => config('preuve.captcha.grant_lookups'),
            'fallback' => 'Sans clés, le plafond horaire de consultation tient seul : un visiteur qui '.
                'l\'atteint est refusé pendant une heure, sans échappatoire. Le refus ne promet alors '.
                'aucun défi.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'site_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secret_key' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $administrateur = $request->user();
        $administrateurId = $administrateur instanceof User ? $administrateur->id : null;

        // La PRÉSENCE du champ distingue « effacer » de « ne pas toucher » :
        // ConvertEmptyStringsToNull rendrait sinon les deux indiscernables.
        foreach ([
            'site_key' => TurnstileVerifier::SITE_KEY_SETTING,
            'secret_key' => TurnstileVerifier::SECRET_SETTING,
        ] as $champ => $reglage) {
            if (! $request->has($champ)) {
                continue;
            }

            $valeur = $request->string($champ)->toString();

            if ($valeur === '') {
                $this->settings->forget($reglage);

                continue;
            }

            // Le secret est chiffré au repos ; la clé publique n'a pas à l'être.
            $champ === 'secret_key'
                ? $this->settings->setSecret($reglage, $valeur, $administrateurId)
                : $this->settings->set($reglage, $valeur, $administrateurId);
        }

        $this->auditChain->append(
            ActorType::Agent,
            $administrateurId,
            'admin.captcha_provider_changed',
            'app_setting',
            0,
            // Jamais les clés, seulement ce qui a été touché.
            ['fields' => array_values(array_filter([
                $request->has('site_key') ? 'site_key' : null,
                $request->has('secret_key') ? 'secret_key' : null,
            ]))],
        );

        return response()->json(['message' => 'Configuration du défi anti-automate enregistrée.']);
    }
}
