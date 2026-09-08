<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Une URL sortante qui ne peut pas viser le réseau interne (anti-SSRF).
 *
 * POURQUOI. Plusieurs réglages d'administration sont des URLs vers lesquelles
 * la plateforme émet ensuite des requêtes — passerelle SMS/OTP, fournisseur
 * KYC — en y attachant parfois un secret d'authentification. Un administrateur
 * compromis, ou abusant de son rôle, pouvait y inscrire une adresse INTERNE
 * (`https://169.254.169.254/…` métadonnées cloud, `https://10.0.0.5/…`, un
 * service back-end) : au premier appel, la plateforme frappe la cible interne
 * et lui livre l'en-tête d'authentification. Cette règle ferme ce détournement
 * à la saisie.
 *
 * CE QU'ELLE VÉRIFIE, ET SELON L'ENVIRONNEMENT :
 * - Le schéma est HTTPS (HTTP toléré en dev/local et en test, jamais en prod).
 * - Une IP littérale doit être publique — ni privée, ni réservée (métadonnées,
 *   loopback, lien-local). Appliqué en test aussi, pour que la garde soit
 *   éprouvée sans réseau ; relâché en `local` pour ne pas gêner un service de
 *   développement sur 127.0.0.1.
 * - Un nom d'hôte est RÉSOLU et chacune de ses IP vérifiée — mais uniquement
 *   en production : sortir sur le réseau en test rendrait la suite lente et non
 *   déterministe (les tests ne joignent jamais l'extérieur).
 *
 * LIMITE ASSUMÉE (TOCTOU DNS). La résolution au moment de la saisie peut
 * différer de celle au moment de l'appel : un nom pourrait pointer public à la
 * validation puis interne ensuite. La parade complète serait un client HTTP qui
 * contrôle l'IP finale de la connexion. Pour un réglage réservé aux
 * administrateurs, la validation à la saisie est une réduction de surface
 * proportionnée ; la parade de fond reste de verrouiller le réseau sortant de
 * l'origine.
 */
final class SafeExternalUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            // L'absence est du ressort de `nullable`/`required`, pas d'ici.
            return;
        }

        $url = trim($value);
        $schema = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $devTolere = app()->environment(['local', 'testing']);

        if ($schema !== 'https' && ! ($devTolere && $schema === 'http')) {
            $fail('L\'adresse doit être en HTTPS.');

            return;
        }

        $hote = parse_url($url, PHP_URL_HOST);

        if (! is_string($hote) || $hote === '') {
            $fail('Cette adresse n\'est pas une URL exploitable.');

            return;
        }

        // Retire les crochets d'une IPv6 littérale : `[::1]` → `::1`.
        $hote = trim($hote, '[]');
        $tolereInterne = app()->environment('local');

        if (! $tolereInterne && in_array(mb_strtolower($hote), ['localhost', 'localhost.localdomain'], true)) {
            $fail('Cette adresse pointe vers la machine elle-même, ce qui n\'est pas autorisé.');

            return;
        }

        // Hôte donné directement comme IP : pas de DNS, vérification immédiate.
        if (filter_var($hote, FILTER_VALIDATE_IP) !== false) {
            if (! $tolereInterne && ! $this->estPublique($hote)) {
                $fail('Cette adresse pointe vers une IP interne ou réservée, ce qui n\'est pas autorisé.');
            }

            return;
        }

        // Nom d'hôte : la résolution ne se fait qu'en production (voir en-tête).
        if (! app()->environment('production')) {
            return;
        }

        $ips = $this->resoudre($hote);

        if ($ips === []) {
            $fail('L\'hôte de cette adresse est introuvable.');

            return;
        }

        foreach ($ips as $ip) {
            if (! $this->estPublique($ip)) {
                $fail('Cette adresse résout vers une IP interne ou réservée, ce qui n\'est pas autorisé.');

                return;
            }
        }
    }

    /**
     * Publique = ni plage privée (RFC 1918, fc00::/7), ni plage réservée
     * (loopback, lien-local 169.254/16, métadonnées, documentation…).
     */
    private function estPublique(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * Les IP d'un nom d'hôte, A et AAAA.
     *
     * @return list<string>
     */
    private function resoudre(string $hote): array
    {
        $ips = gethostbynamel($hote) ?: [];

        foreach (@dns_get_record($hote, DNS_AAAA) ?: [] as $enregistrement) {
            if (isset($enregistrement['ipv6']) && is_string($enregistrement['ipv6'])) {
                $ips[] = $enregistrement['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
