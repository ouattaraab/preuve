<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LifeStatus;
use App\Enums\NotificationType;
use App\Enums\OtpPurpose;
use App\Enums\PaymentPurpose;
use App\Enums\TriggerType;
use App\Exceptions\PaymentRequiredException;
use App\Models\Asset;
use App\Models\User;
use DomainException;

/**
 * Déclarations du détenteur sur le cycle de vie d'un bien : vol, levée de vol,
 * fin de vie (ST-0604 à ST-0606).
 *
 * LE VOL SE DÉCLARE EN UN GESTE. C'est le moment le plus mauvais de la vie de
 * l'utilisateur : il vient de se faire dépouiller, souvent dans la rue, parfois
 * sur un téléphone qui n'est plus le sien. Chaque champ demandé est une chance
 * de plus que la déclaration n'aboutisse pas — et chaque minute perdue est une
 * minute où le bien reste vendable. Un code OTP suffit à prouver que c'est bien
 * le détenteur, et rien d'autre n'est exigé : ni récépissé, ni circonstances,
 * ni photo.
 *
 * LA CONSOLIDATION VIENT APRÈS. Le récépissé de plainte, déposé sous 15 jours,
 * fait passer la déclaration de « déclaré » à « consolidé ». Son absence ne
 * lève jamais l'alerte — elle la nuance seulement, car exiger une plainte
 * préalable rendrait le dispositif inutile là où il sert le plus.
 *
 * LA LEVÉE APPARTIENT AU SEUL DÉCLARANT (ST-0605). Si un tiers pouvait lever
 * une déclaration de vol, il suffirait de voler le bien puis de lever l'alerte
 * pour le revendre. La levée est journalisée et reste visible dans le rapport
 * détaillé : un bien déclaré volé puis « retrouvé » deux fois n'a pas la même
 * histoire qu'un bien sans incident.
 */
final class AssetLifecycleService
{
    public function __construct(
        private readonly StatusTransitionService $transitions,
        private readonly OtpService $otp,
        private readonly NotificationService $notifications,
        private readonly PricingService $tarifs,
        private readonly PaymentService $paiements,
    ) {}

    /**
     * Déclare un vol. Effet immédiat et public : le bien devient invendable au
     * regard de quiconque le consulte.
     *
     * @throws DomainException
     */
    public function declareStolen(Asset $bien, User $detenteur, string $code): Asset
    {
        $this->assertHolder($bien, $detenteur);
        $this->assertPeageRegle($bien, $detenteur);

        $this->otp->verify($this->destinationDe($detenteur), $code, OtpPurpose::SensitiveAction);

        $this->transitions->transitionTo(
            $bien,
            LifeStatus::Stolen,
            TriggerType::Owner,
            $detenteur->id,
            'Déclaration de vol par le détenteur',
        );

        $bien->forceFill([
            'stolen_declared_at' => now(),
            // Non consolidé tant qu'aucun récépissé n'a été accepté : la
            // nuance est publique, l'alerte ne l'est pas moins.
            'stolen_consolidated' => false,
        ])->save();

        return $bien->fresh() ?? $bien;
    }

    /**
     * Le péage de déclaration, quand l'exploitant en a ouvert un.
     *
     * FERMÉ PAR DÉFAUT, ET C'EST L'ESSENTIEL. Le tarif vaut zéro tant que
     * personne ne l'a réglé : la déclaration reste alors ce qu'elle doit être,
     * gratuite et immédiate. La garde ci-dessous ne coûte donc rien au parcours
     * ordinaire — elle ne se referme que sur décision explicite.
     *
     * ELLE EST ICI, ET PAS SEULEMENT DANS LE CONTRÔLEUR. Le contrôleur sait
     * ouvrir la page de l'opérateur et rend un 402 utile ; mais un péage qui
     * n'existerait QUE dans le contrôleur tomberait au premier autre appelant —
     * une commande d'exploitation, un import, un contrôleur ajouté plus tard.
     * Ce qui encaisse doit être gardé là où le geste s'accomplit.
     *
     * @throws PaymentRequiredException
     */
    private function assertPeageRegle(Asset $bien, User $detenteur): void
    {
        $montant = $this->tarifs->amount('theft_declaration');

        if ($montant === 0) {
            return;
        }

        // DÉJÀ PAYÉ, DÉJÀ DÛ. Le code arrive après le règlement : entre les
        // deux, l'application a pu se fermer, le code expirer, l'utilisateur en
        // redemander un. Aucun de ces incidents ne doit faire payer deux fois.
        if ($this->paiements->hasPaidFor($detenteur, $bien, PaymentPurpose::TheftDeclaration)) {
            return;
        }

        throw new PaymentRequiredException(
            PaymentPurpose::TheftDeclaration,
            $montant,
            'La déclaration de vol est soumise à un règlement de '.number_format($montant, 0, ',', ' ')
                .' FCFA. Le code de confirmation te sera envoyé dès le paiement reçu.',
        );
    }

