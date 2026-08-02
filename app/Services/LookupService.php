<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;
use App\Models\Lookup;
use App\Models\User;

/**
 * Consultation publique du statut d'un bien (EP-03).
 *
 * Règle métier absolue n° 1 : gratuite, anonyme, SANS compte. Aucune
 * authentification n'est requise ici, et aucune ne doit jamais l'être — c'est
 * la promesse même du produit : un acheteur au marché doit pouvoir vérifier un
 * bien avant de payer, sans rien installer ni créer.
 *
 * Trois protections encadrent cette ouverture :
 *
 * 1. L'adresse IP n'est jamais écrite en clair (Loi 2013-450). L'empreinte est
 *    salée par jour, ce qui empêche de recouper les consultations d'un même
 *    visiteur d'une journée sur l'autre — y compris depuis l'intérieur de la
 *    plateforme.
 * 2. Le rythme est plafonné pour les visiteurs anonymes (§8) : sans cela, un
 *    concessionnaire pourrait cartographier le parc entier en balayant les
 *    identifiants. Les comptes authentifiés ne sont pas limités, leur usage
 *    étant déjà rattaché à une identité.
 * 3. Le verdict ne dit rien du détenteur (règle n° 4), et pas davantage de
 *    l'identifiant complet du bien.
 *
 * Le comptage s'appuie sur la table `lookups` et son index dédié, et non sur
 * Redis : l'hébergement cible est un mutualisé sans Redis. La journalisation
 * étant de toute façon obligatoire (ST-0304), compter les mêmes lignes évite
 * un second magasin à maintenir cohérent.
 */
final class LookupService
{
    /**
     * Plafond de repli si la configuration est absente ou aberrante. La valeur
     * qui fait foi est `preuve.lookup_rate_limit.anonymous_per_hour`, verrouillée
     * par ConfigurationMutualiseeTest.
     */
    private const ANONYMOUS_HOURLY_LIMIT_FALLBACK = 10;

    /** Longueur en deçà de laquelle une saisie ne peut désigner aucun bien. */
    private const MIN_IDENTIFIER_LENGTH = 6;

    public function __construct(private readonly IdentifierNormalizer $normalizer) {}

    public function lookup(string $identifier, string $ip, ?User $consultant = null, string $source = 'app'): LookupResult
    {
        $normalise = $this->normalizer->normalize($identifier);

        // Une saisie inexploitable ne consomme pas le quota et n'entre pas au
        // journal : c'est une faute de frappe, pas une tentative de balayage.
        if (mb_strlen($normalise) < self::MIN_IDENTIFIER_LENGTH) {
            return LookupResult::invalidIdentifier();
        }

        $empreinte = $this->hashIp($ip);

        if ($consultant === null && $this->hasExceededHourlyLimit($empreinte)) {
            return LookupResult::rateLimited();
        }

        $bien = $this->findActive($normalise, $identifier);

        $this->journal($normalise, $bien, $empreinte, $consultant, $source);

        return $bien === null ? LookupResult::unknown() : LookupResult::found($bien);
    }

    /**
     * Seul l'enregistrement ACTIF fait foi : un ancien détenteur ne doit pas
     * pouvoir faire remonter sa fiche archivée.
     *
     * La référence publique est acceptée en plus de l'identifiant réel : c'est
     * elle qui figure sur les pages web indexables (ST-0306), et un visiteur
     * arrivant par un moteur de recherche doit retrouver le même verdict.
     */
    private function findActive(string $normalise, string $saisie): ?Asset
    {
        $bien = Asset::query()
            ->where('identifier_normalized', $normalise)
            ->whereNotNull('active_flag')
            ->first();

        if ($bien !== null) {
            return $bien;
        }

        $reference = mb_strtoupper(trim($saisie));

        if (! str_starts_with($reference, 'PRV-')) {
            return null;
        }

        return Asset::query()
            ->where('public_ref', $reference)
            ->whereNotNull('active_flag')
            ->first();
    }

    private function hasExceededHourlyLimit(string $empreinte): bool
    {
        $consultations = Lookup::query()
            ->where('ip_hash', $empreinte)
            ->where('created_at', '>', now()->subHour())
            ->count();

        return $consultations >= $this->anonymousHourlyLimit();
    }

    private function anonymousHourlyLimit(): int
    {
        $plafond = config('preuve.lookup_rate_limit.anonymous_per_hour');

        return is_numeric($plafond) && (int) $plafond > 0
            ? (int) $plafond
            : self::ANONYMOUS_HOURLY_LIMIT_FALLBACK;
    }

    private function journal(
        string $normalise,
        ?Asset $bien,
        string $empreinte,
        ?User $consultant,
        string $source,
    ): void {
        Lookup::create([
            'identifier_normalized' => $normalise,
            'found_asset_id' => $bien?->id,
            'ip_hash' => $empreinte,
            'user_id' => $consultant?->id,
            'source' => $source === 'web' ? 'web' : 'app',
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Empreinte salée de l'adresse. Le sel change chaque jour, ce qui rend le
     * journal inexploitable pour suivre un visiteur dans la durée.
     *
     * La clé du HMAC est APP_KEY, qui vit hors de la base : un dump de la
     * seule base ne permet donc pas de retrouver les adresses. Qui détient
     * APP_KEY, en revanche, peut balayer l'espace IPv4 en quelques heures —
     * c'est la limite de tout hachage d'adresse, et la raison pour laquelle
     * ces lignes sont purgées au-delà de 12 mois (politique ARTCI) plutôt que
     * conservées indéfiniment.
     */
    private function hashIp(string $ip): string
    {
        $cle = config('app.key');

        return hash_hmac(
            'sha256',
            $ip,
            (is_string($cle) ? $cle : '').'|'.now()->format('Y-m-d'),
        );
    }
}
