<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\OtpPurpose;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\AccountOtpSender;
use App\Services\OtpService;
use App\Services\PaymentCheckout;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\ReportAccessService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Acheter le rapport détaillé depuis le web, sans compte (ST-0801, ST-0802).
 *
 * POURQUOI CETTE PAGE MANQUAIT ALORS QUE TOUT EXISTAIT. Le serveur savait
 * ouvrir un achat invité, vérifier son code, encaisser et rendre un jeton ;
 * l'application mobile le proposait. Le WEB, lui, s'arrêtait sur le verdict
 * avec un bouton « Vérifier un autre bien » — au moment exact où quelqu'un,
 * debout devant une moto, vient de lire « Rien à signaler » et voudrait en
 * savoir plus. La principale ligne de revenu du produit n'était donc pas
 * vendable sur son canal le plus accessible : un navigateur, sans installation.
 *
 * L'IDENTITÉ EST EXIGÉE, PAS LE COMPTE (règle métier absolue n° 7). Un rapport
 * anonyme serait l'outil de repérage idéal : on saurait tout d'un bien sans
 * jamais laisser de trace. Le trio nom + adresse + téléphone, dont un élément
 * est vérifié par code AVANT le paiement, est le minimum qui laisse une trace
 * exploitable en cas d'abus — et rien de plus n'est demandé.
 *
 * LE CODE PART LÀ OÙ IL PEUT ARRIVER. Tant qu'aucune passerelle SMS n'est
 * branchée, un code adressé à un numéro ne part nulle part. `AccountOtpSender`
 * porte cette décision une fois pour toutes ; le défi reste indexé sur le
 * numéro, qui identifie l'acheteur.
 *
 * LA PAGE N'EST JAMAIS INDEXÉE : elle porte la référence d'un bien réel et les
 * coordonnées d'un acheteur en cours de saisie.
 */
final class ReportPurchaseController extends Controller
{
    /** Ce que le formulaire retient entre deux étapes. */
    private const SESSION = 'achat_rapport';

    public function __construct(
        private readonly PricingService $tarifs,
        private readonly PaymentService $paiements,
        private readonly PaymentCheckout $caisse,
        private readonly ReportAccessService $rapports,
        private readonly AccountOtpSender $codes,
        private readonly OtpService $otp,
    ) {}

    public function show(Request $request, string $publicRef): Response
    {
        return $this->page($request, $this->bien($publicRef));
    }

    /** Étape 1 : l'acheteur se nomme, et reçoit un code. */
    public function requestCode(Request $request, string $publicRef): Response
    {
        $bien = $this->bien($publicRef);

        $request->validate([
            'buyer_name' => ['required', 'string', 'max:150'],
            'buyer_email' => ['required', 'email', 'max:150'],
            'buyer_phone' => ['required', 'string', 'max:30'],
        ]);

        $request->session()->put(self::SESSION, [
            'buyer_name' => $request->string('buyer_name')->toString(),
            'buyer_email' => $request->string('buyer_email')->toString(),
            'buyer_phone' => $request->string('buyer_phone')->toString(),
        ]);

        try {
            $parti = $this->codes->sendTo(
                $request->string('buyer_phone')->toString(),
                $request->string('buyer_email')->toString(),
                OtpPurpose::GuestPayment,
            );
        } catch (Throwable) {
            $parti = false;
        }

        if (! $parti) {
            return $this->page($request, $bien, erreur: 'Le code n\'a pas pu être envoyé. '
                .'Vérifiez le numéro et l\'adresse, puis réessayez dans un instant.');
        }

        return $this->page($request, $bien, etape: 'code');
    }

