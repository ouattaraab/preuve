<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Nature d'un justificatif.
 *
 * Le poids de chacun dans l'arbitrage d'une réclamation est fixé par la grille
 * (systemPatterns §3) ; ici, seul compte ce qui vaut PREUVE DE PROPRIÉTÉ pour
 * faire monter le niveau de fiabilité. Une photo ou un récépissé de plainte
 * documentent le bien ou un incident, ils ne disent rien de qui le possède.
 */
enum DocumentType: string
{
    case Invoice = 'invoice';
    case RegistrationCard = 'registration_card';
    case Acd = 'acd';
    case PoliceReport = 'police_report';
    case Photo = 'photo';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => "Facture d'achat",
            self::RegistrationCard => 'Carte grise',
            self::Acd => 'Arrêté de concession définitive',
            self::PoliceReport => 'Récépissé de plainte',
            self::Photo => 'Photo du bien',
            self::Other => 'Autre document',
        };
    }

    /**
     * Vrai si ce type, une fois accepté, atteste la propriété — condition du
     * passage à « Documenté » (F2).
     */
    public function provesOwnership(): bool
    {
        return in_array($this, [self::Invoice, self::RegistrationCard, self::Acd], true);
    }
}
