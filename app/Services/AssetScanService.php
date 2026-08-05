<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\ScanOutcome;
use App\Models\Asset;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\Scan\DocumentReader;
use App\Services\Scan\ScanExtraction;
use Illuminate\Http\UploadedFile;

/**
 * Pré-remplissage de l'identifiant par scan de carte grise ou de facture
 * (ST-0202).
 *
 * LE SCAN PROPOSE, IL N'ENREGISTRE JAMAIS. Aucun bien ne naît d'une extraction :
 * l'utilisateur voit ce qui a été lu, le corrige s'il le faut, et soumet. Un
 * enregistrement direct sur la foi d'un OCR créerait des biens au mauvais
 * châssis — donc des doublons impossibles à démêler, sur la règle même qui
 * fonde le registre (règle métier absolue n° 3).
 *
 * RIEN N'EST PROPOSÉ QUI NE SOIT VALIDE. Une extraction ne devient une
 * proposition qu'après être passée par `IdentifierNormalizer`, le même juge que
 * l'enregistrement. Proposer un VIN dont le chiffre de contrôle est faux
 * ferait recopier à l'utilisateur une erreur de lecture qu'il n'a aucun moyen
 * de repérer — dix-sept caractères se relisent mal.
 *
 * L'ÉCHEC EST UNE ISSUE NORMALE, PAS UNE ERREUR. Une carte grise pliée,
 * photographiée de nuit, tamponnée en travers du châssis : le cas ordinaire.
 * La réponse est « saisissez-le vous-même », jamais un écran d'erreur — la
 * saisie manuelle reste le chemin nominal, que le scan raccourcit quand il y
 * parvient.
 */
final class AssetScanService
{
    /**
     * Types acceptables lorsque la chaîne vient du balayage du texte de la
     * page. Tous portent un contrôle intégré (VIN, IMEI) ou un format contraint
     * (plaque) : sans cela, n'importe quel mot de six lettres passerait pour un
     * numéro de série.
     */
    private const TYPES_TRUSTED_FROM_TEXT = ['vin', 'imei', 'plate'];

    /** Un identifiant à chiffre de contrôle prime sur un simple format. */
    private const PRIORITY = ['vin' => 5, 'imei' => 4, 'plate' => 3, 'lot_number' => 2, 'serial' => 1];

    public function __construct(
        private readonly DocumentReader $reader,
        private readonly IdentifierNormalizer $normalizer,
    ) {}

    /**
     * Lit un document et rend une proposition.
     *
     * @param  User|null  $utilisateur  nul pour un scan de CONSULTATION, fait
     *                                  sans compte. La trace reste écrite —
     *                                  c'est elle qui mesure si le
     *                                  pré-remplissage sert — mais elle ne
     *                                  désigne personne.
     * @return array{scan: DocumentScan, identifier: array{value: string, type: string}|null, attributes: array<string, string>, confidence: int|null, existing: Asset|null, message: string}
     */
    public function scan(?User $utilisateur, DocumentType $type, UploadedFile $image): array
    {
        $debut = hrtime(true);
        $extraction = $this->reader->read($image);
        $duree = (int) round((hrtime(true) - $debut) / 1_000_000);

        return $this->retenir($utilisateur, $type, $extraction, $duree, 'mindee');
    }

