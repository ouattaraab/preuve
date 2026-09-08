<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\LifeStatus;
use App\Enums\NotificationType;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\TransferStatus;
use App\Enums\TriggerType;
use App\Enums\TrustLevel;
use App\Mail\TransferInvitationMail;
use App\Models\Asset;
use App\Models\AssetStatusHistory;
use App\Models\Transfer;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Transfert de propriété à double validation (ST-0601 à ST-0603).
 *
 * LE POINT CRITIQUE est la finalisation : l'ancien enregistrement est archivé
 * et le nouveau créé DANS LA MÊME TRANSACTION, sous verrou de ligne (règle
 * métier absolue n° 3). Entre les deux, il existe un instant où l'identifiant
 * n'a aucun enregistrement actif ; si cet instant était visible, une
 * consultation y répondrait « bien inconnu » sur un bien parfaitement
 * enregistré, et un enregistrement concurrent pourrait s'y glisser.
 *
 * LE NOUVEAU BIEN REPART EN « DÉCLARÉ, NON VÉRIFIÉ ». Les justificatifs
 * appuyaient la propriété du VENDEUR : une carte grise à son nom ne prouve
 * rien sur l'acheteur. Hériter du niveau de fiabilité reviendrait à vendre
 * avec le bien une confiance qui n'a pas été vérifiée pour son nouveau
 * détenteur — exactement ce qu'un fraudeur chercherait à acheter.
 *
 * LE TRANSFERT S'ADRESSE À UN NUMÉRO, pas à un compte. Exiger que l'acheteur
 * soit déjà inscrit ferait échouer la vente au marché, là où elle se conclut.
 * Le compte est rattaché au moment où l'acheteur confirme.
 *
 * Le statut antérieur du bien est mémorisé : une annulation ou une expiration
 * doit y ramener. Sans cette mémoire, un véhicule de loueur reviendrait
 * « Actif » au lieu de « En location », c'est-à-dire vendable sans
 * avertissement.
 */
final class TransferService
{
    /** Fenêtre laissée à l'acheteur pour confirmer (§4.4). */
    private const EXPIRY_DAYS = 7;

    public function __construct(
        private readonly StatusTransitionService $transitions,
        private readonly OtpService $otp,
        private readonly AuditChain $auditChain,
        private readonly NotificationService $notifications,
        private readonly AccountOtpSender $codes,
    ) {}

