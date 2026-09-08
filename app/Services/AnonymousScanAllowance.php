<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Captcha\CaptchaVerifier;
use Illuminate\Support\Facades\Cache;

/**
 * Ce qu'un visiteur sans compte peut scanner en une heure.
 *
 * POURQUOI UN PLAFOND SÉPARÉ DE CELUI DES CONSULTATIONS. Une consultation est
 * une lecture de base : elle coûte deux millisecondes. Un scan appelle un
 * fournisseur d'extraction qui FACTURE À L'APPEL. Compter les deux ensemble
 * ferait qu'un après-midi de vérifications au marché épuiserait le budget
 * d'extraction de toute la plateforme — ou, dans l'autre sens, qu'un plafond
 * assez bas pour protéger la dépense étranglerait la consultation, qui est la
 * promesse du produit.
 *
 * L'EMPREINTE EST CELLE DE LA CONSULTATION, salée du jour (Loi 2013-450) :
 * aucune adresse n'est conservée, ici pas même sous forme de ligne — le
 * décompte vit en cache et expire de lui-même.
 *
 * ÉCHOUE FERMÉ, comme le plafond de consultation, mais avec la même porte de
 * sortie : un défi anti-robot résolu rouvre un nombre BORNÉ de scans. Un défi
 * ne vaut pas laissez-passer, sans quoi un automate en résoudrait un seul puis
 * viderait le budget.
 */
final class AnonymousScanAllowance
{
    /** Utilisé quand la configuration ne dit rien. Volontairement bas. */
    private const HOURLY_FALLBACK = 5;

    /** Ce qu'un défi résolu rouvre. Plus étroit que pour la consultation. */
    private const GRANT_FALLBACK = 5;

    public function __construct(private readonly CaptchaVerifier $captcha) {}

    /** Vrai quand ce visiteur a épuisé ce à quoi il a droit. */
    public function exceeded(string $empreinte): bool
    {
        return $this->consommes($empreinte) >= $this->plafondHoraire() + $this->octroyes($empreinte);
    }

    /**
     * Comptabilise un scan effectué.
     *
     * APPELÉ APRÈS L'EXTRACTION, ET QUEL QUE SOIT SON RÉSULTAT : le fournisseur
     * facture aussi les documents illisibles. Ne compter que les lectures
     * réussies laisserait un automate envoyer des images vides sans jamais
     * atteindre le plafond, en payant à chaque fois.
     */
    public function record(string $empreinte): void
    {
        Cache::put($this->cleCompte($empreinte), $this->consommes($empreinte) + 1, now()->addHour());
    }

    /**
     * Vérifie un défi et, s'il tient, rouvre le passage.
     *
     * Rend faux quand aucun défi n'est configuré : il n'y a alors pas de
     * sortie, et l'appelant doit le dire au lieu de proposer un bouton mort.
     */
    public function grantAfterChallenge(string $empreinte, ?string $token): bool
    {
        if ($token === null || ! $this->captcha->isConfigured() || ! $this->captcha->verify($token)) {
            return false;
        }

        Cache::put(
            $this->cleOctroi($empreinte),
            $this->octroyes($empreinte) + $this->octroiParDefi(),
            now()->addHour(),
        );

        return true;
    }

    private function consommes(string $empreinte): int
    {
        return $this->entierPositif(Cache::get($this->cleCompte($empreinte)));
    }

    private function octroyes(string $empreinte): int
    {
        return $this->entierPositif(Cache::get($this->cleOctroi($empreinte)));
    }

    private function cleCompte(string $empreinte): string
    {
        return 'preuve.scan.count.'.$empreinte;
    }

    private function cleOctroi(string $empreinte): string
    {
        return 'preuve.scan.grant.'.$empreinte;
    }

    private function plafondHoraire(): int
    {
        return $this->reglage('preuve.scan_rate_limit.anonymous_per_hour', self::HOURLY_FALLBACK);
    }

    private function octroiParDefi(): int
    {
        return $this->reglage('preuve.scan_rate_limit.captcha_grant', self::GRANT_FALLBACK);
    }

    /**
     * ZÉRO EST UNE VALEUR LÉGITIME, PAS UN RÉGLAGE ABSENT.
     *
     * Un exploitant qui met 0 coupe la dépense — budget d'extraction épuisé,
     * fournisseur en panne, facture qui dérape. Retomber sur la valeur par
     * défaut ferait reprendre les appels payants qu'il vient précisément
     * d'arrêter, et il n'aurait aucun moyen de s'en apercevoir avant la
     * facture. Le repli ne joue que si le réglage est ABSENT ou aberrant.
     */
    private function reglage(string $cle, int $defaut): int
    {
        $valeur = config($cle);

        return is_numeric($valeur) && (int) $valeur >= 0 ? (int) $valeur : $defaut;
    }

    private function entierPositif(mixed $valeur): int
    {
        return is_numeric($valeur) ? max(0, (int) $valeur) : 0;
    }
}
