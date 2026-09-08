<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\Request;

/**
 * Comprendre ce que Paystack envoie vraiment (ST-0806).
 *
 * POURQUOI CETTE CLASSE EXISTE. L'endpoint de webhook était écrit pour un
 * format maison : en-tête `X-Preuve-Signature`, HMAC-SHA256 sur un secret
 * partagé, corps `{reference, status}`. Paystack n'envoie rien de tout cela. Il
 * signe en `x-paystack-signature`, en **SHA-512**, avec la CLÉ SECRÈTE
 * elle-même, et poste `{event, data:{reference, status}}`. Branché tel quel, un
 * paiement réel aurait été refusé en 401 — et même signé correctement, rejeté
 * en 422 parce que « success » n'est pas une valeur de notre énumération.
 * L'argent serait entré, rien n'aurait été crédité, et personne ne l'aurait su
 * avant une réclamation.
 *
 * LA SIGNATURE EST CALCULÉE SUR LE CORPS BRUT, pas sur le tableau décodé. Un
 * JSON ré-encodé diffère de l'original — ordre des clés, échappement des
 * barres obliques, espaces — et la signature ne vaudrait plus rien.
 *
 * LA CLÉ SECRÈTE SERT DEUX FOIS, et c'est Paystack qui le veut : elle ouvre les
 * transactions et signe les rappels. Il n'y a donc AUCUN second secret à
 * configurer pour cet opérateur — le champ `payments.webhook_secret` reste
 * réservé aux opérateurs qui en exigent un.
 *
 * ON NE FAIT PAS CONFIANCE À « event » SEUL. `charge.success` dit ce qui s'est
 * passé, mais c'est `data.status` qui porte l'état de la transaction ; les deux
 * peuvent diverger sur un remboursement, et c'est l'état qui doit gagner.
 */
final class PaystackWebhook
{
    private const HEADER = 'x-paystack-signature';

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Vrai si la requête vient bien de Paystack.
     *
     * SANS CLÉ, C'EST FAUX. Une plateforme dont la clé n'est pas encore posée
     * ne doit accepter aucun rappel : ce serait offrir à quiconque le droit de
     * s'attribuer un rapport ou une publication.
     */
    public function verify(Request $request): bool
    {
        $cle = $this->settings->get(PaystackGateway::SECRET_SETTING);

        if (! is_string($cle) || $cle === '') {
            return false;
        }

        $signature = $request->header(self::HEADER);

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        // Comparaison en temps constant : une comparaison ordinaire laisserait
        // deviner la signature caractère par caractère.
        return hash_equals(hash_hmac('sha512', $request->getContent(), $cle), $signature);
    }

    /**
     * La référence, l'état ET le montant réglé, tirés de la charge utile.
     *
     * LE MONTANT ET LA DEVISE SONT REMONTÉS pour être confrontés à l'attendu
     * (voir PaymentService::reconcile). Paystack les rend dans `data.amount`
     * (plus petite unité, ×100) et `data.currency`. Ils peuvent manquer sur un
     * événement partiel : dans ce cas on rend `null`, et la réconciliation
     * traite l'absence comme « non vérifiable » plutôt que comme « conforme ».
     *
     * @return array{reference: string, status: PaymentStatus, amount: int|null, currency: string|null}|null
     *                                                                                                       null quand l'événement ne nous concerne pas — le cas
     *                                                                                                       ordinaire : Paystack poste aussi transferts, abonnements, litiges.
     */
    public function extract(Request $request): ?array
    {
        $donnees = $request->input('data');

        if (! is_array($donnees)) {
            return null;
        }

        $reference = $donnees['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $etat = $this->etat(is_string($donnees['status'] ?? null) ? $donnees['status'] : '');

        if ($etat === null) {
            return null;
        }

        return [
            'reference' => $reference,
            'status' => $etat,
            'amount' => is_numeric($donnees['amount'] ?? null) ? (int) $donnees['amount'] : null,
            'currency' => is_string($donnees['currency'] ?? null) ? $donnees['currency'] : null,
        ];
    }

    /**
     * Le vocabulaire de Paystack, traduit dans le nôtre.
     *
     * CE QUI N'EST PAS RECONNU RESTE NON RECONNU. Rendre « en attente » par
     * défaut ferait basculer une transaction aboutie vers un état antérieur au
     * premier mot nouveau dans leur API — et `reconcile()` ne rejoue pas un
     * état définitif, si bien que la perte serait silencieuse et durable.
     */
    private function etat(string $paystack): ?PaymentStatus
    {
        return match (mb_strtolower($paystack)) {
            'success' => PaymentStatus::Succeeded,
            // « abandoned » : le payeur a fermé la page. C'est un échec du
            // point de vue du service rendu, et le dire permet au client de
            // rouvrir une caisse plutôt que d'attendre indéfiniment.
            'failed', 'abandoned' => PaymentStatus::Failed,
            'reversed' => PaymentStatus::Refunded,
            'ongoing', 'pending', 'processing', 'queued' => PaymentStatus::Pending,
            default => null,
        };
    }
}
