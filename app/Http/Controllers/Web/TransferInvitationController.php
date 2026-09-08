<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Transfer;
use App\Services\TransferService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Accepter une cession depuis le web, sans avoir l'application (ST-0601).
 *
 * POURQUOI CETTE PAGE EXISTE. Une cession s'ouvre vers quelqu'un qui n'a
 * souvent aucun compte, et parfois aucun téléphone compatible : au marché, le
 * vendeur a l'application, l'acheteur non. Sans ce chemin, l'acheteur reçoit un
 * courriel qui lui demande d'installer une application pour valider une vente
 * déjà conclue — et sept jours plus tard la cession expire, le bien reste
 * enregistré au vendeur, et l'acheteur détient un bien qui n'est pas à son nom.
 * C'est exactement la situation que le registre existe pour éviter.
 *
 * DEUX FACTEURS, ET LES DEUX SONT NÉCESSAIRES. Le jeton du lien prouve qu'on a
 * reçu le courriel ; le code prouve qu'on lit bien cette boîte à l'instant. Un
 * courriel transféré, une boîte partagée, une capture d'écran : aucun de ces
 * incidents ne suffit à faire changer un bien de mains.
 *
 * LA PAGE NE NOMME PAS LE VENDEUR (règle métier absolue n° 4), et n'est PAS
 * indexable : elle décrit un bien identifiable et une transaction en cours.
 *
 * LE COMPTE EST CRÉÉ À LA CONFIRMATION, pas avant. Le code vérifié prouve la
 * maîtrise de l'adresse : c'est le seul moment où la création est légitime, et
 * c'est la même règle que sur l'application.
 */
final class TransferInvitationController extends Controller
{
    public function __construct(private readonly TransferService $transferts) {}

    public function show(string $token): Response
    {
        $transfert = $this->transfertOuvert($token);

        return $this->page($transfert);
    }

    /**
     * Renvoie un code à l'adresse de la cession.
     *
     * LE LIEN VIT SEPT JOURS, LE CODE CINQ MINUTES. Sans ce renvoi, l'acheteur
     * qui ouvrait son courriel un quart d'heure plus tard n'avait aucun
     * recours : la page lui disait d'en demander un depuis l'application, que
     * cette page existe justement pour ne pas exiger.
     *
     * IL PART TOUJOURS À L'ADRESSE DE LA CESSION, jamais à une adresse
     * soumise : le formulaire ne demande rien, et il n'y a donc rien à
     * détourner. Qui a le lien fait partir un code chez le destinataire
     * légitime, et nulle part ailleurs.
     */
    public function requestCode(string $token): Response
    {
        $transfert = $this->transfertOuvert($token);

        if ($transfert->to_email === null) {
            return $this->page($transfert, erreur: 'Cette cession se confirme depuis l\'application.');
        }

        try {
            $envoi = $this->transferts->sendCodeToBuyer($transfert);
        } catch (DomainException $e) {
            return $this->page($transfert, erreur: $e->getMessage());
        } catch (Throwable) {
            // Le refus de rythme de l'OTP passe par ici : son message dit
            // « patientez », ce qui est exactement la conduite à tenir.
            return $this->page($transfert, erreur: 'Un code vient déjà de partir. Patientez une '
                .'minute avant d\'en redemander un.');
        }

        // ON NE PROMET PAS UN SECOND MESSAGE QUAND LE PREMIER TIENT ENCORE :
        // il n'arriverait pas, et l'acheteur l'attendrait au lieu de saisir le
        // code qu'il a déjà sous les yeux.
        return $this->page(
            $transfert,
            envoye: $envoi['fresh'] ? $envoi['sent_to'] : null,
            rappel: $envoi['fresh'] ? null : $envoi['sent_to'],
        );
    }

