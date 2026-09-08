<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\AppSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * Réglages modifiables en exploitation, depuis le back-office.
 *
 * Deux natures de valeurs cohabitent, et une seule classe tranche entre elles :
 * les valeurs ordinaires sont stockées en JSON, les valeurs secrètes — clés
 * d'API de fournisseurs de SMS — sont chiffrées avec APP_KEY. Sans ce point
 * de passage unique, un cast automatique tenterait tôt ou tard de décoder un
 * chiffré comme du JSON.
 *
 * Un secret devenu indéchiffrable (APP_KEY tournée, ligne corrompue) est
 * restitué comme absent, jamais comme la chaîne chiffrée : mieux vaut un
 * fournisseur qui refuse de démarrer qu'un envoi d'identifiants illisibles à
 * une API tierce.
 *
 * Les lectures passent par un cache mémoire, à la durée d'une requête : le
 * fournisseur SMS est relu à chaque émission de code.
 */
final class SettingsRepository
{
    /** @var array<string, mixed> */
    private array $cache = [];

    private bool $cacheIsWarm = false;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->warmCache();

        return array_key_exists($key, $this->cache) ? $this->cache[$key] : $default;
    }

    public function set(string $key, mixed $value, ?int $updatedBy = null): void
    {
        $this->write($key, $this->encodeJson($value), false, $updatedBy);
    }

    /** Chiffre la valeur au repos. Réservé aux identifiants de services tiers. */
    public function setSecret(string $key, string $value, ?int $updatedBy = null): void
    {
        $this->write($key, Crypt::encryptString($value), true, $updatedBy);
    }

    public function forget(string $key): void
    {
        AppSetting::where('key', $key)->delete();
        $this->flush();
    }

    /** Force la relecture depuis la base au prochain accès. */
    public function fresh(): self
    {
        $this->flush();

        return $this;
    }

    /** Vrai si le réglage existe, quelle que soit sa valeur. */
    public function has(string $key): bool
    {
        $this->warmCache();

        return array_key_exists($key, $this->cache);
    }

    private function write(string $key, string $stored, bool $isSecret, ?int $updatedBy): void
    {
        AppSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'is_secret' => $isSecret, 'updated_by' => $updatedBy],
        );

        $this->flush();
    }

    private function warmCache(): void
    {
        if ($this->cacheIsWarm) {
            return;
        }

        $this->cache = [];

        foreach (AppSetting::all() as $setting) {
            $this->cache[$setting->key] = $this->decode($setting);
        }

        $this->cacheIsWarm = true;
    }

    private function flush(): void
    {
        $this->cache = [];
        $this->cacheIsWarm = false;
    }

    private function decode(AppSetting $setting): mixed
    {
        if ($setting->value === null) {
            return null;
        }

        if ($setting->is_secret) {
            try {
                return Crypt::decryptString($setting->value);
            } catch (DecryptException) {
                return null;
            }
        }

        try {
            return json_decode($setting->value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private function encodeJson(mixed $value): string
    {
        $encode = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encode === false ? 'null' : $encode;
    }
}
