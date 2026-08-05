<?php

declare(strict_types=1);

namespace App\Services\Scan;

use App\Services\Kyc\MindeeIdentityReader;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lecture d'une carte grise ou d'une facture par Mindee (ST-0202).
 *
 * MÊME COMPTE, AUTRE PRODUIT : la clé d'API est celle du lecteur de pièces
 * d'identité, seule l'adresse du produit change. Dupliquer la clé laisserait
 * la moitié des extractions tomber le jour d'une rotation, sans que rien ne le
 * signale.
 *
 * Ce lecteur ne lève JAMAIS. Un scan raté n'est pas une erreur : c'est le cas
 * ordinaire d'une carte grise usée photographiée dans une cour, et la réponse
 * attendue est « saisissez-le vous-même », pas un écran d'erreur.
 *
 * L'image n'est ni conservée ni journalisée. Une carte grise porte le nom et
 * l'adresse du titulaire ; la garder « au cas où » constituerait un stock de
 * documents nominatifs sans propriétaire déclaré ni chemin de revue. Le
 * titulaire pourra la déposer comme justificatif APRÈS l'enregistrement, où
 * elle sera rattachée à un bien et examinée par un agent.
 */
final class MindeeDocumentReader implements DocumentReader
{
    public const ENDPOINT_SETTING = 'scan.mindee.endpoint';

    private const DEFAULT_ENDPOINT = 'https://api.mindee.net/v1/products/mindee/expense_receipts/v5/predict';

    /**
     * Court, et volontairement : le scan se fait pendant que l'utilisateur
     * attend, dans un parcours qui promet 90 secondes au total (CT-02). Passé
     * ce délai, la saisie manuelle est plus rapide que l'attente.
     */
    private const TIMEOUT_SECONDS = 15;

    /**
     * Champs où les produits Mindee déposent habituellement un identifiant de
     * véhicule. Ils sont lus en premier, avant le balayage du texte brut : un
     * champ nommé vaut mieux qu'une chaîne trouvée au hasard de la page.
     */
    private const IDENTIFIER_FIELDS = [
        'vin', 'chassis_number', 'vehicle_identification_number',
        'registration_number', 'license_plate', 'plate', 'serial_number', 'imei',
    ];

    /** @var array<string, string> */
    private const ATTRIBUTE_FIELDS = [
        'make' => 'make',
        'brand' => 'make',
        'model' => 'model',
        'first_registration' => 'first_registration',
        'registration_date' => 'first_registration',
        'color' => 'color',
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    public function isConfigured(): bool
    {
        return $this->cle() !== null;
    }

    /** La clé du fournisseur, ou nul si elle n'est pas renseignée. */
    private function cle(): ?string
    {
        $cle = $this->settings->get(MindeeIdentityReader::API_KEY_SETTING);

        return is_string($cle) && $cle !== '' ? $cle : null;
    }

    public function read(UploadedFile $image): ScanExtraction
    {
        $cle = $this->cle();

        if ($cle === null) {
            return ScanExtraction::unreadable();
        }

        $chemin = $image->getRealPath();

        if ($chemin === false) {
            return ScanExtraction::unreadable();
        }

        try {
            $reponse = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['Authorization' => 'Token '.$cle])
                ->attach('document', (string) file_get_contents($chemin), $image->getClientOriginalName())
                ->post($this->endpoint());
        } catch (Throwable $e) {
            // Seule la classe de l'erreur : le message peut contenir le corps
            // envoyé, donc l'image du document.
            Log::warning('Scan Mindee indisponible', ['exception' => $e::class]);

            return ScanExtraction::unreadable();
        }

        if ($reponse->failed()) {
            Log::warning('Scan Mindee refusé', ['status' => $reponse->status()]);

            return ScanExtraction::unreadable();
        }

        return $this->parse($reponse->json());
    }

    private function parse(mixed $corps): ScanExtraction
    {
        if (! is_array($corps)) {
            return ScanExtraction::unreadable();
        }

        $prediction = data_get($corps, 'document.inference.prediction');

        if (! is_array($prediction)) {
            return ScanExtraction::unreadable();
        }

        $candidats = [];

        foreach (self::IDENTIFIER_FIELDS as $champ) {
            $valeur = $this->champ($prediction, $champ);

            if ($valeur !== null) {
                $candidats[] = $valeur;
            }
        }

        // Repli sur le texte brut : les produits génériques ne nomment aucun
        // champ « châssis ». Ce balayage rapporte surtout du bruit, et il est
        // rendu à part — seul un identifiant à contrôle intégré (VIN, IMEI) ou
        // à format contraint (plaque) sera retenu à partir de cette source.
        $tokens = $this->tokensFromText($corps);

        $attributs = [];

        foreach (self::ATTRIBUTE_FIELDS as $source => $cible) {
            $valeur = $this->champ($prediction, $source);

            if ($valeur !== null && ! isset($attributs[$cible])) {
                $attributs[$cible] = $valeur;
            }
        }

        if ($candidats === [] && $tokens === [] && $attributs === []) {
            return ScanExtraction::unreadable();
        }

        return new ScanExtraction(
            candidates: array_values(array_unique($candidats)),
            tokens: $tokens,
            attributes: $attributs,
            confidence: $this->confiance($prediction),
        );
    }

    /**
     * Extrait du texte reconnu les suites alphanumériques assez longues pour
     * être un identifiant. Le filtrage sérieux vient ensuite.
     *
     * @return list<string>
     */
    private function tokensFromText(mixed $corps): array
    {
        $texte = data_get($corps, 'document.ocr.mvision-v1.pages.0.all_words');
        $mots = [];

        if (is_array($texte)) {
            foreach ($texte as $mot) {
                $valeur = is_array($mot) ? ($mot['text'] ?? null) : $mot;

                if (is_string($valeur)) {
                    $mots[] = $valeur;
                }
            }
        }

        $tokens = [];

        foreach ($mots as $mot) {
            $propre = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($mot)) ?? '';

            if (mb_strlen($propre) >= 6) {
                $tokens[] = $propre;
            }
        }

        return array_values(array_unique($tokens));
    }

    /** @param array<mixed, mixed> $prediction */
    private function champ(array $prediction, string $cle): ?string
    {
        $valeur = data_get($prediction, $cle.'.value') ?? data_get($prediction, $cle);

        return is_scalar($valeur) && (string) $valeur !== '' ? (string) $valeur : null;
    }

    /** @param array<mixed, mixed> $prediction */
    private function confiance(array $prediction): ?int
    {
        foreach (self::IDENTIFIER_FIELDS as $champ) {
            $confiance = data_get($prediction, $champ.'.confidence');

            if (is_numeric($confiance)) {
                return (int) round((float) $confiance * 100);
            }
        }

        return null;
    }

    private function endpoint(): string
    {
        $adresse = $this->settings->get(self::ENDPOINT_SETTING);

        return is_string($adresse) && $adresse !== '' ? $adresse : self::DEFAULT_ENDPOINT;
    }
}
