<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OtpPurpose;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Payment;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PaymentService;
use App\Services\PaystackGateway;
use App\Services\PricingService;
use App\Services\ReportAccessService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Achat et lecture du rapport détaillé (ST-0801, ST-0802).
 *
 * L'achat n'exige pas de compte, mais exige une IDENTITÉ : un rapport anonyme
 * deviendrait l'outil de repérage idéal. L'acheteur sans compte fournit nom,
 * e-mail et téléphone, ce dernier vérifié par code AVANT le paiement — vérifier
 * après coup ne servirait à rien, l'accès serait déjà ouvert.
 *
 * La LECTURE, elle, ne passe que par le jeton : ni compte, ni session. C'est ce
 * qui permet de recevoir son rapport par SMS et de l'ouvrir sur n'importe quel
 * appareil, y compris celui d'un tiers au marché.
 */
final class ReportController extends Controller
{
    public function __construct(
        private readonly PaymentService $paiements,
        private readonly ReportAccessService $rapports,
        private readonly OtpService $otp,
        private readonly PricingService $tarifs,
        private readonly PaystackGateway $paystack,
    ) {}

    /** Envoie le code de vérification à un acheteur sans compte. */
    public function requestGuestCode(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30']]);

        $this->otp->request($request->string('phone')->toString(), OtpPurpose::GuestPayment);

        return response()->json([
            'message' => 'Si ce numéro est joignable, un code vient de lui être envoyé.',
        ]);
    }

    /**
     * Commande un rapport à partir de la RÉFÉRENCE PUBLIQUE.
     *
     * C'EST LE SEUL CHEMIN D'UN ACHETEUR. Il ne connaît pas l'identifiant
     * interne du bien qu'on lui propose — la consultation le tait, précisément
     * pour qu'on ne puisse pas balayer le registre. La référence opaque, elle,
     * figure sur le verdict qu'il vient de lire.
     */
    public function purchaseByReference(Request $request): JsonResponse
    {
        $request->validate(['public_ref' => ['required', 'string', 'max:32']]);

        $bien = Asset::query()
            ->where('public_ref', mb_strtoupper(trim($request->string('public_ref')->toString())))
            ->whereNotNull('active_flag')
            ->first();

        if (! $bien instanceof Asset) {
            abort(404);
        }

        return $this->purchase($request, $bien->id);
    }

    public function purchase(Request $request, int $asset): JsonResponse
    {
        $request->validate([
            'provider' => ['required', Rule::enum(PaymentProvider::class)],
            'buyer_name' => ['sometimes', 'string', 'max:150'],
            'buyer_email' => ['sometimes', 'email', 'max:150'],
            'buyer_phone' => ['sometimes', 'string', 'max:30'],
            'code' => ['sometimes', 'string'],
        ]);

        $bien = Asset::query()->whereKey($asset)->first();

        if (! $bien instanceof Asset) {
            abort(404);
        }

        $operateur = PaymentProvider::from($request->string('provider')->toString());
        $acheteur = $request->user('sanctum');

        // GRATUIT VEUT DIRE GRATUIT : aucun paiement n'est ouvert, aucun
        // opérateur n'est appelé, et l'accès est accordé sur-le-champ. Ouvrir
        // une transaction de zéro franc ferait échouer l'opérateur, et exiger
        // une carte pour ne rien encaisser serait une friction sans objet.
        if ($this->tarifs->isFree('report')) {
            $acces = $this->rapports->grantFree($bien, $acheteur instanceof User ? $acheteur : null);

            return response()->json([
                'payment' => null,
                'free' => true,
                'access_token' => $acces->access_token,
                // L'ADRESSE, PAS SEULEMENT LE JETON. Un jeton nu se lit avec un
                // client d'API ; personne d'autre n'en fait rien. C'est cette
                // adresse qui s'ouvre sur n'importe quel appareil, se transmet
                // par SMS et se transfère à son garagiste — c'était l'intention
                // depuis le début, et il n'y avait pas de page pour l'accueillir.
                'report_url' => route('public.report', ['token' => $acces->access_token]),
                'message' => 'Le rapport détaillé est gratuit en ce moment.',
            ], 201);
        }

        try {
            $paiement = $acheteur instanceof User
                ? $this->paiements->intendForUser($acheteur, $bien, $operateur)
                : $this->paiements->intendForGuest(
                    $bien,
                    $operateur,
                    $request->string('buyer_name')->toString(),
                    $request->string('buyer_email')->toString(),
                    $request->string('buyer_phone')->toString(),
                    $request->string('code')->toString(),
                );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['buyer_phone' => $e->getMessage()]);
        }