    /**
     * Ouvre un transfert et bascule le bien en « Transfert en cours ».
     *
     * @throws DomainException
     */
    public function initiate(
        Asset $bien,
        User $vendeur,
        string $telephoneAcheteur,
        ?string $adresseAcheteur = null,
    ): Transfer {
        if ($bien->owner_user_id !== $vendeur->id) {
            throw new DomainException('Seul le détenteur enregistré peut céder ce bien.');
        }

        if ($bien->active_flag === null) {
            throw new DomainException('Cet enregistrement est archivé : il ne peut plus être transféré.');
        }

        // La friction est légitime ici (CT-06) : céder un bien engage la
        // propriété, et un transfert opéré depuis un compte non vérifié serait
        // le moyen le plus simple de blanchir un bien volé.
        if (! $vendeur->hasVerifiedIdentity()) {
            throw new DomainException(
                'Vérifiez votre identité avant de céder un bien : le transfert engage la propriété.'
            );
        }

        $destinataire = $this->otp->normalizeDestination($telephoneAcheteur);

        // L'ADRESSE PRÉVIENT, LE NUMÉRO DÉSIGNE. C'est sur le numéro que porte
        // le contrôle au moment d'accepter : le laisser remplacer par une
        // adresse ferait basculer une règle de propriété pour une commodité de
        // notification.
        $adresse = $adresseAcheteur === null || trim($adresseAcheteur) === ''
            ? null
            : $this->otp->normalizeDestination($adresseAcheteur);

        if ($adresse !== null && ! $this->otp->isEmail($adresse)) {
            throw new DomainException('L\'adresse de l\'acheteur n\'est pas une adresse électronique.');
        }

        if ($destinataire === $vendeur->phone) {
            throw new DomainException('Le numéro de l\'acheteur ne peut pas être le vôtre.');
        }

        if ($adresse !== null && $adresse === $vendeur->email) {
            throw new DomainException('L\'adresse de l\'acheteur ne peut pas être la vôtre.');
        }

        if ($this->hasOpenTransfer($bien)) {
            throw new DomainException('Un transfert est déjà en cours sur ce bien.');
        }

        // LE STATUT EST CONTRÔLÉ AVANT D'ÉCRIRE QUOI QUE CE SOIT.
        //
        // La bascule vers « Transfert en cours » venait après la création du
        // transfert, et c'est elle qui refusait : le refus arrivait donc une
        // fois la ligne écrite et validée. Elle survivait à l'exception, et
        // `hasOpenTransfer()` la retrouvait pendant sept jours — le bien
        // devenait incessible par un transfert qui n'avait jamais commencé.
        $this->assertCessible($bien);

        $statutAnterieur = $bien->life_status;

        $transfert = $this->auditChain->transaction(
            function () use ($bien, $vendeur, $destinataire, $adresse, $statutAnterieur): array {
                $transfert = Transfer::create([
                    'asset_id' => $bien->id,
                    'from_user_id' => $vendeur->id,
                    'to_phone' => $destinataire,
                    'to_email' => $adresse,
                    // 32 octets tirés au hasard : une adresse en
                    // `/cession/42` s'énumérerait, et livrerait la liste des
                    // biens qui changent de mains cette semaine.
                    'invite_token' => bin2hex(random_bytes(32)),
                    'status' => TransferStatus::Initiated,
                    'previous_life_status' => $statutAnterieur,
                    'expires_at' => now()->addDays(self::EXPIRY_DAYS),
                ]);

                return [
                    'result' => $transfert,
                    'actorType' => ActorType::User,
                    'actorId' => $vendeur->id,
                    'action' => 'transfer.initiated',
                    'entityType' => 'transfer',
                    'entityId' => $transfert->id,
                    // Jamais le numéro de l'acheteur : audit_log est
                    // inaltérable et désigne ici quelqu'un qui n'a peut-être
                    // même pas de compte.
                    'payload' => ['asset_id' => $bien->id, 'from_status' => $statutAnterieur->value],
                ];
            }
        );

        // Hors de la transaction d'audit : la bascule de statut écrit sa propre
        // entrée, et AuditChain refuse d'être imbriquée.
        $this->transitions->transitionTo(
            $bien,
            LifeStatus::Transferring,
            TriggerType::Transfer,
            $vendeur->id,
            'Transfert initié vers un acheteur',
        );

        // LE CODE PART LÀ OÙ L'ACHETEUR PEUT LE LIRE. Tant qu'aucune
        // passerelle SMS n'est branchée, un code adressé à un numéro ne part
        // nulle part : le transfert suivait alors tout son cours côté vendeur
        // pendant que l'acheteur n'était jamais prévenu, et la cession
        // expirait au bout de sept jours sans que personne comprenne.
        $this->otp->request(
            $this->destinationDuTransfert($transfert),
            OtpPurpose::Transfer,
            $adresse !== null ? OtpChannel::Email : OtpChannel::Sms,
            $adresse,
        );

        // L'INVITATION EST UN SECOND MESSAGE, et ce n'est pas un doublon : le
        // code seul arrive sans contexte chez quelqu'un qui n'a peut-être
        // jamais entendu parler de PREUVE, et se lit alors comme une
        // escroquerie. Elle dit quel bien, et ce qu'il y a à faire ; le code,
        // lui, autorise. Deux chemins distincts pour deux rôles distincts.
        if ($adresse !== null) {
            $this->inviter($transfert, $bien, $adresse);
        }

        return $transfert;
    }

    /**
     * Envoie l'invitation à confirmer.
     *
     * ELLE NE NOMME PAS LE VENDEUR (règle métier absolue n° 4). Le bien suffit
     * à reconnaître la transaction : celui qui vient d'acheter une moto sait
     * laquelle. Nommer le cédant permettrait d'apprendre le nom de n'importe
     * quel propriétaire en ouvrant une cession vers une adresse quelconque.
     *
     * UN ÉCHEC D'ENVOI NE DÉFAIT PAS LE TRANSFERT. Il est déjà écrit, le bien
     * est déjà basculé, et le lever ici laisserait un bien en « transfert en
     * cours » sans transfert. On journalise : le vendeur voit sa cession en
     * attente et peut la relancer ou l'annuler.
     */
    private function inviter(Transfer $transfert, Asset $bien, string $adresse): void
    {
        $jeton = $transfert->invite_token;

        if ($jeton === null) {
            return;
        }

        try {
            Mail::to($adresse)->send(new TransferInvitationMail(
                lien: url('/cession/'.$jeton),
                bien: $this->libelleDuBien($bien),
                numero: (string) $bien->identifier_raw,
                joursRestants: self::EXPIRY_DAYS,
            ));
        } catch (Throwable $e) {
            Log::warning('Invitation de cession non délivrée', [
                'transfer_id' => $transfert->id,
                'exception' => $e::class,
                // JAMAIS L'ADRESSE : les journaux sortent de la plateforme
                // (supervision, sauvegardes) et une adresse y est une donnée
                // personnelle au sens de la loi 2013-450.
            ]);
        }
    }

