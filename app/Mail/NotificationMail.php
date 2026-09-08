<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Alerte critique acheminée par courriel, faute de passerelle SMS.
 *
 * MÊME DISCIPLINE QUE LE SMS : le titre, et rien de plus. L'argument est
 * identique — un aperçu de courriel s'affiche sur un écran verrouillé, parfois
 * sous les yeux d'un tiers, ou du voleur si l'appareil a été pris avec le bien.
 * Le détail reste derrière l'authentification.
 *
 * L'identité d'un consultant n'entre JAMAIS ici : ce message ne reçoit qu'un
 * titre et un identifiant public de bien (règle métier absolue n° 4).
 */
final class NotificationMail extends Mailable
{
    public function __construct(
        public readonly string $titre,
        public readonly ?string $publicRef = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'PREUVE — '.$this->titre);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.notification', with: [
            'titre' => $this->titre,
            'publicRef' => $this->publicRef,
        ]);
    }
}
