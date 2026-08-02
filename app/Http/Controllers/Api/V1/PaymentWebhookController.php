<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
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
 * La signature partagée se configure depuis l'espace administrateur, comme les
 * autres secrets de la plateforme. Sans elle, l'endpoint refuse tout :
 * accepter des événements non signés reviendrait à laisser quiconque
 * s'attribuer des rapports gratuitement.
 */
final class PaymentWebhookController extends Controller
{
    public const SECRET_SETTING = 'payments.webhook_secret';

    public function __construct(
        private readonly PaymentService $paiements,
        private readonly SettingsRepository $settings,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $operateur = PaymentProvider::tryFrom($provider);

        if ($operateur === null) {
            return response()->json(['message' => 'Opérateur inconnu.'], 404);
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