    /** Étape 2 : le code est vérifié, puis la caisse s'ouvre. */
    public function purchase(Request $request, string $publicRef): Response|RedirectResponse
    {
        $bien = $this->bien($publicRef);
        $acheteur = $this->acheteurEnSession($request);

        if ($acheteur === null) {
            // Session perdue — onglet rouvert, cookie expiré. On recommence
            // plutôt que d'échouer sur un champ que l'acheteur ne voit plus.
            return $this->page($request, $bien, erreur: 'Reprenons : vos coordonnées n\'ont pas été retenues.');
        }

        $request->validate(['code' => ['required', 'string', 'max:12']]);
        $code = trim($request->string('code')->toString());

        // GRATUIT VEUT DIRE GRATUIT : aucun opérateur appelé, accès immédiat.
        // Ouvrir une transaction de zéro franc la ferait échouer.
        if ($this->tarifs->isFree('report')) {
            try {
                $this->otp->verify($acheteur['buyer_phone'], $code, OtpPurpose::GuestPayment);
            } catch (Throwable $e) {
                return $this->page($request, $bien, etape: 'code', erreur: $this->refus($e));
            }

            $acces = $this->rapports->grantFree($bien, null);
            $request->session()->forget(self::SESSION);

            return redirect()->route('public.report', ['token' => $acces->access_token]);
        }

        try {
            $paiement = $this->paiements->intendForGuest(
                $bien,
                PaymentProvider::Paystack,
                $acheteur['buyer_name'],
                $acheteur['buyer_email'],
                $acheteur['buyer_phone'],
                $code,
            );

            $checkout = $this->caisse->openFor($paiement, $acheteur['buyer_email']);
        } catch (DomainException $e) {
            return $this->page($request, $bien, etape: 'code', erreur: $e->getMessage());
        } catch (Throwable $e) {
            return $this->page($request, $bien, etape: 'code', erreur: $this->refus($e));
        }

        $request->session()->forget(self::SESSION);

        if ($checkout === null) {
            // Aucun opérateur en ligne : on ne laisse pas l'acheteur sur une
            // page morte, la page de retour dira où en est son règlement.
            return redirect()->to('/paiement/retour?payment='.$paiement->id);
        }

        // REDIRECTION HORS DU SITE, dans le navigateur de l'acheteur : c'est la
        // barre d'adresse qui lui permet de vérifier qu'il est bien chez
        // l'opérateur et non sur une imitation.
        return redirect()->away($checkout);
    }

    /**
     * Le message d'un refus d'OTP, sans son détail.
     *
     * ON NE DIT PAS COMBIEN D'ESSAIS RESTENT : ce décompte guiderait une
     * tentative automatisée, et n'aide en rien celui qui a mal recopié.
     */
    private function refus(Throwable $e): string
    {
        return $e instanceof DomainException
            ? $e->getMessage()
            : 'Ce code est incorrect ou périmé. Demandez-en un nouveau.';
    }

    /** @return array{buyer_name: string, buyer_email: string, buyer_phone: string}|null */
    private function acheteurEnSession(Request $request): ?array
    {
        $donnees = $request->session()->get(self::SESSION);

        if (! is_array($donnees)
            || ! is_string($donnees['buyer_name'] ?? null)
            || ! is_string($donnees['buyer_email'] ?? null)
            || ! is_string($donnees['buyer_phone'] ?? null)) {
            return null;
        }

        return [
            'buyer_name' => $donnees['buyer_name'],
            'buyer_email' => $donnees['buyer_email'],
            'buyer_phone' => $donnees['buyer_phone'],
        ];
    }

    private function page(
        Request $request,
        Asset $bien,
        string $etape = 'identite',
        ?string $erreur = null,
    ): Response {
        $acheteur = $this->acheteurEnSession($request);

        return response()->view('public.rapport-commander', [
            'indexable' => false,
            'titre' => 'Rapport détaillé · Preuve',
            'description' => 'L\'histoire complète d\'un bien avant de l\'acheter.',
            'publicRef' => $bien->public_ref,
            'categorie' => ucfirst((string) $bien->asset_category_key),
            'prix' => $this->tarifs->amount('report'),
            'gratuit' => $this->tarifs->isFree('report'),
            'etape' => $etape,
            'erreur' => $erreur,
            'acheteur' => $acheteur,
            // OÙ LE CODE EST PARTI, dit à l'écran. Sans cela, l'acheteur guette
            // un SMS pendant que le message est dans sa boîte aux lettres.
            'parCourriel' => $this->codes->smsAbsent(),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function bien(string $publicRef): Asset
    {
        $bien = Asset::query()
            ->where('public_ref', mb_strtoupper(trim($publicRef)))
            ->whereNotNull('active_flag')
            ->first();

        if (! $bien instanceof Asset) {
            abort(404);
        }

        return $bien;
    }
}
