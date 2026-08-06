<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;
use App\Models\Lookup;
use App\Models\User;
use App\Services\Captcha\CaptchaVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
 *    étant déjà rattaché à une identité. Un défi anti-automate rouvre le
 *    passage à qui le résout — le plafond ne doit pas condamner un visiteur
 *    légitime derrière une adresse partagée, cas courant en Côte d'Ivoire où
 *    un cybercafé mutualise une seule adresse publique.
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

    /**
     * Consultations rendues par un défi résolu. Un défi ne vaut PAS
     * laissez-passer : sans ce plafond, un automate résoudrait un seul défi
     * puis balaierait le registre à loisir, et le défi n'aurait fait que
     * retarder d'une minute ce qu'il devait empêcher. Ce qui coûte à
     * l'attaquant, c'est de devoir en résoudre un tous les dix.
     */
    private const CAPTCHA_GRANT_FALLBACK = 10;

    /**
     * Combien de fois le seuil de défi avant le refus sec, faute de défi.
     *
     * Trente fois : à dix identifiants distincts par heure, cela porte le mur à
     * trois cents. Un acheteur qui compare des motos sur un parking en vérifie
     * cinq ; un automate qui balaie le registre en passe trois cents en
     * quelques minutes. L'écart entre les deux usages est de deux ordres de
     * grandeur, et c'est cet écart qu'on exploite plutôt qu'un seuil serré qui
     * frapperait les deux.
     */
    private const CEILING_FACTOR = 30;

    public function __construct(
        private readonly IdentifierNormalizer $normalizer,
        private readonly CaptchaVerifier $captcha,
    ) {}

    /**
     * @param  string|null  $captchaToken  jeton de défi, présenté seulement
     *                                     après un refus pour dépassement
     */
    public function lookup(
        string $identifier,
        string $ip,
        ?User $consultant = null,
        string $source = 'app',
        ?string $captchaToken = null,
    ): LookupResult {
        // Chronomètre serveur : CT-01 promet moins d'une seconde au 95e
        // centile, et une promesse non mesurée n'est qu'une intention.
        $debut = hrtime(true);

        $normalise = $this->normalizer->normalize($identifier);

        // Une saisie inexploitable ne consomme pas le quota et n'entre pas au
        // journal : c'est une faute de frappe, pas une tentative de balayage.
        if (mb_strlen($normalise) < self::MIN_IDENTIFIER_LENGTH) {
            return LookupResult::invalidIdentifier();
        }

        $empreinte = $this->hashIp($ip);

        if ($consultant === null && $this->hasExceededAllowance($empreinte)) {
            // Le jeton n'est vérifié QU'ICI : sur le chemin nominal, la
            // consultation ne doit pas payer un aller-retour vers Cloudflare —
            // CT-01 promet moins d'une seconde. Et un jeton Turnstile étant à
            // usage unique, le brûler sans nécessité obligerait le visiteur à
            // résoudre un défi qu'on ne lui a jamais demandé.
            if ($captchaToken === null || ! $this->grantAfterChallenge($empreinte, $captchaToken)) {
                return LookupResult::rateLimited($this->captcha->isConfigured());
            }
        }

        $bien = $this->findActive($normalise, $identifier);

        $this->journal($normalise, $bien, $empreinte, $consultant, $source, $debut);

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

    /**
     * Le plafond horaire, augmenté de ce qu'un défi résolu a rendu.
     *
     * Le comptage reste celui des consultations réellement journalisées :
     * l'octroi ne les efface pas, il déplace la barre. Un visiteur qui a
     * résolu un défi voit donc son quota reprendre normalement ensuite.
     */
    /**
     * Ce plafond arrête un BALAYAGE, pas un quartier.
     *
     * DEUX CORRECTIONS, TOUTES DEUX DICTÉES PAR LA CÔTE D'IVOIRE.
     *
     * 1. ON COMPTE LES IDENTIFIANTS DISTINCTS, pas les consultations. Ce que le
     *    plafond protège, c'est le registre contre son énumération : un
     *    automate demande mille numéros différents, un humain revérifie deux
     *    fois la même moto pendant qu'il négocie. Compter les requêtes brutes
     *    faisait payer la relecture — le geste le plus honnête du parcours.
     *
     * 2. LE MUR N'EST PLUS À DIX. Chez Orange, MTN et Moov, des milliers
     *    d'abonnés partagent quelques adresses publiques (CGNAT) : à dix par
     *    heure et par empreinte, la onzième personne d'un même opérateur se
     *    voyait refuser la promesse n° 1 du produit — sans avoir rien fait, et
     *    sans porte de sortie tant qu'aucun défi n'est configuré. Le seuil
     *    devient donc celui du DÉFI ; le refus sec n'intervient qu'à un
     *    plafond bien plus haut, qu'un humain n'atteint pas et qu'un automate
     *    franchit en quelques minutes.
     */
    private function hasExceededAllowance(string $empreinte): bool
    {
        $distincts = $this->distinctLookups($empreinte);
        $seuil = $this->anonymousHourlyLimit() + $this->granted($empreinte);

        if ($distincts < $seuil) {
            return false;
        }

        // Un défi est offert : on s'arrête ici, et le visiteur passe en le
        // résolvant. C'est le fonctionnement voulu.
        if ($this->captcha->isConfigured()) {
            return true;
        }

        // AUCUN DÉFI CONFIGURÉ. Refuser dès le seuil ferme le produit à toute
        // une population derrière une même adresse. On laisse passer jusqu'au
        // plafond dur, et on SIGNALE la pression : c'est le seul moyen pour
        // l'exploitant d'apprendre que le CGNAT mord, plutôt que de le
        // découvrir par des utilisateurs qui n'écrivent jamais.
        if ($distincts < $this->hardCeiling()) {
            return false;
        }

        Log::warning('Plafond de consultation atteint sans défi configuré', [
            // L'empreinte est déjà salée du jour : elle ne désigne personne, et
            // ne permet pas de suivre un visiteur d'un jour sur l'autre.
            'ip_hash' => $empreinte,
            'distinct_identifiers' => $distincts,
            'hint' => 'Renseigner les clés Turnstile, ou relever PREUVE_LOOKUP_CEILING.',
        ]);

        return true;
    }

    /**
     * Identifiants DISTINCTS consultés depuis cette empreinte dans l'heure.
     *
     * `count(distinct)` sur un index `(ip_hash, created_at)` reste une lecture
     * d'index : la mesure à volume — 300 000 consultations journalisées — donne
     * 2 ms au 95e centile, et cette requête ne change pas d'ordre de grandeur.
     */
    private function distinctLookups(string $empreinte): int
    {
        return Lookup::query()
            ->where('ip_hash', $empreinte)
            ->where('created_at', '>', now()->subHour())
            ->distinct()
            ->count('identifier_normalized');
    }

    /**
     * Le refus sec, quand aucun défi ne peut être proposé.
     *
     * Volontairement large : il ne vise pas à doser l'usage mais à arrêter
     * l'énumération. Quelqu'un qui vérifie trois cents biens différents en une
     * heure ne compare pas des motos sur un parking.
     */
    private function hardCeiling(): int
    {
        $plafond = config('preuve.lookup_rate_limit.anonymous_ceiling');
        $seuil = $this->anonymousHourlyLimit();

        // Jamais EN DESSOUS du seuil de défi — un refus plus strict que ce que
        // le seuil annonce serait incohérent. Mais ÉGAL est permis, et c'est un
        // réglage utile : il rétablit le refus sec dès le seuil, pour un
        // exploitant qui préfère la stricte ancienne règle.
        return is_numeric($plafond) && (int) $plafond >= $seuil
            ? (int) $plafond
            : $seuil * self::CEILING_FACTOR;
    }

    /**
     * Vérifie le défi et, s'il tient, rouvre le passage pour un nombre borné
     * de consultations.
     *
     * L'octroi est porté par l'EMPREINTE de l'adresse, jamais par l'adresse :
     * la même clé que le plafond, et donc la même protection (Loi 2013-450).
     */
    private function grantAfterChallenge(string $empreinte, string $token): bool
    {
        if (! $this->captcha->isConfigured() || ! $this->captcha->verify($token)) {
            return false;
        }

        Cache::put(
            $this->grantKey($empreinte),
            $this->granted($empreinte) + $this->captchaGrant(),
            now()->addHour(),
        );

        return true;
    }

    private function granted(string $empreinte): int
    {
        $octroi = Cache::get($this->grantKey($empreinte));

        return is_numeric($octroi) ? max(0, (int) $octroi) : 0;
    }

    private function grantKey(string $empreinte): string
    {
        return 'preuve.captcha.grant.'.$empreinte;
    }

    private function captchaGrant(): int
    {
        $octroi = config('preuve.captcha.grant_lookups');

        return is_numeric($octroi) && (int) $octroi > 0
            ? (int) $octroi
            : self::CAPTCHA_GRANT_FALLBACK;
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
        int $debut,
    ): void {
        // Plafonné à la capacité de la colonne : une valeur aberrante — une
        // requête restée bloquée une minute — ne doit pas faire échouer
        // l'écriture du journal, qui sert aussi au plafond anti-profilage.
        $duree = min(65535, (int) round((hrtime(true) - $debut) / 1_000_000));

        Lookup::create([
            'identifier_normalized' => $normalise,
            'found_asset_id' => $bien?->id,
            'ip_hash' => $empreinte,
            'user_id' => $consultant?->id,
            'source' => $source === 'web' ? 'web' : 'app',
            'duration_ms' => $duree,
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
