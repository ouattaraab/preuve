<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentProvider;
use App\Enums\PaymentPurpose;
use App\Models\Asset;
use App\Models\Payment;
use App\Models\User;
use DomainException;

/**
 * Ouvrir une page de paiement, en un seul endroit.
 *
 * POURQUOI CE SERVICE EXISTE. Deux contrôleurs — la mise en avant, la
 * déclaration payante — avaient besoin de la même séquence : créer l'intention,
 * appeler l'opérateur, ranger sa référence, rendre l'adresse de la page. Écrite
 * deux fois, cette séquence aurait divergé au premier correctif : l'un des deux
 * chemins aurait cessé d'enregistrer `provider_ref`, et les webhooks de ce
 * motif n'auraient plus trouvé leur transaction — un encaissement sans
 * contrepartie, muet.
 *
 * ELLE CONSTRUIT AUSSI L'ADRESSE DE RETOUR, parce qu'elle seule dispose de
 * l'identifiant du paiement au bon moment — et parce qu'un retour laissé à
 * l'appelant finit par pointer une route qui n'affiche rien d'utile.
 *
 * LA RÉFÉRENCE DE L'OPÉRATEUR EST ÉCRITE AVANT TOUT RETOUR. C'est elle qui
 * relie le webhook à la transaction ; sans elle, l'argent arrive et rien ne
 * s'ouvre.
 *
 * UN OPÉRATEUR SANS PASSERELLE NE REND PAS D'ADRESSE, et c'est volontaire :
 * PawaPay se règle hors application. Rendre `null` dit « pas de page à
 * ouvrir », ce qu'un client sait afficher, là où une exception ferait échouer
 * un parcours parfaitement valide.
 */
final class PaymentCheckout
{
    public function __construct(
        private readonly PaymentService $paiements,
        private readonly PaystackGateway $paystack,
    ) {}

    /**
     * @return array{payment: Payment, checkout_url: string|null}
     *
     * @throws DomainException si l'opérateur refuse d'ouvrir la page
     */
    public function open(
        User $payeur,
        Asset $bien,
        PaymentProvider $operateur,
        PaymentPurpose $motif,
        int $montant,
    ): array {
        $paiement = $this->paiements->intendFor($payeur, $bien, $operateur, $motif, $montant);

        if ($operateur !== PaymentProvider::Paystack) {
            return ['payment' => $paiement, 'checkout_url' => null];
        }

        // L'ADRESSE DE RETOUR EST CONSTRUITE ICI, ET NON PAR L'APPELANT. Elle
        // a besoin de l'identifiant du paiement, qui n'existe qu'une fois
        // l'intention créée : la laisser au contrôleur l'a fait pointer une
        // route d'API authentifiée, où l'opérateur ramenait un NAVIGATEUR pour
        // lui afficher un 401 en JSON — à quelqu'un qui venait de payer.
        $ouverture = $this->paystack->initialize(
            $paiement,
            $payeur->email ?? '',
            url('/paiement/retour?payment='.$paiement->id),
        );

        $paiement->forceFill(['provider_ref' => $ouverture['reference']])->save();

        return ['payment' => $paiement, 'checkout_url' => $ouverture['authorization_url']];
    }
}
