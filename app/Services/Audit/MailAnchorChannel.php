<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Mail\AuditAnchorMail;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Ancrage par courrier électronique horodaté.
 *
 * C'est le canal le plus simple et l'un des plus solides : le message est
 * horodaté et conservé par un serveur de messagerie tiers, qui n'appartient pas
 * à la plateforme. Antidater un ancrage supposerait de compromettre aussi ce
 * tiers.
 *
 * L'adresse d'archivage se configure depuis l'espace administrateur. Elle
 * devrait pointer vers une boîte distincte de l'exploitation courante, si
 * possible chez un autre fournisseur que l'hébergement : un ancrage stocké dans
 * la même infrastructure que la base perd l'essentiel de sa valeur.
 */
final class MailAnchorChannel implements AnchorChannel
{
    public const RECIPIENT_SETTING = 'audit.anchor.mail_recipient';

    public function __construct(private readonly SettingsRepository $settings) {}

    public function name(): string
    {
        return 'mail';
    }

    public function isConfigured(): bool
    {
        return $this->recipient() !== null;
    }

    public function publish(AnchorDocument $document): void
    {
        $destinataire = $this->recipient();

        if ($destinataire === null) {
            throw new RuntimeException(
                "Aucune adresse d'archivage configurée : l'ancrage ne peut pas quitter la plateforme."
            );
        }

        Mail::to($destinataire)->send(new AuditAnchorMail($document));
    }

    private function recipient(): ?string
    {
        $adresse = $this->settings->get(self::RECIPIENT_SETTING);

        return is_string($adresse) && $adresse !== '' ? $adresse : null;
    }
}
