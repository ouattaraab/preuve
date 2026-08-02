<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\LifeStatus;
use App\Enums\NotificationType;
use App\Enums\TriggerType;
use App\Enums\TrustLevel;
use App\Exceptions\DoublonActifException;
use App\Models\Asset;
use App\Models\AssetStatusHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Enregistrement express d'un bien (ST-0201, ST-0203, ST-0204).
 *
 * Le parcours tient en quatre gestes et doit se boucler en moins de 90
 * secondes (CT-02) : aucun KYC n'est exigé ici, la friction n'apparaît qu'aux
 * étapes qui le méritent — renforcement de la fiabilité, transfert,
 * réclamation (CT-06). Le bien naît donc en « Déclaré, non vérifié » (F1) et
 * « Enregistrement récent » (V-PRV), avec une fenêtre de contestation de
 * 30 jours.
 *
 * Deux garde-fous portent la règle métier absolue n° 3 :
 *
 * 1. L'identifiant est normalisé AVANT tout contrôle. Sans cela,
 *    « 1m8gdm9axkp-042788 » et « 1M8GDM9AXKP042788 » seraient deux biens
 *    actifs pour un seul châssis, et l'unicité ne vaudrait plus rien.
 * 2. La collision est détectée deux fois : par une lecture préalable, qui
 *    donne un message utile, ET par l'index unique de la base, qui tranche la
 *    course entre deux enregistrements simultanés du même identifiant. La
 *    lecture seule ne suffirait pas — deux requêtes concurrentes la
 *    passeraient toutes les deux.
 *
 * Une tentative de doublon ne crée JAMAIS de bien : l'utilisateur est renvoyé
 * vers la fiche existante et le parcours de réclamation, et la tentative est
 * journalisée pour détecter les fraudes tôt (ST-0205).
 */
final class AssetRegistrationService
{
    /** Longueur de la partie aléatoire de la référence publique. */
    private const PUBLIC_REF_LENGTH = 8;

    /**
     * Alphabet sans les caractères confondables à l'oral ou à la lecture
     * (0/O, 1/I/L) : une référence publique est lue au téléphone et recopiée
     * à la main.
     */
    private const PUBLIC_REF_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const PUBLIC_REF_MAX_TRIES = 10;

    /** Durée de la fenêtre de contestation d'un enregistrement récent. */
    private const PROVISIONAL_DAYS = 30;

