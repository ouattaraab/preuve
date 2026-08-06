<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * L'invitation d'un acheteur à confirmer une cession (ST-0601).
 *
 * POURQUOI ELLE EXISTE. Le code seul arrive sans contexte : « votre code de
 * transfert de propriété : 481203 », adressé à quelqu'un qui n'a peut-être
 * jamais entendu parler de PREUVE. Un message pareil se lit comme une tentative
 * d'escroquerie — et il a raison de se lire ainsi, parce qu'un code non
 * sollicité en est presque toujours une. Cette lettre-ci dit QUI a fait quoi,
 * SUR QUEL BIEN, et CE QU'IL Y A À FAIRE.
 *
 * ELLE NE NOMME PAS LE VENDEUR (règle métier absolue n° 4). Le bien suffit à
 * reconnaître la transaction : celui qui vient d'acheter une moto sait laquelle.
 * Nommer le cédant ferait sortir une identité de la plateforme vers une adresse
 * que personne n'a vérifiée au moment de l'envoi — il suffirait d'ouvrir une
 * cession vers une adresse quelconque pour apprendre le nom d'un propriétaire.
 *
 * ELLE NE PORTE PAS LE CODE. Deux messages, deux chemins : le lien décrit,
 * le code autorise. Un courriel transféré, une boîte partagée, une capture
 * d'écran ne suffisent alors jamais à faire changer un bien de mains.
 *
 * TEXTE BRUT : il sera lu depuis n'importe quel client, y compris sommaire, et
 * sur des forfaits où chaque kilo-octet compte.
 */
final class TransferInvitationMail extends Mailable
{
    public function __construct(
        public readonly string $lien,
        public readonly string $bien,
        public readonly string $numero,
        public readonly int $joursRestants,
    ) {}

    public function envelope(): Envelope
    {
        // L'OBJET DIT L'ACTION ATTENDUE. « Notification PREUVE » se classe et
        // s'oublie ; une cession non confirmée expire en sept jours et laisse
        // un bien bloqué en « transfert en cours ».
        return new Envelope(subject: 'PREUVE — un bien vous est cédé, à confirmer');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.transfer-invitation', with: [
            'lien' => $this->lien,
            'bien' => $this->bien,
            'numero' => $this->numero,
            'jours' => $this->joursRestants,
        ]);
    }
}