    public function confirm(Request $request, string $token): Response
    {
        $transfert = $this->transfertOuvert($token);

        $brut = $request->input('code');
        $code = is_string($brut) ? trim($brut) : '';

        if ($code === '') {
            return $this->page($transfert, erreur: 'Saisissez le code reçu par courriel.');
        }

        if ($transfert->to_email === null) {
            // Une cession ouverte sans adresse ne s'accepte pas ici : le code
            // est parti vers un numéro, et rien ne relie ce visiteur à lui.
            return $this->page($transfert, erreur: 'Cette cession se confirme depuis l\'application.');
        }

        try {
            // LE CODE D'ABORD, LE COMPTE ENSUITE : le service n'ouvre le compte
            // de l'acheteur qu'une fois le code reconnu. La page le créait
            // avant, et une saisie fautive en laissait un derrière elle.
            $transfert = $this->transferts->confirmByBuyerAddress($transfert, $transfert->to_email, $code);
        } catch (DomainException $e) {
            return $this->page($transfert, erreur: $e->getMessage());
        } catch (Throwable) {
            // Un refus qui ne vient pas du métier : on n'en recopie pas le
            // détail, qui dirait combien d'essais restent.
            return $this->page($transfert, erreur: 'Ce code n\'a pas été accepté. Demandez-en un '
                .'nouveau, puis réessayez.');
        }

        // DEUX ISSUES, ET IL FAUT DIRE LAQUELLE. Le transfert exige les DEUX
        // confirmations : celle du vendeur peut ne pas être encore venue.
        // Annoncer « le bien est à vous » dans ce cas serait faux, et l'acheteur
        // repartirait convaincu d'un enregistrement qui n'a pas eu lieu.
        $acheve = $transfert->status === TransferStatus::Completed;
        $nouveau = $acheve && $transfert->created_asset_id !== null
            ? Asset::query()->whereKey($transfert->created_asset_id)->first()
            : null;

        return response()->view('public.cession-acceptee', [
            'indexable' => false,
            'titre' => $acheve ? 'Cession confirmée · Preuve' : 'Votre accord est enregistré · Preuve',
            'description' => 'Suite de la cession.',
            'acheve' => $acheve,
            'bien' => $this->apercu($transfert),
            'publicRef' => $nouveau instanceof Asset ? (string) $nouveau->public_ref : null,
        ])->header('X-Robots-Tag', 'noindex, nofollow')
            // NO-REFERRER : le jeton de cession vit dans l'URL. Sans cet
            // en-tête, un clic sortant de la page en fuirait le chemin complet
            // dans l'en-tête Referer.
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * @param  string|null  $envoye  destination masquée où un code vient de partir
     * @param  string|null  $rappel  destination masquée où un code encore valable attend
     */
    private function page(
        Transfer $transfert,
        ?string $erreur = null,
        ?string $envoye = null,
        ?string $rappel = null,
    ): Response {
        return response()->view('public.cession', [
            'indexable' => false,
            'titre' => 'Un bien vous est cédé · Preuve',
            'description' => 'Confirmez la cession avec le code reçu.',
            'jeton' => (string) $transfert->invite_token,
            'bien' => $this->apercu($transfert),
            'expire' => $transfert->expires_at,
            'erreur' => $erreur,
            'envoye' => $envoye,
            'rappel' => $rappel,
        ])->header('X-Robots-Tag', 'noindex, nofollow')
            // NO-REFERRER : le jeton de cession vit dans l'URL. Sans cet
            // en-tête, un clic sortant de la page en fuirait le chemin complet
            // dans l'en-tête Referer.
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Ce qu'on montre du bien, et rien d'autre.
     *
     * NI LE VENDEUR, NI SON HISTORIQUE. La page s'ouvre avec un lien : elle ne
     * doit rien apprendre à qui l'aurait intercepté au-delà de ce qu'il faut
     * pour reconnaître la transaction.
     *
     * @return array<string, string>
     */
    private function apercu(Transfer $transfert): array
    {
        $bien = Asset::query()->whereKey($transfert->asset_id)->first();

        if (! $bien instanceof Asset) {
            return ['categorie' => 'Bien', 'numero' => '', 'marque' => ''];
        }

        $attributs = $bien->getAttribute('attributes');

        return [
            'categorie' => ucfirst((string) $bien->asset_category_key),
            'numero' => (string) $bien->identifier_raw,
            'marque' => is_array($attributs) && is_string($attributs['brand_model'] ?? null)
                ? mb_substr(trim($attributs['brand_model']), 0, 80)
                : '',
        ];
    }

    private function transfertOuvert(string $token): Transfer
    {
        // LE JETON EST LA SEULE CLÉ. Pas d'identifiant en clair dans l'URL :
        // `/cession/42` s'énumérerait, et livrerait quels biens changent de
        // mains cette semaine — donc lesquels sont disponibles à l'arrachée.
        $transfert = Transfer::query()
            ->where('invite_token', $token)
            // `Initiated` seulement : `BuyerConfirmed` veut dire que
            // l'acheteur a DÉJÀ accepté, et rouvrir la page lui ferait croire
            // que rien n'a pris.
            ->where('status', TransferStatus::Initiated)
            ->where('expires_at', '>', now())
            ->first();

        if (! $transfert instanceof Transfer) {
            // 404 pour tous les cas : jeton inconnu, cession expirée, déjà
            // acceptée. Les distinguer confirmerait qu'un jeton a existé.
            abort(404);
        }

        return $transfert;
    }
}
