<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Services\Otp\SmsProviderRegistry;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Passerelle SMS générique pour les notifications.
 *
 * Réutilise la CONFIGURATION de la passerelle OTP — même adresse, même clé,
 * même gabarit de requête — parce que c'est le même contrat avec le même
 * agrégateur. Demander à un exploitant de configurer deux fois la même
 * passerelle serait une source d'erreur sans contrepartie.
 *
 * Seul le gabarit de message diffère : une notification porte un texte
 * complet, là où un code OTP est substitué dans une phrase type.
 */
final class HttpSmsGateway implements SmsGateway
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SmsProviderRegistry $registry,
    ) {}

    public function isConfigured(): bool
    {
        $fournisseur = $this->settings->get('sms.provider');

        if (! is_string($fournisseur) || ! $this->registry->exists($fournisseur) || $fournisseur === 'log') {
            return false;
        }

        return $this->endpoint() !== null && $this->template() !== null;
    }

    public function send(string $destination, string $message): void
    {
        $url = $this->endpoint();
        $gabarit = $this->template();

        if ($url === null || $gabarit === null) {
            throw new RuntimeException('Aucune passerelle SMS configurée pour les notifications.');
        }

        if (! str_starts_with($url, 'https://') && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('La passerelle SMS doit être jointe en HTTPS.');
        }

        $requete = Http::timeout(self::TIMEOUT_SECONDS)->asJson();
        $entete = $this->settings->get('sms.config.auth_header');

        if (is_string($entete) && $entete !== '') {
            $requete = $requete->withHeaders(['Authorization' => $entete]);
        }

        try {
            $reponse = $requete->post($url, $this->payload($gabarit, $destination, $message));
        } catch (Throwable $e) {
            // Le message d'origine peut contenir le corps envoyé : seule la
            // classe de l'erreur remonte.
            throw new RuntimeException('Passerelle SMS injoignable ('.$e::class.').');
        }

        if ($reponse->failed()) {
            throw new RuntimeException('Passerelle SMS en erreur (HTTP '.$reponse->status().').');
        }
    }

    /** @return array<string, mixed> */
    private function payload(string $gabarit, string $destination, string $message): array
    {
        $corps = str_replace(
            ['{{destination}}', '{{message}}'],
            [$this->fragment($destination), $this->fragment($message)],
            $gabarit,
        );

        try {
            $decode = json_decode($corps, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Le gabarit de la passerelle SMS ne produit pas un JSON valide.');
        }

        if (! is_array($decode)) {
            throw new RuntimeException('Le gabarit de la passerelle SMS doit produire un objet JSON.');
        }

        /** @var array<string, mixed> $decode */
        return $decode;
    }

    /** Valeur échappée puis débarrassée de ses guillemets englobants. */
    private function fragment(string $valeur): string
    {
        $encode = json_encode($valeur, JSON_UNESCAPED_UNICODE);

        return $encode === false ? '' : trim($encode, '"');
    }

    private function endpoint(): ?string
    {
        $config = $this->settings->get('sms.config');
        $url = is_array($config) ? ($config['endpoint_url'] ?? null) : null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function template(): ?string
    {
        $config = $this->settings->get('sms.config');
        $gabarit = is_array($config) ? ($config['payload_template'] ?? null) : null;

        return is_string($gabarit) && $gabarit !== '' ? $gabarit : null;
    }
}