    /**
     * Ce qu'on montre du bien : sa catégorie et sa marque, rien de plus.
     *
     * Le contenu des `attributes` est saisi par le vendeur ; le passer entier
     * dans un courriel lui donnerait un canal de texte libre vers une adresse
     * qu'il choisit. Deux champs connus, et c'est tout.
     */
    private function libelleDuBien(Asset $bien): string
    {
        $attributs = $bien->getAttribute('attributes');
        $marque = is_array($attributs) && is_string($attributs['brand_model'] ?? null)
            ? trim($attributs['brand_model'])
            : '';

        $categorie = ucfirst((string) $bien->asset_category_key);

        return $marque === '' ? $categorie : $categorie.' — '.mb_substr($marque, 0, 80);
    }

    /**
     * (Ré)émet le code de l'ACHETEUR, et rend une forme masquée de l'endroit
     * où il vient de partir.
     *
     * CETTE MÉTHODE EXISTE PARCE QUE LE CODE ET SA VÉRIFICATION SE CHERCHAIENT
     * AILLEURS L'UN DE L'AUTRE. L'application demandait un code sur la
     * coordonnée du COMPTE qui se présente ; la confirmation le cherchait sur
     * celle du TRANSFERT. Dès qu'une adresse était donnée — le cas recommandé,
     * et le seul par lequel l'acheteur est réellement prévenu — les deux
     * différaient, et aucun code saisi n'était jamais reconnu.
     *
     * ELLE SERT AUSSI À REDEMANDER. Le lien d'invitation vit sept jours, le
     * code cinq minutes : sans renvoi, l'acheteur qui ouvre son courriel un
     * quart d'heure plus tard n'avait plus aucun recours.
     *
     * UN CODE ENCORE VALABLE N'EST PAS REMPLACÉ, IL EST RAPPELÉ. Deux envois
     * rapprochés se heurtent au délai de soixante secondes, et le demandeur
     * recevait « Trop de demandes » au lieu du champ de saisie — juste après
     * l'ouverture de la cession, c'est-à-dire au moment précis où l'acheteur
     * ouvre l'écran. Le code déjà parti fait l'affaire ; il suffit de le dire.
     *
     * @return array{sent_to: string, fresh: bool} `fresh` est faux quand le code
     *                                             en attente est celui d'avant
     *
     * @throws DomainException
     */
    public function sendCodeToBuyer(Transfer $transfert): array
    {
        $this->assertOpen($transfert);

        $destination = $this->destinationDuTransfert($transfert);

        if ($this->otp->hasPendingCode($destination, OtpPurpose::Transfer)) {
            return ['sent_to' => $this->masquer($destination), 'fresh' => false];
        }

        // Mêmes canal et destination qu'à l'ouverture de la cession : c'est ce
        // que `confirmByBuyer()` interrogera, et un écart d'un seul caractère
        // rendrait le code inutilisable.
        $this->otp->request(
            $destination,
            OtpPurpose::Transfer,
            $transfert->to_email !== null ? OtpChannel::Email : OtpChannel::Sms,
            $transfert->to_email,
        );

        return ['sent_to' => $this->masquer($destination), 'fresh' => true];
    }

