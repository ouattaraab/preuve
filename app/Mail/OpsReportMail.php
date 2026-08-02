<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Rapport d'exploitation expédié au destinataire configuré (ST-0904).
 *
 * L'OBJET DIT LE VERDICT. C'est souvent la seule partie lue, et parfois la
 * seule visible — sur un téléphone, dans une liste de messages. « À traiter »
 * ou « rien à signaler » doit s'y trouver, sinon le rapport ne se distingue
 * plus du bruit et finit filtré.
 *
 * Texte brut, comme le constat d'ancrage : il sera relu depuis n'importe quel
 * client, peut-être imprimé, et une mise en forme n'ajouterait rien.
 */
final class OpsReportMail extends Mailable
{
    public function __construct(
        public readonly string $titre,
        public readonly bool $anomalie,
        public readonly string $corps,
        public readonly ?string $journal = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'PREUVE — '.$this->titre.' — '.($this->anomalie ? 'À TRAITER' : 'rien à signaler'),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.ops-report', with: [
            'titre' => $this->titre,
            'anomalie' => $this->anomalie,
            'corps' => $this->corps,
            'journal' => $this->journal,
        ]);
    }
}