    /**
     * Lève une déclaration de vol — bien retrouvé, ou erreur.
     *
     * @throws DomainException
     */
    public function clearStolen(Asset $bien, User $detenteur, string $code): Asset
    {
        $this->assertHolder($bien, $detenteur);

        if ($bien->life_status !== LifeStatus::Stolen) {
            throw new DomainException("Ce bien n'est pas déclaré volé.");
        }

        $this->otp->verify($this->destinationDe($detenteur), $code, OtpPurpose::SensitiveAction);

        $this->transitions->transitionTo(
            $bien,
            LifeStatus::Active,
            TriggerType::Owner,
            $detenteur->id,
            'Levée de la déclaration de vol par le déclarant',
        );

        // `stolen_declared_at` n'est PAS effacé : la trace de l'épisode reste
        // dans le rapport détaillé. Un bien déclaré volé puis retrouvé deux
        // fois n'a pas la même histoire qu'un bien sans incident.
        $bien->forceFill(['stolen_consolidated' => false])->save();

        return $bien->fresh() ?? $bien;
    }

    /**
     * Déclare un bien hors d'usage (ST-0606). L'identifiant n'est PAS libéré :
     * seule une décision de back-office peut le remettre en circulation, sans
     * quoi déclarer une épave deviendrait le moyen de recycler un identifiant
     * volé sur un autre bien.
     *
     * @throws DomainException
     */
    public function declareEndOfLife(Asset $bien, User $detenteur, string $code, ?string $motif = null): Asset
    {
        $this->assertHolder($bien, $detenteur);

        $this->otp->verify($this->destinationDe($detenteur), $code, OtpPurpose::SensitiveAction);

        $this->transitions->transitionTo(
            $bien,
            LifeStatus::EndOfLife,
            TriggerType::Owner,
            $detenteur->id,
            $motif ?? 'Bien déclaré hors d\'usage par le détenteur',
        );

        $proprietaire = $bien->owner;

        if ($proprietaire instanceof User) {
            $this->notifications->notify(
                $proprietaire,
                NotificationType::StatusChange,
                'Votre bien est déclaré hors d\'usage',
                'Son cycle de vie est clos. L\'identifiant reste réservé : sa remise en circulation suppose '.
                'une décision de la plateforme.',
                $bien,
            );
        }

        return $bien->fresh() ?? $bien;
    }

    /** @throws DomainException */
    private function assertHolder(Asset $bien, User $utilisateur): void
    {
        if ($bien->owner_user_id !== $utilisateur->id) {
            throw new DomainException('Seul le détenteur enregistré peut déclarer sur ce bien.');
        }

        if ($bien->active_flag === null) {
            throw new DomainException('Cet enregistrement est archivé.');
        }
    }

    /**
     * Où ce compte reçoit ses codes.
     *
     * PAS `->phone` EN DUR : un compte ouvert par adresse n'en a pas, et
     * l'interroger renverrait `null` — le service refuserait alors une
     * destination invalide, sans que rien n'explique pourquoi le titulaire ne
     * peut pas déclarer le vol de son propre bien.
     */
    private function destinationDe(User $compte): string
    {
        $destination = $compte->otpDestination();

        if ($destination === null) {
            throw new DomainException(
                'Ce compte n\'a ni numéro ni adresse : il ne peut recevoir aucun code. '
                .'Ajoutez une coordonnée avant de continuer.'
            );
        }

        return $destination;
    }
}