    /**
     * Émet le code du VENDEUR et rend, masqué, l'endroit où il est parti.
     *
     * RIEN NE L'ÉMETTAIT. `confirmBySeller()` cherchait un code sur la
     * coordonnée du compte cédant, et aucune ligne de l'application n'en
     * produisait pour ce motif : seule une demande de connexion, avec un autre
     * motif, pouvait en créer un — et elle n'aurait pas convenu.
     *
     * @return array{sent_to: string, fresh: bool}
     *
     * @throws DomainException
     */
    public function sendCodeToSeller(Transfer $transfert, User $vendeur): array
    {
        $this->assertOpen($transfert);

        if ($transfert->from_user_id !== $vendeur->id) {
            throw new DomainException('Seul le vendeur peut demander ce code.');
        }

        $affiche = $this->masquer($this->livraisonDe($vendeur));

        if ($this->otp->hasPendingCode($this->destinationDe($vendeur), OtpPurpose::Transfer)) {
            return ['sent_to' => $affiche, 'fresh' => false];
        }

        // Indexé sur la coordonnée du compte — celle que `confirmBySeller()`
        // interrogera — mais livré là où il peut réellement arriver.
        //
        // LE MESSAGE N'ACCUSE PAS LA MAUVAISE CAUSE. Le compte sans coordonnée
        // a déjà été écarté plus haut, par `livraisonDe()` : ce qui reste ici,
        // c'est le rythme d'envoi ou une passerelle indisponible — et conseiller
        // d'ajouter une adresse enverrait le vendeur corriger ce qui va bien.
        if (! $this->codes->send($vendeur, OtpPurpose::Transfer)) {
            throw new DomainException(
                'Le code n\'a pas pu partir. Patientez une minute, puis réessayez.'
            );
        }

        return ['sent_to' => $affiche, 'fresh' => true];
    }

    /** @throws DomainException */
    public function confirmBySeller(Transfer $transfert, User $vendeur, string $code): Transfer
    {
        $this->assertOpen($transfert);

        if ($transfert->from_user_id !== $vendeur->id) {
            throw new DomainException('Seul le vendeur peut confirmer de son côté.');
        }

        $this->otp->verify($this->destinationDe($vendeur), $code, OtpPurpose::Transfer);

        $transfert->forceFill(['seller_otp_at' => now()])->save();

        return $this->completeIfReady($transfert);
    }

    /**
     * Confirmation de l'acheteur. C'est ici que son compte est rattaché : il
     * peut n'avoir été qu'un numéro jusque-là.
     *
     * @throws DomainException
     */
    public function confirmByBuyer(Transfer $transfert, User $acheteur, string $code): Transfer
    {
        $this->assertOpen($transfert);

        // LE DESTINATAIRE SE RECONNAÎT PAR L'UNE OU L'AUTRE DE SES
        // COORDONNÉES. Exiger le numéro fermerait la cession à un acheteur
        // ouvert par adresse — qui est justement celui que l'adresse a permis
        // de prévenir. La comparaison ignore les valeurs nulles : sans cela,
        // deux comptes sans numéro se reconnaîtraient l'un dans le transfert de
        // l'autre.
        $parNumero = $acheteur->phone !== null && $acheteur->phone === $transfert->to_phone;
        $parAdresse = $acheteur->email !== null && $acheteur->email === $transfert->to_email;

        if (! $parNumero && ! $parAdresse) {
            throw new DomainException('Ce transfert ne vous est pas destiné.');
        }

        // LE CODE SE VÉRIFIE LÀ OÙ IL A ÉTÉ ÉMIS, c'est-à-dire sur la
        // coordonnée du TRANSFERT — pas sur celle du compte qui se présente.
        //
        // Le défi était indexé sur le numéro et livré à l'adresse : un acheteur
        // ouvert par adresse recevait bien son code et ne pouvait JAMAIS le
        // valider, parce que la vérification cherchait un défi à son adresse
        // quand la base en portait un au numéro. Il ne restait qu'à en demander
        // un second — ce qu'aucun acheteur ne devine, et ce qu'aucun écran ne
        // proposait depuis le lien d'invitation.
        $this->otp->verify($this->destinationDuTransfert($transfert), $code, OtpPurpose::Transfer);

        return $this->enregistrerAccordAcheteur($transfert, $acheteur);
    }

    /**
     * Confirmation venue du LIEN D'INVITATION, où l'acheteur n'est encore
     * qu'une adresse : pas de compte, pas de session, rien à quoi se rattacher.
     *
     * LE CODE D'ABORD, LE COMPTE ENSUITE. La page ouvrait le compte avant de
     * savoir si le code était bon : une saisie fautive laissait derrière elle un
     * compte que personne n'avait demandé, sur une adresse dont la maîtrise
     * n'était pas prouvée — et hors de la chaîne d'audit, alors que toute autre
     * création de compte y figure.
     *
     * @throws DomainException
     */
    public function confirmByBuyerAddress(Transfer $transfert, string $adresse, string $code): Transfer
    {
        $this->assertOpen($transfert);

        if ($transfert->to_email === null
            || $transfert->to_email !== $this->otp->normalizeDestination($adresse)) {
            throw new DomainException('Ce transfert ne vous est pas destiné.');
        }

        $this->otp->verify($transfert->to_email, $code, OtpPurpose::Transfer);

        return $this->enregistrerAccordAcheteur($transfert, $this->compteDeLAdresse($transfert->to_email));
    }

