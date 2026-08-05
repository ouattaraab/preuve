<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Services\AnonymousScanAllowance;
use App\Services\AssetScanService;
use App\Services\Captcha\CaptchaVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Lire le numéro sur une carte grise, SANS COMPTE, pour le vérifier ensuite.
 *
 * POURQUOI CETTE ROUTE EXISTE À PART. Le scan servait déjà à l'enregistrement,
 * où l'on est forcément connecté. Mais celui à qui l'on propose une moto sur un
 * parking n'a pas de compte, et c'est lui à qui recopier dix-sept caractères de
 * châssis coûte le plus — l'erreur de recopie est le premier motif de « bien
 * introuvable ». Exiger un compte ici démentirait la pastille « Gratuit · Sans
 * compte » au moment exact où elle compte (règle métier n° 1, CT-06).
 *
 * ELLE NE CONSULTE RIEN, ET C'EST DÉLIBÉRÉ. Le scan d'enregistrement rend
 * `existing_asset` pour éviter à quelqu'un de remplir un formulaire de quatre-
 * vingt-dix secondes avant d'apprendre que le bien existe déjà. Ici, ce champ
 * serait une consultation déguisée : elle échapperait au journal des
 * consultations — donc aux compteurs de trente jours, à l'alerte de pic, et au
 * plafond horaire lui-même. Le client obtient l'identifiant, le montre dans un
 * champ modifiable, et l'utilisateur lance la consultation comme n'importe qui.
 *
 * LA DÉPENSE EST BORNÉE À PART. Un scan appelle un fournisseur qui facture à
 * l'appel ; une consultation lit la base. Voir AnonymousScanAllowance.
 *
 * Répond TOUJOURS 200 sur un document illisible : c'est le résultat le plus
 * fréquent en conditions réelles, et un écran d'erreur détournerait de la
 * saisie manuelle, qui reste le chemin nominal.
 */
final class LookupScanController extends Controller
{
    /** Un scan ne peut porter que sur une pièce qui contient l'identifiant. */
    private const TYPES_SCANNABLES = [DocumentType::RegistrationCard, DocumentType::Invoice];

    public function __construct(
        private readonly AssetScanService $scans,
        private readonly AnonymousScanAllowance $budget,
        private readonly CaptchaVerifier $captcha,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $tailleMax = config('preuve.documents.max_kb');
        $tailleMax = is_numeric($tailleMax) ? (int) $tailleMax : 8192;

        $request->validate([
            'doc_type' => ['required', Rule::in(array_map(
                static fn (DocumentType $type): string => $type->value,
                self::TYPES_SCANNABLES,
            ))],
            'file' => ['required', 'file', 'max:'.$tailleMax, 'mimes:pdf,jpg,jpeg,png,heic'],
        ]);

        $empreinte = $this->empreinte($request->ip() ?? '');

        if ($this->budget->exceeded($empreinte)) {
            // Le jeton n'est vérifié QU'ICI : sur le chemin nominal, il
            // coûterait un aller-retour vers Cloudflare, et un jeton étant à
            // usage unique, le brûler sans nécessité obligerait le visiteur à
            // résoudre un défi qu'on ne lui a jamais demandé.
            $jeton = $this->jetonDeDefi($request);

            if (! $this->budget->grantAfterChallenge($empreinte, $jeton)) {
                return response()->json([
                    'scan_id' => null,
                    'identifier' => null,
                    'attributes' => [],
                    'confidence' => null,
                    'rate_limited' => true,
                    'captcha' => $this->captcha->isConfigured()
                        ? ['provider' => 'turnstile', 'site_key' => $this->captcha->siteKey()]
                        : null,
                    // LA SAISIE MANUELLE RESTE OUVERTE, et le message le dit :
                    // c'est le raccourci qui est plafonné, jamais la
                    // vérification elle-même.
                    'message' => 'Beaucoup de scans depuis cette connexion. '.
                        'Saisis le numéro à la main : la vérification, elle, reste libre.',
                ], 429);
            }
        }

        $fichier = $request->file('file');

        if (! $fichier instanceof UploadedFile) {
            abort(422);
        }

        // COMPTABILISÉ AVANT L'EXTRACTION, pas après : le fournisseur facture
        // dès l'appel, y compris si celui-ci échoue. Ne compter que les
        // succès laisserait un automate payer indéfiniment sans jamais
        // atteindre le plafond.
        $this->budget->record($empreinte);

        $resultat = $this->scans->scan(
            null,
            DocumentType::from($request->string('doc_type')->toString()),
            $fichier,
        );

        return response()->json([
            'scan_id' => $resultat['scan']->id,
            // `null` sans détour quand la lecture a échoué : pas de valeur
            // approchée, qui serait recopiée sans être vérifiée.
            'identifier' => $resultat['identifier'],
            'attributes' => $resultat['attributes'],
            'confidence' => $resultat['confidence'],
            'rate_limited' => false,
            'message' => $resultat['message'],
        ]);
    }

    /** La même empreinte que le plafond de consultation, salée du jour. */
    private function empreinte(string $ip): string
    {
        $cle = config('app.key');

        return hash_hmac('sha256', $ip, (is_string($cle) ? $cle : '').'|'.now()->format('Y-m-d'));
    }

    private function jetonDeDefi(Request $request): ?string
    {
        foreach ([$request->header('X-Captcha-Token'), $request->input('captcha_token')] as $valeur) {
            if (is_string($valeur) && $valeur !== '') {
                return $valeur;
            }
        }

        return null;
    }
}
