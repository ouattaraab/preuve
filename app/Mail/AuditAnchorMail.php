<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Audit\AnchorDocument;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Constat d'ancrage expédié à l'adresse d'archivage.
 *
 * Texte brut délibérément : il sera relu des mois plus tard, peut-être depuis
 * un client de messagerie sommaire, peut-être imprimé pour être versé à un
 * dossier. Une mise en forme HTML n'ajouterait rien et pourrait être altérée
 * par un client de messagerie qui la réinterprète.
 */
final class AuditAnchorMail extends Mailable
{
    public function __construct(public readonly AnchorDocument $document) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->document->subject());
    }

    public function content(): Content
    {
        return new Content(text: 'mail.audit-anchor', with: ['corps' => $this->document->toText()]);
    }
}
