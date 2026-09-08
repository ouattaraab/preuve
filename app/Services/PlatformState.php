<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Services\Settings\SettingsRepository;

/**
 * Mode de fonctionnement dégradé de la plateforme (ST-0904).
 *
 * LE MODE LECTURE SEULE NE FERME JAMAIS LA CONSULTATION. C'est la promesse la
 * plus visible du produit : un acheteur au marché doit pouvoir vérifier un bien
 * même pendant une maintenance. Fermer la consultation pour migrer un schéma
 * reviendrait à ce que, ce jour-là, un vol passe inaperçu.
 *
 * Ce qui se ferme, ce sont les ÉCRITURES : enregistrer, transférer, réclamer.
 * Elles peuvent attendre une heure ; une vérification avant paiement, non.
 *
 * L'objectif de 99,5 % de disponibilité ne se tient pas en ne tombant jamais,
 * mais en choisissant ce qui tombe.
 */
final class PlatformState
{
    public const READ_ONLY_SETTING = 'platform.read_only';

    public const READ_ONLY_REASON_SETTING = 'platform.read_only_reason';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditChain $auditChain,
    ) {}

    public function isReadOnly(): bool
    {
        return $this->settings->get(self::READ_ONLY_SETTING) === true;
    }

    public function reason(): ?string
    {
        $motif = $this->settings->get(self::READ_ONLY_REASON_SETTING);

        return is_string($motif) && $motif !== '' ? $motif : null;
    }

    /**
     * Bascule le mode. Journalisé : couper les écritures de toute la
     * plateforme est une action d'exploitation majeure, et savoir qui l'a
     * décidée et quand fait partie de ce qu'un incident doit pouvoir établir.
     */
    public function setReadOnly(bool $actif, ?int $acteurId, ?string $motif = null): void
    {
        $this->auditChain->append(
            ActorType::Agent,
            $acteurId,
            $actif ? 'platform.read_only_enabled' : 'platform.read_only_disabled',
            'app_setting',
            0,
            ['reason' => $motif],
        );

        if (! $actif) {
            $this->settings->forget(self::READ_ONLY_SETTING);
            $this->settings->forget(self::READ_ONLY_REASON_SETTING);

            return;
        }

        $this->settings->set(self::READ_ONLY_SETTING, true, $acteurId);
        $this->settings->set(
            self::READ_ONLY_REASON_SETTING,
            $motif ?? 'Maintenance en cours.',
            $acteurId,
        );
    }

    /**
     * Message rendu à qui tente d'écrire pendant la maintenance. Dit ce qui
     * reste possible : un utilisateur qui ne peut pas enregistrer doit savoir
     * qu'il peut encore consulter, et que son bien reste protégé.
     */
    public function readOnlyMessage(): string
    {
        return ($this->reason() ?? 'Maintenance en cours.').
            ' Les enregistrements et modifications sont momentanément suspendus. '.
            'La consultation des biens reste ouverte, et vos biens restent protégés.';
    }
}