    /**
     * Le compte du destinataire, créé au besoin — et journalisé comme toute
     * autre création de compte.
     *
     * SANS NUMÉRO : celui qui accepte par courriel n'en a pas forcément donné,
     * et en inventer un rendrait le compte irrécupérable. L'adresse, elle, est
     * marquée vérifiée : le code qu'on vient de reconnaître y a été lu.
     */
    private function compteDeLAdresse(string $adresse): User
    {
        $compte = User::query()->where('email', $adresse)->first();

        if ($compte instanceof User) {
            return $compte;
        }

        return $this->auditChain->transaction(function () use ($adresse): array {
            $nouveau = User::create(['email' => $adresse]);
            $nouveau->forceFill(['email_verified_at' => now()])->save();

            return [
                'result' => $nouveau,
                'actorType' => ActorType::User,
                'actorId' => $nouveau->id,
                'action' => 'auth.account_created',
                'entityType' => 'user',
                'entityId' => $nouveau->id,
                // Jamais l'adresse : audit_log est inaltérable et survivrait à
                // tout exercice du droit à l'effacement (Loi 2013-450).
                'payload' => ['channel' => 'email', 'origin' => 'transfer_invitation'],
            ];
        });
    }

    /**
     * L'accord de l'acheteur est inscrit, et le vendeur prévenu que son tour
     * est venu.
     *
     * SANS CET AVERTISSEMENT, LA CESSION EXPIRE. Le vendeur devait ouvrir
     * l'application au hasard pour découvrir qu'on l'attendait ; sept jours plus
     * tard le transfert se refermait faute d'un geste que personne ne lui avait
     * réclamé, et l'acheteur restait avec un bien enregistré au nom d'un autre.
     */
    private function enregistrerAccordAcheteur(Transfer $transfert, User $acheteur): Transfer
    {
        $transfert->forceFill([
            'to_user_id' => $acheteur->id,
            'buyer_otp_at' => now(),
            'status' => TransferStatus::BuyerConfirmed,
        ])->save();

        if (! $transfert->bothConfirmed()) {
            $this->prevenirLeVendeur($transfert);
        }

        return $this->completeIfReady($transfert);
    }

    private function prevenirLeVendeur(Transfer $transfert): void
    {
        $vendeur = User::query()->whereKey($transfert->from_user_id)->first();

        if (! $vendeur instanceof User) {
            return;
        }

        $this->notifications->notify(
            $vendeur,
            // Type CRITIQUE, donc non désactivable : ne pas le recevoir revient
            // à laisser la cession expirer.
            NotificationType::TransferInvitation,
            'Votre acheteur a accepté',
            'Il ne manque plus que votre confirmation pour que le bien change de mains. '
            .'Sans elle, la cession expire et le bien vous reste.',
            $transfert->asset instanceof Asset ? $transfert->asset : null,
        );
    }

    /**
     * Annulation par le vendeur, tant que le transfert n'est pas finalisé.
     * L'acheteur, lui, n'annule pas : ne pas confirmer suffit, et le transfert
     * expirera de lui-même.
     *
     * @throws DomainException
     */
    public function cancel(Transfer $transfert, User $vendeur): Transfer
    {
        $this->assertOpen($transfert);

        if ($transfert->from_user_id !== $vendeur->id) {
            throw new DomainException('Seul le vendeur peut annuler ce transfert.');
        }

        $this->close($transfert, TransferStatus::Cancelled, $vendeur->id, 'Transfert annulé par le vendeur');

        return $transfert->fresh() ?? $transfert;
    }

    /** Expiration à J+7 : le bien revient à son statut antérieur. */
    public function expire(Transfer $transfert): Transfer
    {
        $this->close($transfert, TransferStatus::Expired, null, 'Transfert expiré sans confirmation');

        return $transfert->fresh() ?? $transfert;
    }

