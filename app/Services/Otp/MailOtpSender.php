<?php

declare(strict_types=1);

namespace App\Services\Otp;

use App\Enums\OtpPurpose;
use App\Mail\OtpCodeMail;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Achemine le code par courriel (décision D7 — « repli OTP »).
 *
 * Ce canal existe parce qu'aucun fournisseur SMS n'est arbitré : il permet
 * d'ouvrir la plateforme sans attendre un contrat d'agrégateur. Ce n'est pas un
 * équivalent du SMS, et il ne faut pas le présenter comme tel.
 *
 * IL REFUSE UNE DESTINATION QUI N'EST PAS UNE ADRESSE. Un numéro de téléphone
 * passé ici ne produirait aucune erreur de transport : le message partirait vers
 * un destinataire inexistant, l'envoi serait compté comme réussi, et
 * l'utilisateur attendrait indéfiniment un code jamais expédié. Mieux vaut un
 * refus net au moment de l'émission.
 *
 * LE CODE N'APPARAÎT NI DANS LES JOURNAUX NI DANS LES MESSAGES D'ERREUR. Seule
 * la classe de l'échec est consignée : le message d'origine d'une passerelle
 * peut reprendre le corps expédié, donc le code lui-même.
 *
 * CE QUE CE CANAL VAUT, ET CE QU'IL NE VAUT PAS. Une boîte de courriel se
 * compromet plus discrètement qu'une carte SIM, et elle est souvent ouverte sur
 * le même appareil que l'application — un vol de téléphone donne alors les deux
 * facteurs d'un coup. Pour un compte qui autorise des transferts de propriété,
 * c'est une protection moindre. À remplacer par le SMS dès qu'un fournisseur
 * est arbitré, sans que ce remplacement demande une livraison : le canal se
 * choisit depuis l'espace administrateur.
 */
final class MailOtpSender implements OtpSender
{
    public function send(string $destination, string $code, OtpPurpose $purpose): void
    {
        if (! filter_var($destination, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                'Le canal courriel a reçu une destination qui n\'est pas une adresse. Le code n\'a pas été '.
                'expédié : un envoi vers un destinataire inexistant se compterait comme réussi et laisserait '.
                'l\'utilisateur attendre indéfiniment.'
            );
        }

        $ttl = config('preuve.otp.ttl_minutes');
        $ttl = is_numeric($ttl) ? (int) $ttl : 5;

        try {
            Mail::to($destination)->send(new OtpCodeMail($code, $purpose, $ttl));
        } catch (Throwable $e) {
            // La classe seule : le message d'origine peut contenir le corps
            // expédié, donc le code.
            throw new RuntimeException(
                'Le code n\'a pas pu être expédié par courriel ('.$e::class.'). Vérifiez la passerelle : '.
                'php artisan preuve:check-mail'
            );
        }
    }
}