    public function __construct(
        private readonly CategoryRegistry $categories,
        private readonly IdentifierNormalizer $normalizer,
        private readonly AuditChain $auditChain,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Champs déclarés par la catégorie
     * @param  int|null  $clientElapsedMs  Chronomètre côté client, pour la
     *                                     télémétrie CT-02 (< 90 s au médian)
     *
     * @throws DoublonActifException si un enregistrement actif existe déjà
     * @throws RuntimeException si la catégorie ou l'identifiant est inexploitable
     */
    public function register(
        User $owner,
        string $categoryKey,
        array $attributes,
        ?int $companyId = null,
        ?int $clientElapsedMs = null,
    ): Asset {
        $this->assertCategoryIsPublished($categoryKey);

        $canonicalKey = $this->categories->canonicalFieldKey($categoryKey);
        $raw = $attributes[$canonicalKey] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            throw new RuntimeException(
                "L'identifiant « {$canonicalKey} » est obligatoire pour enregistrer un bien de la catégorie ".
                "« {$categoryKey} »."
            );
        }

        $normalized = $this->normalizer->normalize($raw);
        $type = $this->normalizer->detectType($raw);

        if ($normalized === '' || $type === null) {
            throw new RuntimeException(
                'Cet identifiant n\'est pas exploitable : il doit permettre de désigner le bien de façon unique.'
            );
        }

        $this->refuseIfActiveExists($normalized, $owner);

        try {
            return $this->createRegistration(
                $owner,
                $categoryKey,
                $attributes,
                $raw,
                $normalized,
                $type,
                $companyId,
                $clientElapsedMs,
            );
        } catch (QueryException $e) {
            // Course perdue : un autre enregistrement du même identifiant a
            // été validé entre la lecture préalable et l'insertion. C'est
            // l'index unique qui tranche, et c'est voulu — la lecture seule ne
            // sérialise rien.
            if (! $this->isActiveUniquenessViolation($e)) {
                throw $e;
            }

            $this->refuseIfActiveExists($normalized, $owner);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRegistration(
        User $owner,
        string $categoryKey,
        array $attributes,
        string $raw,
        string $normalized,
        string $type,
        ?int $companyId,
        ?int $clientElapsedMs,
    ): Asset {
        // Bien, première ligne d'historique et entrée d'audit dans une seule
        // transaction : un bien sans trace d'audit, ou l'inverse, laisserait
        // un trou dans la chaîne de responsabilité.
        return $this->auditChain->transaction(
            function () use (
                $owner,
                $categoryKey,
                $attributes,
                $raw,
                $normalized,
                $type,
                $companyId,
                $clientElapsedMs,
            ): array {
                $registeredAt = now();

                $asset = Asset::create([
                    'public_ref' => $this->generatePublicRef(),
                    'owner_user_id' => $owner->id,
                    'company_id' => $companyId,
                    'asset_category_key' => $categoryKey,
                    'identifier_type' => $type,
                    'identifier_raw' => $raw,
                    'identifier_normalized' => $normalized,
                    'active_flag' => 1,
                    'attributes' => $attributes,
                    'trust_level' => TrustLevel::Declared,
                    'life_status' => LifeStatus::Provisional,
                    'provisional_until' => $registeredAt->copy()->addDays(self::PROVISIONAL_DAYS),
                    'registered_at' => $registeredAt,
                ]);

                // Ligne d'ouverture de l'historique : pas de statut d'origine,
                // le bien vient d'exister. Elle n'emprunte pas
                // StatusTransitionService, dont la matrice ne décrit que des
                // transitions entre deux statuts existants.
                AssetStatusHistory::create([
                    'asset_id' => $asset->id,
                    'from_status' => null,
                    'to_status' => LifeStatus::Provisional,
                    'to_trust' => TrustLevel::Declared,
                    'trigger_type' => TriggerType::Owner,
                    'actor_user_id' => $owner->id,
                    'created_at' => $registeredAt->format('Y-m-d H:i:s'),
                ]);

                return [
                    'result' => $asset,
                    'actorType' => ActorType::User,
                    'actorId' => $owner->id,
                    'action' => 'asset.registered',
                    'entityType' => 'asset',
                    'entityId' => $asset->id,
                    // Ni l'identifiant du bien ni les champs saisis : audit_log
                    // est inaltérable, et l'identifiant reste lisible sur la
                    // ligne du bien, elle, effaçable le jour venu
                    // (Loi 2013-450, ST-0105).
                    'payload' => array_filter([
                        'category' => $categoryKey,
                        'identifier_type' => $type,
                        'trust_level' => TrustLevel::Declared->value,
                        'life_status' => LifeStatus::Provisional->value,
                        'client_elapsed_ms' => $clientElapsedMs,
                    ], fn (mixed $valeur): bool => $valeur !== null),
                ];
            }
        );
    }

    /** @throws DoublonActifException */
    private function refuseIfActiveExists(string $normalized, User $candidat): void
    {
        $existant = Asset::query()
            ->where('identifier_normalized', $normalized)
            ->whereNotNull('active_flag')
            ->first();

        if ($existant === null) {
            return;
        }

        $this->auditChain->append(
            ActorType::User,
            $candidat->id,
            'asset.duplicate_attempt',
            'asset',
            $existant->id,
            [
                'identifier_type' => $existant->identifier_type,
                'life_status' => $existant->life_status->value,
            ],
        );

        $this->alertHolder($existant, $candidat);

        throw new DoublonActifException($existant);
    }

    /**
     * Alerte le détenteur d'une tentative d'enregistrement de son identifiant
     * (ST-0205) : c'est le signal de fraude le plus précoce dont dispose la
     * plateforme — quelqu'un détient assez d'informations sur le bien pour en
     * recopier l'identifiant.
     *
     * Le détenteur n'apprend RIEN du candidat (règle métier absolue n° 4) : ni
     * son nom, ni son identifiant de compte. Une alerte nominative
     * transformerait chaque tentative honnête — un acheteur qui enregistre de
     * bonne foi le bien qu'il vient d'acquérir — en dénonciation.
     *
     * Le détenteur ne peut pas se taire sur ce type : il est critique.
     */
    private function alertHolder(Asset $existant, User $candidat): void
    {
        $detenteur = $existant->owner;

        // Un candidat qui retombe sur son propre bien n'a pas à s'alerter
        // lui-même : c'est le cas banal du double envoi depuis un réseau
        // instable (CT-05).
        if (! $detenteur instanceof User || $detenteur->id === $candidat->id) {
            return;
        }

        $this->notifications->notify(
            $detenteur,
            NotificationType::DuplicateAttempt,
            'Tentative d\'enregistrement de votre bien',
            'Quelqu\'un vient de tenter d\'enregistrer un bien portant le même identifiant que le vôtre. '.
            "Si vous n'avez pas vendu ce bien, restez vigilant : vérifiez qu'il est toujours en votre ".
            'possession.',
            $existant,
            ['identifier_type' => $existant->identifier_type],
        );
    }

    private function assertCategoryIsPublished(string $categoryKey): void
    {
        $publiee = $this->categories->published()->contains(
            fn ($categorie): bool => $categorie->key === $categoryKey
        );

        if (! $publiee) {
            throw new RuntimeException(
                "La catégorie « {$categoryKey} » n'est pas publiée : aucun bien ne peut y être enregistré."
            );
        }
    }

    /**
     * Référence publique opaque (PRV-XXXXXXXX). Elle circule sur les pages web
     * indexables : elle ne doit donc rien laisser deviner de l'identifiant réel
     * du bien, et encore moins de son propriétaire.
     */
    private function generatePublicRef(): string
    {
        for ($essai = 0; $essai < self::PUBLIC_REF_MAX_TRIES; $essai++) {
            $reference = 'PRV-';

            for ($i = 0; $i < self::PUBLIC_REF_LENGTH; $i++) {
                $reference .= self::PUBLIC_REF_ALPHABET[random_int(0, strlen(self::PUBLIC_REF_ALPHABET) - 1)];
            }

            if (! Asset::where('public_ref', $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException(
            'Impossible de tirer une référence publique libre : la collision répétée signale un tirage aléatoire '.
            'défaillant, pas un manque de place.'
        );
    }

    /**
     * Distingue la violation de l'unicité active des autres erreurs SQL : une
     * contrainte différente — clé étrangère, colonne trop courte — ne doit
     * jamais être présentée à l'utilisateur comme un doublon.
     */
    private function isActiveUniquenessViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'uq_assets_identifier_active');
    }
}
