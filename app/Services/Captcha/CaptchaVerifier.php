<?php

declare(strict_types=1);

namespace App\Services\Captcha;

/**
 * Vérification d'un défi anti-automate.
 *
 * Le contrat existe pour la même raison que celui du fournisseur SMS : le choix
 * du prestataire ne doit pas se figer dans le code d'un service métier. Il est
 * d'autant plus important ici que ce prestataire voit passer une part du trafic
 * de consultation — c'est-à-dire les gens qui vérifient un bien avant de payer.
 */
interface CaptchaVerifier
{
    public function isConfigured(): bool;

    /**
     * Clé publique à remettre au client pour afficher le défi. Publique par
     * nature : elle n'ouvre rien, et le client ne peut pas afficher le widget
     * sans elle.
     */
    public function siteKey(): ?string;

    /**
     * Vrai si le jeton présenté atteste d'un défi réellement résolu.
     *
     * DOIT ÉCHOUER FERMÉ. Un fournisseur injoignable ne peut pas valoir
     * acceptation : ce serait exactement le contournement que le défi existe
     * pour empêcher, et il suffirait de faire tomber l'appel pour balayer le
     * registre.
     */
    public function verify(string $token): bool;
}