    /**
     * Chaîne des détenteurs (ST-0603) : la suite des enregistrements successifs
     * pour un même identifiant, du plus ancien au plus récent.
     *
     * Rendue en interne dans son intégralité ; l'exposition publique, elle, se
     * limite au NOMBRE de détenteurs et aux dates (règle métier absolue n° 4 —
     * l'acheteur d'un rapport n'apprend jamais qui a possédé le bien).
     *
     * @return list<Asset>
     */
    public function holderChain(Asset $bien): array
    {
        $chaine = Asset::query()
            ->where('identifier_normalized', $bien->identifier_normalized)
            ->orderBy('registered_at')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values($chaine);
    }

    /**
     * Change le détenteur d'un bien : archive l'enregistrement courant et en
     * crée un nouveau au nom du repreneur, DANS LA MÊME TRANSACTION et sous
     * verrou de ligne (règle métier absolue n° 3).
     *
     * Public parce que l'arbitrage d'une réclamation opère le même changement
     * de main, sur décision d'un agent plutôt que par double confirmation. Un
     * second chemin qui réimplémenterait l'archivage finirait par diverger de
     * celui-ci — et c'est précisément la règle qu'on ne peut pas se permettre
     * de voir diverger.
     *
     * @param  int  $repreneurId  compte qui reçoit le bien
     */
    public function handOver(
        Asset $bien,
        int $repreneurId,
        TriggerType $declencheur,
        ?int $acteurId,
        string $motif,
    ): Asset {
        $maintenant = now();

        $ancien = Asset::whereKey($bien->id)->lockForUpdate()->firstOrFail();

        if ($ancien->active_flag === null) {
            throw new DomainException('Cet enregistrement a déjà été archivé.');
        }

        // Archivage d'abord : l'index unique (identifier_normalized,
        // active_flag) interdirait sinon l'insertion du nouvel actif.
        $ancien->forceFill(['active_flag' => null])->save();

        $nouveau = Asset::create([
            'public_ref' => $this->generatePublicRef(),
            'owner_user_id' => $repreneurId,
            'company_id' => null,
            'previous_asset_id' => $ancien->id,
            'asset_category_key' => $ancien->asset_category_key,
            'identifier_type' => $ancien->identifier_type,
            'identifier_raw' => $ancien->identifier_raw,
            'identifier_normalized' => $ancien->identifier_normalized,
            'active_flag' => 1,
            'attributes' => $ancien->getAttribute('attributes'),
            // Repart en « Déclaré, non vérifié » : les justificatifs
            // appuyaient la propriété du détenteur précédent, pas celle du
            // repreneur.
            'trust_level' => TrustLevel::Declared,
            'life_status' => LifeStatus::Active,
            'provisional_until' => null,
            'registered_at' => $maintenant,
        ]);

        AssetStatusHistory::create([
            'asset_id' => $nouveau->id,
            'from_status' => null,
            'to_status' => LifeStatus::Active,
            'to_trust' => TrustLevel::Declared,
            'trigger_type' => $declencheur,
            'actor_user_id' => $acteurId,
            'reason' => $motif,
            'created_at' => $maintenant->format('Y-m-d H:i:s'),
        ]);

        return $nouveau;
    }

    private function completeIfReady(Transfer $transfert): Transfer
    {
        if (! $transfert->bothConfirmed()) {
            return $transfert;
        }

        return $this->complete($transfert);
    }

    /**
     * Finalisation : archivage de l'ancien et création du nouveau dans la MÊME
     * transaction, sous verrou de ligne (règle métier absolue n° 3).
     */
    private function complete(Transfer $transfert): Transfer
    {
        $acheteurId = $transfert->to_user_id;

        if ($acheteurId === null) {
            throw new DomainException('Aucun compte acheteur rattaché à ce transfert.');
        }

        $acheve = $this->auditChain->transaction(function () use ($transfert, $acheteurId): array {
            $ancien = Asset::whereKey($transfert->asset_id)->firstOrFail();

            // Point de passage unique de la règle 3, partagé avec le transfert
            // forcé prononcé en arbitrage.
            $nouveau = $this->handOver(
                $ancien,
                $acheteurId,
                TriggerType::Transfer,
                $acheteurId,
                'Enregistrement né du transfert #'.$transfert->id,
            );

            $transfert->forceFill([
                'status' => TransferStatus::Completed,
                'completed_at' => now(),
                'created_asset_id' => $nouveau->id,
            ])->save();

            return [
                'result' => $transfert,
                'actorType' => ActorType::User,
                'actorId' => $acheteurId,
                'action' => 'transfer.completed',
                'entityType' => 'transfer',
                'entityId' => $transfert->id,
                'payload' => [
                    'archived_asset_id' => $ancien->id,
                    'created_asset_id' => $nouveau->id,
                ],
            ];
        });

        // HORS DE LA TRANSACTION D'AUDIT, qui refuse d'être imbriquée — et
        // parce qu'un acheminement lent ne doit pas retenir la finalisation.
        $this->prevenirDeLAchevement($acheve);

        return $acheve;
    }

