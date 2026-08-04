<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Services\Settings\SettingsRepository;
use DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ouverture d'une transaction Paystack, et rien d'autre.
 *
 * CETTE CLASSE N'ENCAISSE PAS ET NE CONFIRME RIEN. Elle demande une adresse de
 * règlement, la rend au client, et s'arrête là. Ce qui fait foi, c'est le
 * webhook signé : un client qui revient en annonçant « c'est payé » ne prouve
 * rien — il suffirait de rappeler l'adresse de retour à la main pour obtenir un
 * rapport gratuitement. Le retour dans l'application ne fait que RELIRE l'état.
 *
 * LA CLÉ SECRÈTE EST UN RÉGLAGE CHIFFRÉ, jamais une variable d'environnement :
 * elle change quand l'opérateur la fait tourner, et un changement de clé ne doit
 * pas demander une livraison. Sans clé, la passerelle refuse — elle ne
 * fabrique pas d'adresse fantaisiste qui échouerait chez l'utilisateur.
 *
 * LES MONTANTS PARTENT EN PLUS PETITE UNITÉ. Paystack compte en kobo, pesewa ou
 * centime selon la devise ; pour le franc CFA, qui n'a pas de subdivision en
 * usage, le montant est multiplié par cent comme l'exige l'API. Se tromper ici
 * facturerait cent fois trop, ou cent fois trop peu.
 */
final class PaystackGateway
{
    public const SECRET_SETTING = 'payments.paystack.secret_key';

    private const ENDPOINT = 'https://api.paystack.co/transaction/initialize';

    public function __construct(private readonly SettingsRepository $settings) {}

    public function isConfigured(): bool
    {
        $cle = $this->settings->get(self::SECRET_SETTING);

        return is_string($cle) && $cle !== '';
    }

    /**
     * Ouvre une transaction et rend l'adresse où régler.
     *
     * @return array{authorization_url: string, reference: string}
     *
     * @throws DomainException
     */
    public function initialize(Payment $paiement, string $courriel, string $retour): array
    {
        $cle = $this->settings->get(self::SECRET_SETTING);

        if (! is_string($cle) || $cle === '') {
            throw new DomainException(
                'Le paiement par carte n\'est pas encore configuré. Réessayez plus tard.'
            );
        }

        // LA RÉFÉRENCE EST TIRÉE PAR NOUS, à partir de l'identifiant du
        // paiement : c'est elle que le webhook rapprochera, et la contrainte
        // d'unicité `(provider, provider_ref)` rend le rejeu inoffensif. La
        // laisser tirer par l'opérateur nous priverait de ce rapprochement.
        $reference = 'preuve-'.$paiement->id.'-'.bin2hex(random_bytes(6));

        try {
            $reponse = Http::withToken($cle)
                ->timeout(20)
                ->acceptJson()
                ->post(self::ENDPOINT, [
                    'email' => $courriel,
                    // Paystack attend la plus petite unité : ×100.
                    'amount' => $paiement->amount_fcfa * 100,
                    'currency' => 'XOF',
                    'reference' => $reference,
                    'callback_url' => $retour,
                    // Aucune donnée personnelle ici : l'opérateur n'a pas à
                    // savoir quel bien est consulté, ni par qui.
                    'metadata' => ['payment_id' => $paiement->id],
                ]);
        } catch (\Throwable $e) {
            Log::warning('paystack.unreachable', ['payment' => $paiement->id, 'error' => $e->getMessage()]);

            throw new DomainException(
                'L\'opérateur de paiement ne répond pas. Réessayez dans un moment.'
            );
        }

        $corps = $reponse->json();
        $url = is_array($corps) ? data_get($corps, 'data.authorization_url') : null;

        if (! $reponse->successful() || ! is_string($url) || $url === '') {
            // Le message de l'opérateur n'est PAS relayé tel quel : il est en
            // anglais, technique, et parle de champs que l'utilisateur n'a pas
            // saisis. On journalise pour l'exploitant, on rassure l'acheteur.
            Log::warning('paystack.refused', [
                'payment' => $paiement->id,
                'status' => $reponse->status(),
                'body' => is_array($corps) ? ($corps['message'] ?? null) : null,
            ]);

            throw new DomainException(
                'L\'opérateur n\'a pas pu ouvrir le paiement. Réessayez, ou choisissez un autre moyen.'
            );
        }

        return ['authorization_url' => $url, 'reference' => $reference];
    }
}
