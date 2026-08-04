<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Settings\SettingsRepository;

/**
 * Version minimale exigée des applications mobiles (« forçage de mise à jour »).
 *
 * POURQUOI FORCER : une application distribuée par les magasins vit des mois
 * après sa publication sur les téléphones qui ne se mettent pas à jour. Le jour
 * où un défaut de sécurité, un changement de règle métier ou une correction de
 * normalisation d'identifiant est livré, les anciennes versions continuent
 * d'écrire dans le registre selon les anciennes règles. Le forçage est le seul
 * levier qui n'attende pas le bon vouloir de chacun.
 *
 * CE QUI N'EST JAMAIS BLOQUÉ : LA CONSULTATION. La règle métier absolue n° 1
 * dit qu'un verdict est gratuit, anonyme et sans compte ; elle ne dit pas
 * « sauf si votre téléphone est vieux ». Quelqu'un qui vérifie une moto sur un
 * marché avec une version dépassée doit obtenir son verdict. Le forçage ne
 * porte que sur les ÉCRITURES — enregistrer, transférer, réclamer — c'est-à-dire
 * exactement les gestes dont les règles ont pu changer.
 *
 * LE DÉFAUT EST « AUCUNE EXIGENCE ». Une version minimale mal saisie mettrait
 * hors service tout le parc installé d'un seul réglage : tant que rien n'est
 * réglé, rien ne bloque.
 */
final class AppRelease
{
    public const MINIMUM_SETTING = 'app.minimum_version';

    public const LATEST_SETTING = 'app.latest_version';

    public function __construct(private readonly SettingsRepository $settings) {}

    /** Version minimale exigée, ou null si aucune exigence n'est posée. */
    public function minimum(): ?string
    {
        $valeur = $this->settings->get(self::MINIMUM_SETTING);

        return is_string($valeur) && $valeur !== '' ? $valeur : null;
    }

    /** Dernière version publiée, à titre indicatif. */
    public function latest(): ?string
    {
        $valeur = $this->settings->get(self::LATEST_SETTING);

        return is_string($valeur) && $valeur !== '' ? $valeur : null;
    }

    public function setMinimum(?string $version, ?int $par = null): void
    {
        $this->settings->set(self::MINIMUM_SETTING, $version === null || $version === '' ? null : $version, $par);
    }

    public function setLatest(?string $version, ?int $par = null): void
    {
        $this->settings->set(self::LATEST_SETTING, $version === null || $version === '' ? null : $version, $par);
    }

    /**
     * Vrai si la version annoncée par le client est en deçà de l'exigence.
     *
     * UNE VERSION ILLISIBLE PASSE. Un client qui annonce « 1.4-beta » ou rien du
     * tout n'est pas présumé fautif : refuser l'écriture sur un en-tête qu'on
     * n'a pas su lire punirait un utilisateur pour un défaut de la plateforme.
     */
    public function isOutdated(?string $annoncee): bool
    {
        $minimum = $this->minimum();

        if ($minimum === null || $annoncee === null || $annoncee === '') {
            return false;
        }

        if (! $this->estLisible($annoncee) || ! $this->estLisible($minimum)) {
            return false;
        }

        return version_compare($this->normaliser($annoncee), $this->normaliser($minimum), '<');
    }

    /** Trois nombres séparés par des points, rien d'autre. */
    public function estLisible(string $version): bool
    {
        return preg_match('/^\d{1,4}(\.\d{1,4}){0,2}$/', $version) === 1;
    }

    /** « 1.4 » et « 1.4.0 » désignent la même version. */
    private function normaliser(string $version): string
    {
        $morceaux = array_pad(explode('.', $version), 3, '0');

        return implode('.', array_slice($morceaux, 0, 3));
    }
}