    /**
     * Les deux parties apprennent que le bien a changé de mains.
     *
     * PERSONNE N'ÉTAIT PRÉVENU. Le bien quittait un inventaire et entrait dans
     * un autre en silence : celui des deux qui avait confirmé le premier — le
     * plus souvent l'acheteur, venu par le lien — n'apprenait jamais que la
     * cession avait abouti, et le vendeur voyait son bien disparaître de sa
     * liste sans explication. Le type `TransferCompleted` existait depuis
     * EP-10 ; aucune ligne ne l'émettait.
     *
     * AUCUNE DES DEUX NOTIFICATIONS NE NOMME L'AUTRE PARTIE (règle métier
     * absolue n° 4) : chacun sait avec qui il a traité parce qu'il l'avait en
     * face de lui, et la plateforme n'a pas à le lui confirmer.
     */
    private function prevenirDeLAchevement(Transfer $transfert): void
    {
        $nouveau = $transfert->created_asset_id === null
            ? null
            : Asset::query()->whereKey($transfert->created_asset_id)->first();

        $vendeur = User::query()->whereKey($transfert->from_user_id)->first();

        if ($vendeur instanceof User) {
            $this->notifications->notify(
                $vendeur,
                NotificationType::TransferCompleted,
                'Cession finalisée',
                'Ce bien n\'est plus enregistré à votre nom. Vous ne pouvez plus le déclarer '
                .'volé ni le céder.',
                // L'ANCIEN ENREGISTREMENT, celui que le vendeur connaît : le
                // nouveau ne lui appartient pas et sa fiche lui serait refusée.
                $transfert->asset instanceof Asset ? $transfert->asset : null,
            );
        }

        $acheteur = $transfert->to_user_id === null
            ? null
            : User::query()->whereKey($transfert->to_user_id)->first();

        if ($acheteur instanceof User) {
            $this->notifications->notify(
                $acheteur,
                NotificationType::TransferCompleted,
                'Le bien est à votre nom',
                'La cession est enregistrée. Le bien repart au niveau « Déclaré » : les '
                .'justificatifs du vendeur prouvaient SA propriété, pas la vôtre.',
                $nouveau instanceof Asset ? $nouveau : null,
            );
        }
    }

    private function close(Transfer $transfert, TransferStatus $issue, ?int $acteurId, string $motif): void
    {
        $bien = $transfert->asset;

        $transfert->forceFill(['status' => $issue])->save();

        if (! $bien instanceof Asset || $bien->life_status !== LifeStatus::Transferring) {
            // Le bien a pu changer de statut entre-temps — une déclaration de
            // vol pendant le transfert, par exemple. On ne le ramène pas de
            // force à un statut périmé.
            return;
        }

        $this->transitions->transitionTo(
            $bien,
            $transfert->previous_life_status,
            $acteurId === null ? TriggerType::System : TriggerType::Owner,
            $acteurId,
            $motif,
        );

        $detenteur = $bien->owner;

        if ($detenteur instanceof User) {
            $this->notifications->notify(
                $detenteur,
                NotificationType::StatusChange,
                $issue === TransferStatus::Expired ? 'Transfert expiré' : 'Transfert annulé',
                $issue === TransferStatus::Expired
                    ? "L'acheteur n'a pas confirmé dans les 7 jours : votre bien retrouve son statut précédent."
                    : 'Le transfert a été annulé : votre bien retrouve son statut précédent.',
                $bien,
            );
        }
    }

    private function assertOpen(Transfer $transfert): void
    {
        if (! $transfert->status->isOpen()) {
            throw new DomainException('Ce transfert n\'est plus en cours.');
        }

        if ($transfert->expires_at->isPast()) {
            throw new DomainException('Ce transfert a expiré : le vendeur doit en initier un nouveau.');
        }
    }