    /**
     * Même sélection, mais à partir de mots DÉJÀ LUS SUR L'APPAREIL.
     *
     * POURQUOI CE CHEMIN EXISTE. La lecture d'une carte grise se fait
     * parfaitement sur le téléphone, et cela change trois choses qu'aucun
     * réglage de fournisseur ne pouvait changer :
     *
     * — L'IMAGE NE PART PAS. Une carte grise porte le nom et l'adresse de son
     *   propriétaire ; envoyer la photo entière à un tiers pour en extraire
     *   dix-sept caractères contredisait la minimisation appliquée partout
     *   ailleurs (Loi 2013-450). Ici ne circulent que des mots.
     * — CELA MARCHE SANS RÉSEAU, et l'envoi d'une photo de plusieurs
     *   mégaoctets en 3G disparaît du chemin (CT-05, et les 90 s de CT-02).
     * — CELA NE COÛTE RIEN, donc rien n'a à être plafonné : le plafond de
     *   dépense ne protégeait qu'une facture, et il n'y en a plus.
     *
     * LA SÉLECTION RESTE ICI, SUR LE SERVEUR, et c'est le point important : le
     * téléphone ne fait que lire. Le chiffre de contrôle du VIN, le Luhn de
     * l'IMEI, le format de plaque et l'ordre de priorité sont des règles
     * métier ; embarquées dans l'application, elles se périmeraient sur des
     * téléphones qui ne se mettent pas à jour.
     *
     * @param  list<string>  $mots  ce que l'appareil a lu, sans tri ni filtre
     * @return array{scan: DocumentScan, identifier: array{value: string, type: string}|null, attributes: array<string, string>, confidence: int|null, existing: Asset|null, message: string}
     */
    public function scanText(?User $utilisateur, DocumentType $type, array $mots): array
    {
        $debut = hrtime(true);

        // Traités comme du TEXTE BRUT et non comme des champs nommés : rien ne
        // dit à l'appareil lequel de ces mots est un châssis, et le prétendre
        // ferait accepter sans contrôle une chaîne quelconque. Seul un
        // identifiant à contrôle intégré ou à format contraint sera retenu.
        $extraction = new ScanExtraction(tokens: $mots);
        $duree = (int) round((hrtime(true) - $debut) / 1_000_000);

        return $this->retenir($utilisateur, $type, $extraction, $duree, 'device');
    }