        // OÙ RÉGLER, ET PAS SEULEMENT COMBIEN. Le client était jusqu'ici prié
        // de « régler auprès de l'opérateur », sans adresse : il n'avait aucun
        // moyen de payer. Paystack ouvre la transaction et rend l'adresse ; les
        // opérateurs mobiles gardent leur parcours propre, hors application.
        $checkout = null;

        if ($paiement->provider === PaymentProvider::Paystack) {
            try {
                $ouverture = $this->paystack->initialize(
                    $paiement,
                    $this->courrielDeContact($request, $acheteur),
                    $this->retour($paiement),
                );
            } catch (DomainException $e) {
                throw ValidationException::withMessages(['provider' => $e->getMessage()]);
            }

            $checkout = $ouverture['authorization_url'];

            // La référence de l'opérateur est posée AVANT la redirection :
            // c'est elle que le webhook rapprochera, et l'unicité
            // `(provider, provider_ref)` rend un rejeu inoffensif.
            $paiement->forceFill(['provider_ref' => $ouverture['reference']])->save();
        }

        return response()->json([
            'payment' => [
                'id' => $paiement->id,
                'amount_fcfa' => $paiement->amount_fcfa,
                'provider' => $paiement->provider->value,
                'provider_label' => $paiement->provider->label(),
                'status' => $paiement->status->value,
            ],
            'checkout_url' => $checkout,
            'message' => $checkout === null
                ? 'Réglez le montant auprès de l\'opérateur. Votre lien de rapport vous sera transmis '
                    .'dès la confirmation du paiement.'
                : 'Ouvre la page de paiement, règle le montant, puis reviens : le rapport '
                    .'s\'ouvrira dès que l\'opérateur aura confirmé.',
        ], 201);
    }

    /**
     * Adresse de retour après paiement.
     *
     * ELLE NE PROUVE RIEN, et c'est important : le client qui revient ne fait
     * que relire l'état. Ce qui accorde l'accès est le webhook signé de
     * l'opérateur — sans quoi il suffirait de rappeler cette adresse à la main
     * pour obtenir un rapport sans payer.
     */
    private function retour(Payment $paiement): string
    {
        return url('/paiement/retour?payment='.$paiement->id);
    }

    /**
     * Courriel transmis à l'opérateur : le sien s'il est connu, une adresse de
     * service sinon. Paystack l'exige ; il n'a pas à connaître autre chose.
     */
    private function courrielDeContact(Request $request, mixed $acheteur): string
    {
        if ($acheteur instanceof User && is_string($acheteur->email) && $acheteur->email !== '') {
            return $acheteur->email;
        }

        $saisi = $request->string('buyer_email')->toString();

        return $saisi !== '' ? $saisi : 'paiements@preuve.click';
    }

    /**
     * Lecture par jeton. Aucune authentification : le jeton EST le droit
     * d'accès, et il a été acheté par une personne identifiée.
     */
    public function show(string $token): JsonResponse
    {
        try {
            $rapport = $this->rapports->read($token);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($rapport + [
            // L'adresse partageable du même rapport. Elle permet à un client
            // d'offrir « transmettre ce lien » sans reconstruire une URL, donc
            // sans se tromper de domaine sur une recette.
            'report_url' => route('public.report', ['token' => $token]),
        ]);
    }
}
