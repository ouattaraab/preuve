<?php

declare(strict_types=1);

namespace App\Services\Captcha;

use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Défi Cloudflare Turnstile.
 *
 * RETENU CONTRE reCAPTCHA pour une raison de fond : Turnstile ne dépose pas de
 * cookie publicitaire et ne rattache pas le visiteur à un profil marchand.
 * Faire passer par un régisseur publicitaire les gens qui vérifient un bien
 * avant de l'acheter contredirait la minimisation que la plateforme s'impose
 * partout ailleurs (Loi 2013-450) — d'autant qu'ils consultent précisément
 * SANS compte, donc en attendant de n'être identifiés par personne.
 *
 * L'ADRESSE DU VISITEUR N'EST PAS TRANSMISE. Le paramètre `remoteip` de
 * Cloudflare est facultatif : le renseigner livrerait à un tiers l'adresse
 * même que la plateforme refuse de stocker en clair. Sans lui, la vérification
 * reste valable — le jeton est déjà lié à la session du défi.
 *
 * ELLE ÉCHOUE FERMÉ. Une panne de Cloudflare rend `false`, jamais `true` :
 * l'inverse ferait du défi un obstacle qu'il suffit de faire tomber. Les
 * visiteurs concernés sont de toute façon déjà au-delà du plafond, donc déjà
 * bloqués — échouer fermé ne dégrade rien pour eux.
 */
final class TurnstileVerifier implements CaptchaVerifier
{
    public const SITE_KEY_SETTING = 'captcha.turnstile.site_key';

    public const SECRET_SETTING = 'captcha.turnstile.secret';

    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Court : la vérification se fait dans la requête de consultation, dont la
     * promesse est de rendre un verdict en moins d'une seconde (CT-01).
     */
    private const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly SettingsRepository $settings) {}

    public function isConfigured(): bool
    {
        return $this->siteKey() !== null && $this->secret() !== null;
    }

    public function siteKey(): ?string
    {
        $cle = $this->settings->get(self::SITE_KEY_SETTING);

        return is_string($cle) && $cle !== '' ? $cle : null;
    }

    public function verify(string $token): bool
    {
        $secret = $this->secret();

        if ($secret === null || $token === '') {
            return false;
        }

        try {
            $reponse = Http::timeout(self::TIMEOUT_SECONDS)
                ->asForm()
                ->post(self::ENDPOINT, [
                    'secret' => $secret,
                    'response' => $token,
                ]);
        } catch (Throwable $e) {
            // Ni le jeton ni le secret ne sont consignés : le message d'origine
            // peut reprendre le corps envoyé.
            Log::warning('Vérification Turnstile indisponible', ['exception' => $e::class]);

            return false;
        }

        if ($reponse->failed()) {
            Log::warning('Vérification Turnstile refusée', ['status' => $reponse->status()]);

            return false;
        }

        return $reponse->json('success') === true;
    }

    private function secret(): ?string
    {
        $secret = $this->settings->get(self::SECRET_SETTING);

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
