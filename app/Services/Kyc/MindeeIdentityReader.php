<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\Services\Settings\SettingsRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Extraction par l'API Mindee.
 *
 * La clé d'API se configure depuis l'espace administrateur, comme la passerelle
 * SMS : elle expire, se révoque et change de plan tarifaire — autant
 * d'événements d'exploitation qui ne doivent pas demander une livraison.
 *
 * Ce lecteur ne lève JAMAIS : une panne de Mindee, un quota épuisé ou une photo
 * illisible rendent une extraction vide, et le dossier part en revue manuelle.
 * Faire échouer la soumission reviendrait à suspendre les vérifications
 * d'identité de toute la plateforme parce qu'un tiers est indisponible.
 *
 * Rien de ce qui est extrait n'est journalisé : la réponse contient le numéro
 * de pièce, que même les journaux applicatifs ne doivent pas voir.
 */
final class MindeeIdentityReader implements IdentityReader
{
    public const API_KEY_SETTING = 'kyc.mindee.api_key';

    public const ENDPOINT_SETTING = 'kyc.mindee.endpoint';

    private const DEFAULT_ENDPOINT = 'https://api.mindee.net/v1/products/mindee/international_id/v2/predict';

    /**
     * Court : l'extraction se fait dans la requête de soumission, et un
     * utilisateur en 3G ne doit pas attendre un tiers indéfiniment (CT-05).
     */
    private const TIMEOUT_SECONDS = 20;

    public function __construct(private readonly SettingsRepository $settings) {}

    public function read(UploadedFile $recto, UploadedFile $verso): IdentityExtraction
    {
        $cle = $this->settings->get(self::API_KEY_SETTING);

        if (! is_string($cle) || $cle === '') {
            // Aucune clé configurée : l'extraction n'est pas une erreur, elle
            // n'a simplement pas lieu. La revue manuelle reste possible.
            return IdentityExtraction::unreadable();
        }

        try {
            $reponse = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['Authorization' => 'Token '.$cle])
                ->attach('document', (string) file_get_contents($recto->getRealPath()), $recto->getClientOriginalName())
                ->post($this->endpoint());
        } catch (Throwable $e) {
            // Le message d'origine peut contenir le corps envoyé, donc l'image :
            // seule la classe de l'erreur est consignée.
            Log::warning('Extraction Mindee indisponible', ['exception' => $e::class]);

            return IdentityExtraction::unreadable();
        }

        if ($reponse->failed()) {
            Log::warning('Extraction Mindee refusée', ['status' => $reponse->status()]);

            return IdentityExtraction::unreadable();
        }

        return $this->parse($reponse->json());
    }

    private function parse(mixed $corps): IdentityExtraction
    {
        if (! is_array($corps)) {
            return IdentityExtraction::unreadable();
        }

        $champs = data_get($corps, 'document.inference.prediction');

        if (! is_array($champs)) {
            return IdentityExtraction::unreadable();
        }

        return new IdentityExtraction(
            documentNumber: $this->champ($champs, 'document_number'),
            fullName: $this->nomComplet($champs),
            birthDate: $this->champ($champs, 'birth_date'),
            expiryDate: $this->champ($champs, 'expiry_date'),
            confidence: $this->confiance($champs),
        );
    }

    /** @param array<mixed, mixed> $champs */
    private function champ(array $champs, string $cle): ?string
    {
        $valeur = data_get($champs, $cle.'.value');

        return is_scalar($valeur) && (string) $valeur !== '' ? (string) $valeur : null;
    }

    /** @param array<mixed, mixed> $champs */
    private function nomComplet(array $champs): ?string
    {
        $prenom = $this->champ($champs, 'given_names.0') ?? $this->champ($champs, 'given_names');
        $nom = $this->champ($champs, 'surnames.0') ?? $this->champ($champs, 'surnames');

        $complet = trim(($prenom ?? '').' '.($nom ?? ''));

        return $complet === '' ? null : $complet;
    }

    /** @param array<mixed, mixed> $champs */
    private function confiance(array $champs): ?int
    {
        $confiance = data_get($champs, 'document_number.confidence');

        return is_numeric($confiance) ? (int) round((float) $confiance * 100) : null;
    }

    private function endpoint(): string
    {
        $adresse = $this->settings->get(self::ENDPOINT_SETTING);

        return is_string($adresse) && $adresse !== '' ? $adresse : self::DEFAULT_ENDPOINT;
    }
}
