<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Models\User;
use App\Services\Otp\ConfigurableOtpSender;
use App\Services\Settings\SettingsRepository;
use Throwable;

/**
 * Envoyer un code à un titulaire DÉJÀ CONNU.
 *
 * CE CAS N'EST PAS CELUI DE LA CONNEXION, et la différence est de sécurité.
 * À la connexion, le demandeur fournit une destination et le serveur doit s'en
 * méfier : accepter une adresse soumise laisserait détourner le code de
 * n'importe quel compte. Ici, l'utilisateur est déjà authentifié ou déjà payeur
 * identifié — la destination est LUE AU DOSSIER, jamais reçue. Il n'y a donc
 * rien à arbitrer, et c'est pourquoi ce chemin est court là où
 * `OtpAuthController::deliveryTarget()` est long.
 *
 * LE CANAL SUIT CE QUI EST BRANCHÉ. Tant qu'aucune passerelle SMS n'est
 * configurée, le code part par courriel — sinon il ne partirait nulle part, et
 * un utilisateur attendrait un SMS que rien n'a émis.
 *
 * L'ÉCHEC EST SILENCIEUX MAIS RENDU. Un code qui ne part pas ne doit jamais
 * emporter la transaction qui le déclenche : un webhook de paiement rejoué en
 * échec ferait rembourser ou redoubler un encaissement pour un simple courriel
 * indisponible. L'appelant reçoit `false` et décide.
 */
final class AccountOtpSender
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly SettingsRepository $settings,
    ) {}

    public function send(User $titulaire, OtpPurpose $motif): bool
    {
        $destination = $titulaire->otpDestination();

        if ($destination === null) {
            return false;
        }

        $adresse = is_string($titulaire->email) && $titulaire->email !== '' ? $titulaire->email : null;

        // Une destination qui EST une adresse s'auto-livre ; sinon, le canal
        // dépend de la passerelle réglée dans l'espace administrateur.
        $parCourriel = $this->otp->isEmail($destination)
            || $this->settings->get(ConfigurableOtpSender::PROVIDER_KEY) === 'mail';

        if ($parCourriel && $adresse === null && ! $this->otp->isEmail($destination)) {
            // Compte sans adresse alors que seul le courriel est branché :
            // le code n'a aucun chemin. Le dire à l'appelant vaut mieux que
            // de faire croire à un envoi.
            return false;
        }

        try {
            $this->otp->request(
                $destination,
                $motif,
                $parCourriel ? OtpChannel::Email : OtpChannel::Sms,
                $parCourriel ? ($this->otp->isEmail($destination) ? $destination : $adresse) : null,
            );
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
