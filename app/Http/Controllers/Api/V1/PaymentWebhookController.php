<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\PaystackWebhook;
use App\Services\Settings\SettingsRepository;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Réception des événements d'opérateurs de paiement (ST-0806).
 *
 * Répond 200 même sur événement inconnu : un opérateur qui reçoit une erreur
 * réessaie, parfois pendant des jours, et une référence qui ne nous concerne
 * pas ne deviendra jamais valide. On accuse réception de ce qu'on a compris,
 * on ignore le reste — sans jamais laisser croire qu'un paiement a été traité
 * quand il ne l'a pas été.
 *
 * DEUX DIALECTES, UN SEUL POINT D'ENTRÉE. Paystack signe en `x-paystack-signature`,
 * en SHA-512, avec la clé secrète elle-même, et enveloppe la transaction dans
 * `data` : son cas est traité à part. Les autres opérateurs suivent le format
 * maison — `X-Preuve-Signature`, HMAC-SHA256 sur un secret partagé réglable
 * depuis l'espace administrateur.
 *
 * Sans secret, l'endpoint refuse tout : accepter des événements non signés
 * reviendrait à laisser quiconque s'attribuer des rapports gratuitement.
 */
final class PaymentWebhookController extends Controller
{
    public const SECRET_SETTING = 'payments.webhook_secret';

    public function __construct(
        private readonly PaymentService $paiements,
        private readonly SettingsRepository $settings,
        private readonly PaystackWebhook $paystack,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $operateur = PaymentProvider::tryFrom($provider);

        if ($operateur === null) {
            return response()->json(['message' => 'Opérateur inconnu.'], 404);
        }

        // CHAQUE OPÉRATEUR PARLE SA LANGUE, et il faut la parler avec lui.
        // Paystack signe en SHA-512 avec la clé secrète, dans un en-tête à lui,
        // et enveloppe la transaction dans `data`. Le format maison ci-dessous
        // reste pour les opérateurs qui n'imposent rien — PawaPay notamment.
        if ($operateur === PaymentProvider::Paystack) {
            return $this->paystackEvent($request);
        }

        if (! $this->signatureIsValid($request)) {
            return response()->json(['message' => 'Signature invalide.'], 401);
        }

        $reference = $request->string('reference')->toString();
        $etat = PaymentStatus::tryFrom($request->string('status')->toString());

        if ($reference === '' || $etat === null) {
            return response()->json(['message' => 'Événement inexploitable.'], 422);
        }

        try {
            $paiement = $this->paiements->reconcile(
                $operateur,
                $reference,
                $etat,
                $request->has('payment_id') ? (int) $request->integer('payment_id') : null,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($paiement instanceof Payment) {
            $this->paiements->fulfill($paiement);
        }

        // 200 dans tous les cas restants : l'opérateur n'a pas à réessayer un
        // événement que nous avons vu, même s'il ne nous concernait pas.
        return response()->json(['received' => true]);
    }

    /**
     * Un rappel de Paystack.
     *
     * 200 SUR CE QU'ON NE COMPREND PAS, et c'est délibéré : Paystack poste
     * aussi les transferts, les abonnements et les litiges sur la même adresse.
     * Rendre une erreur ferait retenter pendant des jours un événement qui ne
     * deviendra jamais nôtre, et finirait par faire désactiver le point de
     * réception côté opérateur — donc par nous priver des rappels utiles.
     *
     * MAIS 401 SUR UNE SIGNATURE FAUSSE : celui-là n'est pas un malentendu.
     */
    private function paystackEvent(Request $request): JsonResponse
    {
        if (! $this->paystack->verify($request)) {
            return response()->json(['message' => 'Signature invalide.'], 401);
        }

        $evenement = $this->paystack->extract($request);

        if ($evenement === null) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        try {
            $paiement = $this->paiements->reconcile(
                PaymentProvider::Paystack,
                $evenement['reference'],
                $evenement['status'],
                null,
                $evenement['amount'],
                $evenement['currency'],
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($paiement instanceof Payment) {
            // C'EST ICI QUE LE SERVICE EST RENDU : rapport crédité, bien publié
            // sur la liste, code de déclaration envoyé. Sans cet appel,
            // l'encaissement aurait lieu et rien ne suivrait.
            $this->paiements->fulfill($paiement);
        }

        return response()->json(['received' => true]);
    }

    /**
     * Comparaison en temps constant : une comparaison ordinaire laisserait
     * deviner la signature caractère par caractère.
     */
    private function signatureIsValid(Request $request): bool
    {
        $secret = $this->settings->get(self::SECRET_SETTING);

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $signature = $request->header('X-Preuve-Signature');

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }
}
