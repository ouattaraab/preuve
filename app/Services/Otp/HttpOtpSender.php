<?php

declare(strict_types=1);

namespace App\Services\Otp;

use App\Enums\OtpPurpose;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Achemine le code par une passerelle HTTP quelconque, décrite par un gabarit
 * configuré depuis l'espace administrateur.
 *
 * Aucun agrégateur n'est codé en dur : le corps de la requête est un gabarit
 * JSON où {{destination}} et {{message}} sont substitués. Les trois passerelles
 * du marché ivoirien s'y ramènent, et un changement de contrat se règle par
 * une modification de réglage plutôt que par une livraison.
 *
 * Trois refus délibérés :
 * - une adresse non chiffrée hors développement : le code transiterait en
 *   clair, alors que c'est le secret même que l'OTP protège ;
 * - une réponse d'erreur du fournisseur : un envoi silencieusement perdu
 *   laisserait l'utilisateur attendre un SMS qui n'arrivera jamais ;
 * - un gabarit invalide ou incomplet : mieux vaut échouer à la configuration
 *   qu'au premier utilisateur réel.
 *
 * Le code n'apparaît dans aucun message d'erreur ni journal de transport : la
 * réponse du fournisseur est rapportée, jamais la requête envoyée.
 */
final class HttpOtpSender implements OtpSender
{
    /**
     * Court volontairement : l'envoi est synchrone dans la requête d'émission
     * du code. Une passerelle lente ne doit pas retenir la requête HTTP de
     * l'utilisateur jusqu'à la limite de temps du mutualisé.
     */
    private const TIMEOUT_SECONDS = 10;

    private const DEFAULT_MESSAGE_TEMPLATE = 'Votre code PREUVE est {{code}}. Il expire dans 5 minutes.';

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function send(string $destination, string $code, OtpPurpose $purpose): void
    {
        $url = $this->requiredString('endpoint_url');
        $gabaritCorps = $this->requiredString('payload_template');

        $this->assertUrlIsEncrypted($url);

        $message = str_replace(
            '{{code}}',
            $code,
            $this->optionalString('message_template') ?? self::DEFAULT_MESSAGE_TEMPLATE,
        );

        $corps = $this->buildPayload($gabaritCorps, $destination, $message);
        $methode = strtoupper($this->optionalString('http_method') ?? 'POST');

        $requete = Http::timeout(self::TIMEOUT_SECONDS)->asJson();

        $enTeteAuth = $this->optionalString('auth_header');

        if ($enTeteAuth !== null) {
            $requete = $requete->withHeaders(['Authorization' => $enTeteAuth]);
        }

        try {
            $reponse = $requete->send($methode, $url, ['json' => $corps]);
        } catch (Throwable $e) {
            // Le message de l'exception d'origine peut contenir le corps de la
            // requête, donc le code : il n'est jamais propagé tel quel.
            throw new RuntimeException(
                "La passerelle SMS n'a pas pu être jointe (".$e::class.'). Le code n\'a pas été envoyé.'
            );
        }

        if ($reponse->failed()) {
            throw new RuntimeException(sprintf(
                'La passerelle SMS a refusé l\'envoi (HTTP %d). Le code n\'a pas été envoyé.',
                $reponse->status(),
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(string $gabarit, string $destination, string $message): array
    {
        // La substitution passe par les valeurs encodées en JSON : un message
        // contenant un guillemet casserait sinon le gabarit, et un
        // destinataire soufflé pourrait y injecter des champs arbitraires.
        $corps = str_replace(
            ['{{destination}}', '{{message}}'],
            [$this->jsonFragment($destination), $this->jsonFragment($message)],
            $gabarit,
        );

        try {
            $decode = json_decode($corps, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(
                'Le gabarit du corps de la requête ne produit pas un JSON valide. '.
                'Corrigez-le dans la configuration du fournisseur SMS.'
            );
        }

        if (! is_array($decode)) {
            throw new RuntimeException(
                'Le gabarit du corps de la requête doit produire un objet JSON.'
            );
        }

        /** @var array<string, mixed> $decode */
        return $decode;
    }

    /** Valeur échappée puis débarrassée de ses guillemets englobants. */
    private function jsonFragment(string $valeur): string
    {
        $encode = json_encode($valeur, JSON_UNESCAPED_UNICODE);

        return $encode === false ? '' : trim($encode, '"');
    }

    private function assertUrlIsEncrypted(string $url): void
    {
        if (str_starts_with($url, 'https://')) {
            return;
        }

        if (app()->environment(['local', 'testing'])) {
            return;
        }

        throw new RuntimeException(
            'La passerelle SMS doit être jointe en HTTPS : en clair, le code transiterait en clair sur le '.
            "réseau, alors que c'est précisément le secret qu'il protège."
        );
    }

    private function requiredString(string $cle): string
    {
        $valeur = $this->optionalString($cle);

        if ($valeur === null) {
            throw new RuntimeException(
                "La configuration du fournisseur SMS est incomplète : « {$cle} » est obligatoire."
            );
        }

        return $valeur;
    }

    private function optionalString(string $cle): ?string
    {
        $valeur = $this->config[$cle] ?? null;

        return is_string($valeur) && trim($valeur) !== '' ? $valeur : null;
    }
}