    /**
     * Refuse une cession que la matrice des statuts n'autoriserait pas, EN
     * LANGAGE COURANT (CT-04).
     *
     * Le message de `TransitionInterditeException` nomme des codes — « V-PRV →
     * V-VTE » — que l'interface ne montre jamais, et il arrivait jusqu'au
     * vendeur par le `catch (DomainException)` du contrôleur. Ici, chaque refus
     * dit la RAISON, parce qu'elle est différente à chaque fois et qu'aucune
     * n'est un défaut de la plateforme.
     *
     * @throws DomainException
     */
    private function assertCessible(Asset $bien): void
    {
        if (StatusTransitionService::isAllowed($bien->life_status, LifeStatus::Transferring, TriggerType::Transfer)) {
            return;
        }

        throw new DomainException(match ($bien->life_status) {
            LifeStatus::Provisional => 'Cet enregistrement est trop récent pour être cédé : '
                .'la fenêtre de contestation doit s\'achever d\'abord.',
            LifeStatus::Stolen => 'Ce bien est déclaré volé. Levez la déclaration avant de le céder.',
            LifeStatus::Disputed => 'La propriété de ce bien est contestée : la cession attendra '
                .'la décision.',
            LifeStatus::EndOfLife => 'Ce bien est déclaré hors d\'usage : il ne se cède plus.',
            default => 'Ce bien ne peut pas être cédé tant qu\'il est « '
                .$bien->life_status->label().' ».',
        });
    }

    /**
     * Où le code de ce titulaire arrive RÉELLEMENT.
     *
     * Ce n'est pas toujours la coordonnée qui indexe le défi : tant qu'aucune
     * passerelle SMS n'est branchée, un code indexé sur un numéro est livré à
     * l'adresse du compte. Annoncer le numéro ferait chercher un SMS qui n'est
     * jamais parti.
     */
    private function livraisonDe(User $compte): string
    {
        $adresse = $compte->email;

        return $this->codes->smsAbsent() && is_string($adresse) && $adresse !== ''
            ? $adresse
            : $this->destinationDe($compte);
    }

    /**
     * Une destination reconnaissable par son titulaire, illisible par-dessus
     * son épaule.
     *
     * Elle n'est rendue qu'à la partie concernée — jamais l'adresse de
     * l'acheteur au vendeur (règle métier absolue n° 4) — mais un écran se lit
     * à plusieurs, et il suffit de savoir OÙ chercher, pas QUOI.
     */
    private function masquer(string $destination): string
    {
        if ($this->otp->isEmail($destination)) {
            [$local, $domaine] = explode('@', $destination, 2);

            return mb_substr($local, 0, 1)
                .str_repeat('•', max(1, mb_strlen($local) - 1))
                .'@'.$domaine;
        }

        return str_repeat('•', max(0, mb_strlen($destination) - 2)).mb_substr($destination, -2);
    }

    private function hasOpenTransfer(Asset $bien): bool
    {
        return Transfer::query()
            ->where('asset_id', $bien->id)
            ->whereIn('status', [TransferStatus::Initiated->value, TransferStatus::BuyerConfirmed->value])
            ->where('expires_at', '>', now())
            ->exists();
    }

    /** Même alphabet que l'enregistrement : lisible au téléphone, sans caractère confondable. */
    private function generatePublicRef(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

        do {
            $reference = 'PRV-';

            for ($i = 0; $i < 8; $i++) {
                $reference .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (DB::table('assets')->where('public_ref', $reference)->exists());

        return $reference;
    }

    /**
     * Où ce compte reçoit ses codes.
     *
     * PAS `->phone` EN DUR : un compte ouvert par adresse n'en a pas, et
     * l'interroger renverrait `null` — le service refuserait alors une
     * destination invalide, sans que rien n'explique pourquoi le titulaire ne
     * peut pas déclarer le vol de son propre bien.
     */
    /**
     * La coordonnée sur laquelle le défi de CE transfert est indexé.
     *
     * L'ADRESSE PRIME QUAND ELLE EXISTE, parce que c'est là que le code part :
     * tant qu'aucune passerelle SMS n'est branchée, un défi indexé sur un
     * numéro serait un défi que personne ne peut résoudre.
     */
    private function destinationDuTransfert(Transfer $transfert): string
    {
        return $transfert->to_email ?? $transfert->to_phone;
    }

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