    /**
     * @return array{scan: DocumentScan, identifier: array{value: string, type: string}|null, attributes: array<string, string>, confidence: int|null, existing: Asset|null, message: string}
     */
    private function retenir(
        ?User $utilisateur,
        DocumentType $type,
        ScanExtraction $extraction,
        int $duree,
        string $fournisseur,
    ): array {
        $retenu = $extraction->failed
            ? null
            : $this->bestCandidate($extraction->candidates, $extraction->tokens);

        $scan = DocumentScan::create([
            'user_id' => $utilisateur?->id,
            'doc_type' => $type->value,
            'provider' => $fournisseur,
            // Empreinte seule : voir la migration. Comparer suffit.
            'proposed_hash' => $retenu === null ? null : $this->fingerprint($retenu['value']),
            'proposed_type' => $retenu['type'] ?? null,
            'confidence' => $extraction->confidence,
            'duration_ms' => $duree,
            'outcome' => ScanOutcome::Pending,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        return [
            'scan' => $scan,
            'identifier' => $retenu,
            'attributes' => $extraction->attributes,
            'confidence' => $extraction->confidence,
            // Prévenir MAINTENANT qu'un enregistrement actif existe évite à
            // l'utilisateur de remplir un formulaire de 90 secondes pour se
            // heurter à un refus à la fin. Cela ne divulgue rien : la
            // consultation de statut est publique, gratuite et anonyme.
            'existing' => $retenu === null ? null : $this->activeAsset($retenu['value']),
            'message' => $retenu === null
                ? 'Le document n\'a pas pu être lu. Saisissez l\'identifiant vous-même : '.
                  'l\'enregistrement est identique.'
                : 'Vérifiez l\'identifiant lu avant de valider : une erreur de lecture se corrige ici, '.
                  'pas après l\'enregistrement.',
        ];
    }

    /**
     * Constate ce que l'utilisateur a réellement soumis (ST-0202, « taux de
     * pré-remplissage mesuré »).
     *
     * Appelé à la SOUMISSION, sans attendre l'issue de l'enregistrement : un
     * doublon refusé signifie que la lecture était bonne, et le compter comme
     * un échec de pré-remplissage fausserait la mesure dans le sens qui
     * arrange.
     */
    public function recordUse(?int $scanId, User $utilisateur, string $identifiantSoumis): void
    {
        if ($scanId === null) {
            return;
        }

        $scan = DocumentScan::query()
            ->whereKey($scanId)
            // Un scan appartient à celui qui l'a fait : sans ce filtre, un
            // client pourrait attribuer sa saisie au scan de quelqu'un d'autre
            // et brouiller la mesure.
            ->where('user_id', $utilisateur->id)
            ->where('outcome', ScanOutcome::Pending->value)
            ->first();

        if (! $scan instanceof DocumentScan) {
            return;
        }

        $soumis = $this->normalizer->normalize($identifiantSoumis);

        $scan->forceFill([
            'outcome' => $scan->proposed_hash !== null && hash_equals($scan->proposed_hash, $this->fingerprint($soumis))
                ? ScanOutcome::Accepted
                : ScanOutcome::Corrected,
            'used_at' => now(),
        ])->save();
    }

    /**
     * Meilleur candidat validé, ou null.
     *
     * @param  list<string>  $nommes
     * @param  list<string>  $tokens
     * @return array{value: string, type: string}|null
     */
    private function bestCandidate(array $nommes, array $tokens): ?array
    {
        $meilleur = null;
        $meilleurRang = 0;

        foreach ($nommes as $brut) {
            $meilleur = $this->challenge($brut, false, $meilleur, $meilleurRang);
            $meilleurRang = $meilleur === null ? 0 : self::PRIORITY[$meilleur['type']];
        }

        foreach ($tokens as $brut) {
            $meilleur = $this->challenge($brut, true, $meilleur, $meilleurRang);
            $meilleurRang = $meilleur === null ? 0 : self::PRIORITY[$meilleur['type']];
        }

        return $meilleur;
    }

    /**
     * @param  array{value: string, type: string}|null  $tenant
     * @return array{value: string, type: string}|null
     */
    private function challenge(string $brut, bool $depuisTexte, ?array $tenant, int $rangTenant): ?array
    {
        $normalise = $this->normalizer->normalize($brut);
        $type = $this->normalizer->detectType($normalise);

        if ($type === null || $normalise === '' || $this->isFailedChecksum($normalise)) {
            return $tenant;
        }

        if ($depuisTexte && ! in_array($type, self::TYPES_TRUSTED_FROM_TEXT, true)) {
            return $tenant;
        }

        return self::PRIORITY[$type] > $rangTenant ? ['value' => $normalise, 'type' => $type] : $tenant;
    }

    /**
     * Vrai si la chaîne a la FORME d'un identifiant à contrôle intégré mais en
     * échoue le contrôle.
     *
     * Sans cette barrière, un VIN dont l'OCR a manqué un caractère cesse d'être
     * un VIN, retombe dans le fourre-tout « numéro de série » — qui accepte
     * n'importe quelle chaîne d'au moins six caractères — et serait proposé
     * quand même. L'utilisateur enregistrerait alors un châssis inexistant,
     * sans aucun moyen de s'en apercevoir : personne ne relit dix-sept
     * caractères. Un contrôle qui échoue vaut refus, jamais rétrogradation.
     */
    private function isFailedChecksum(string $normalise): bool
    {
        if (mb_strlen($normalise) === 17 && ! $this->normalizer->isValidVin($normalise)) {
            return true;
        }

        return preg_match('/^\d{15}$/', $normalise) === 1 && ! $this->normalizer->isValidImei($normalise);
    }

    private function activeAsset(string $normalise): ?Asset
    {
        return Asset::query()
            ->where('identifier_normalized', $normalise)
            ->whereNotNull('active_flag')
            ->first();
    }

    /**
     * Empreinte de comparaison. Clavetée sur APP_KEY : sans clé, l'espace des
     * plaques ivoiriennes se balaie en quelques minutes et la table cesserait
     * de protéger quoi que ce soit.
     */
    private function fingerprint(string $normalise): string
    {
        $cle = config('app.key');

        return hash_hmac('sha256', $normalise, is_string($cle) ? $cle : '');
    }
}
