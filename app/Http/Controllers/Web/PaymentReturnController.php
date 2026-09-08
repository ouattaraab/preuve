<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\ReportPurchase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Où l'opérateur ramène le payeur (ST-0801, ST-0805).
 *
 * CETTE PAGE MANQUAIT, ET LE RAPPORT LA DÉSIGNAIT DÉJÀ. `ReportController`
 * envoyait Paystack renvoyer le payeur sur `/paiement/retour`, une adresse qui
 * n'existait nulle part : après avoir réglé, l'acheteur tombait sur un 404.
 * Il avait payé, la plateforme avait encaissé, et l'écran lui disait que la
 * page était introuvable. On ne prend pas d'argent pour finir là-dessus.
 *
 * ELLE NE DÉCIDE RIEN, ELLE RELIT. Le retour du navigateur ne prouve aucun
 * paiement : il suffirait de rappeler l'adresse à la main pour s'attribuer un
 * rapport. Seul le webhook signé fait foi, et cette page se contente de lire
 * l'état qu'il a écrit.
 *
 * ELLE SAIT DIRE « PAS ENCORE ». Le rappel de l'opérateur arrive parfois après
 * le navigateur : quelques secondes, exceptionnellement plus. Annoncer un échec
 * dans cet intervalle ferait repayer quelqu'un qui a déjà payé — c'est la pire
 * chose qu'une page de retour puisse faire.
 *
 * ELLE N'EST JAMAIS INDEXÉE et ne porte aucun jeton dans son adresse : le lien
 * du rapport est remis DANS la page, jamais dans l'URL, qui fuirait par
 * l'en-tête `Referer` et par l'historique du navigateur.
 */
final class PaymentReturnController extends Controller
{
    public function show(Request $request): Response
    {
        $paiement = $this->paiement($request);

        return response()->view('public.paiement-retour', [
            'indexable' => false,
            'titre' => 'Retour de paiement · Preuve',
            'description' => 'Où en est votre règlement.',
            'etat' => $paiement?->status,
            'motif' => $paiement?->purpose,
            'montant' => $paiement?->amount_fcfa,
            'intitule' => $paiement?->purpose->label(),
            'lienRapport' => $this->lienDuRapport($paiement),
            'suite' => $this->suite($paiement),
        ])->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Le paiement désigné par l'adresse de retour.
     *
     * AUCUNE AUTHENTIFICATION ICI, et rien de sensible non plus : la page ne
     * rend qu'un état et un intitulé de tarif. L'identifiant est un entier
     * séquentiel — quiconque en essaie un autre apprend qu'un paiement de
     * 1 000 FCFA a abouti, ce qui ne désigne personne et n'ouvre rien.
     */
    private function paiement(Request $request): ?Payment
    {
        // `query()` rend un TABLEAU sur `?payment[]=1` : une page publique
        // reçoit tout ce qu'on veut bien lui envoyer, et un `(int)` sur un
        // tableau lèverait une 500 sur une URL bricolée.
        $brut = $request->query('payment');
        $id = is_string($brut) ? (int) $brut : 0;

        return $id > 0 ? Payment::query()->whereKey($id)->first() : null;
    }

    /**
     * Le lien du rapport, quand c'est un rapport qui vient d'être payé.
     *
     * REMIS DANS LA PAGE, PAS DANS L'ADRESSE. Le jeton est une capacité au
     * porteur : le mettre dans l'URL le ferait fuir par l'historique et par le
     * `Referer` de la première ressource tierce chargée.
     */
    private function lienDuRapport(?Payment $paiement): ?string
    {
        if ($paiement === null
            || $paiement->purpose !== PaymentPurpose::DetailedReport
            || $paiement->status !== PaymentStatus::Succeeded) {
            return null;
        }

        $acces = ReportPurchase::query()
            ->where('payment_id', $paiement->id)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        return $acces instanceof ReportPurchase
            ? url('/rapport/'.$acces->access_token)
            : null;
    }

    /**
     * Ce qu'il reste à faire, dit dans les mots du service acheté.
     *
     * UN MESSAGE GÉNÉRIQUE NE SUFFIT PAS. « Paiement reçu » laisse le payeur
     * chercher lui-même ce qui a changé : celui qui vient de régler une mise en
     * avant doit savoir que son bien paraît désormais sur la liste, et celui
     * qui a payé un péage de déclaration qu'un code l'attend.
     */
    private function suite(?Payment $paiement): ?string
    {
        if ($paiement === null || $paiement->status !== PaymentStatus::Succeeded) {
            return null;
        }

        return match ($paiement->purpose) {
            PaymentPurpose::TheftListing => 'Votre bien paraît désormais sur la liste publique '
                .'des biens volés. Vous pouvez l\'en retirer à tout moment, gratuitement, '
                .'depuis l\'application.',
            PaymentPurpose::TheftDeclaration => 'Votre code de confirmation vient de vous être '
                .'envoyé. Revenez dans l\'application et saisissez-le pour déclarer le vol.',
            PaymentPurpose::DetailedReport => 'Votre rapport est prêt.',
            default => null,
        };
    }
}
