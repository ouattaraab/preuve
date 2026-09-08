<?php

declare(strict_types=1);

namespace App\Services\Otp;

use App\Enums\OtpPurpose;
use App\Services\Settings\SettingsRepository;
use RuntimeException;

/**
 * Achemine le code par le fournisseur choisi dans l'espace administrateur.
 *
 * C'est cette classe qui est liée à OtpSender : le reste de l'application
 * ignore quel fournisseur est actif, et un changement de passerelle ne demande
 * ni livraison ni redéploiement.
 *
 * Un fournisseur inconnu fait échouer l'envoi plutôt que de retomber
 * silencieusement sur les journaux : un tel repli écrirait les codes en clair
 * dans un fichier de log de production. Tant qu'AUCUN fournisseur n'a été
 * choisi — l'état d'une installation neuve — les journaux servent de défaut,
 * mais LogOtpSender refuse de lui-même la production.
 */
final class ConfigurableOtpSender implements OtpSender
{
    public const PROVIDER_KEY = 'sms.provider';

    public const CONFIG_KEY = 'sms.config';

    public const DEFAULT_PROVIDER = 'log';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SmsProviderRegistry $registry,
    ) {}

    public function send(string $destination, string $code, OtpPurpose $purpose): void
    {
        $this->resolve()->send($destination, $code, $purpose);
    }

    /** Fournisseur actif, instancié avec sa configuration courante. */
    public function resolve(): OtpSender
    {
        $cle = $this->activeProviderKey();
        $classe = $this->registry->classFor($cle);

        return $classe === HttpOtpSender::class
            ? new HttpOtpSender($this->activeConfig())
            : new $classe;
    }

    /** Force la relecture des réglages au prochain envoi. */
    public function fresh(): self
    {
        $this->settings->fresh();

        return $this;
    }

    public function activeProviderKey(): string
    {
        $cle = $this->settings->get(self::PROVIDER_KEY, self::DEFAULT_PROVIDER);

        if (! is_string($cle) || ! $this->registry->exists($cle)) {
            throw new RuntimeException(
                'Le fournisseur SMS configuré est inconnu : aucun code ne peut être envoyé. '.
                "Choisissez un fournisseur valide dans l'espace administrateur."
            );
        }

        return $cle;
    }

    /**
     * Configuration du fournisseur actif, secrets déchiffrés compris.
     *
     * Chaque champ secret est stocké sous sa propre clé de réglage, chiffrée
     * au repos : les mêler au tableau JSON ordinaire les y laisserait en clair.
     *
     * @return array<string, mixed>
     */
    public function activeConfig(): array
    {
        $config = $this->settings->get(self::CONFIG_KEY, []);
        $config = is_array($config) ? $config : [];

        foreach ($this->registry->secretFieldsFor($this->activeProviderKey()) as $champ) {
            $secret = $this->settings->get(self::CONFIG_KEY.'.'.$champ);

            if (is_string($secret) && $secret !== '') {
                $config[$champ] = $secret;
            }
        }

        /** @var array<string, mixed> $config */
        return $config;
    }
}
