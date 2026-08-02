<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\OtpPurpose;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Code à usage unique acheminé par courriel (décision D7).
 *
 * LE CODE N'EST PAS DANS L'OBJET. Un objet s'affiche sur un écran verrouillé,
 * dans une liste de messages, parfois sous les yeux d'un tiers — ou du voleur,
 * si l'appareil a été pris avec le bien. Le même raisonnement que pour les SMS
 * critiques, appliqué ici.
 *
 * Texte brut : il sera lu depuis n'importe quel client, y compris sommaire, et
 * une mise en forme n'ajouterait rien à six chiffres.
 */
final class OtpCodeMail extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly OtpPurpose $purpose,
        public readonly int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'PREUVE — '.$this->motif());
    }

    public function content(): Content
    {
        return new Content(text: 'mail.otp-code', with: [
            'code' => $this->code,
            'motif' => $this->motif(),
            'ttl' => $this->ttlMinutes,
        ]);
    }

    /**
     * Dire À QUOI sert le code est ce qui permet à l'utilisateur de reconnaître
     * un code qu'il n'a pas demandé — c'est-à-dire une tentative de connexion
     * ou de transfert menée à son insu.
     */
    private function motif(): string
    {
        return match ($this->purpose) {
            OtpPurpose::Login => 'code de connexion',
            OtpPurpose::Register => 'code de création de compte',
            OtpPurpose::SensitiveAction => 'code de confirmation',
            OtpPurpose::Transfer => 'code de transfert de propriété',
            OtpPurpose::GuestPayment => 'code de vérification pour votre rapport',
        };
    }
}
