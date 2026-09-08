<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Notifications push par Firebase Cloud Messaging (ST-1003).
 *
 * La clé serveur se configure depuis l'espace administrateur, comme les autres
 * secrets de la plateforme.
 *
 * LE CONTENU DU PUSH EST VOLONTAIREMENT PAUVRE. Une notification s'affiche sur
 * un écran verrouillé, parfois sous les yeux d'un tiers — ou du voleur, si le
 * téléphone lui-même a été pris. Elle dit qu'il se passe quelque chose et
 * invite à ouvrir l'application ; le détail reste derrière l'authentification.
 */
final class FcmPushTransport implements PushTransport
{
    public const SERVER_KEY_SETTING = 'push.fcm.server_key';

    public const ENDPOINT_SETTING = 'push.fcm.endpoint';

    private const DEFAULT_ENDPOINT = 'https://fcm.googleapis.com/fcm/send';

    private const TIMEOUT_SECONDS = 10;

    public function __construct(private readonly SettingsRepository $settings) {}

    public function isConfigured(): bool
    {
        $cle = $this->settings->get(self::SERVER_KEY_SETTING);

        return is_string($cle) && $cle !== '';
    }

    /** @param array<string, mixed> $donnees */
    public function send(User $destinataire, string $titre, string $corps, array $donnees = []): void
    {
        $cle = $this->settings->get(self::SERVER_KEY_SETTING);

        if (! is_string($cle) || $cle === '') {
            throw new RuntimeException('Aucune clé FCM configurée.');
        }

        $jetons = $this->tokensOf($destinataire);

        if ($jetons === []) {
            // Pas d'appareil enregistré : ce n'est pas une erreur. C'est le cas
            // majoritaire au démarrage, et le SMS prend le relais sur le
            // critique.
            return;
        }

        try {
            $reponse = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['Authorization' => 'key='.$cle])
                ->post($this->endpoint(), [
                    'registration_ids' => $jetons,
                    'notification' => ['title' => $titre, 'body' => $corps],
                    'data' => $donnees,
                ]);
        } catch (Throwable $e) {
            throw new RuntimeException('FCM injoignable ('.$e::class.').');
        }

        if ($reponse->failed()) {
            throw new RuntimeException('FCM en erreur (HTTP '.$reponse->status().').');
        }
    }

    /** @return list<string> */
    private function tokensOf(User $destinataire): array
    {
        $jetons = [];

        $lignes = DB::table('device_tokens')
            ->where('user_id', $destinataire->id)
            ->pluck('token');

        foreach ($lignes as $jeton) {
            if (is_string($jeton) && $jeton !== '') {
                $jetons[] = $jeton;
            }
        }

        return $jetons;
    }

    private function endpoint(): string
    {
        $adresse = $this->settings->get(self::ENDPOINT_SETTING);

        return is_string($adresse) && $adresse !== '' ? $adresse : self::DEFAULT_ENDPOINT;
    }
}
