<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Grille de pondération des preuves (systemPatterns §3).
 *
 * Les poids ne sont pas arbitraires : ils classent les preuves par ce qu'elles
 * établissent réellement. Un document officiel nominatif désigne un
 * propriétaire ; un récépissé de plainte atteste une dépossession ; une facture
 * prouve un achat sans dire qu'il n'y a pas eu revente depuis ; une photo ne
 * prouve qu'une détention à un instant.
 *
 * L'ancienneté du compte pèse le moins, et c'est délibéré : elle avantagerait
 * mécaniquement l'inscrit de longue date contre la victime qui découvre la
 * plateforme le jour où on lui vole son bien.
 */
enum EvidenceType: string
{
    case OfficialNamedDoc = 'official_named_doc';
    case PoliceReport = 'police_report';
    case Invoice = 'invoice';
    case Anteriority = 'anteriority';
    case AccountHistory = 'account_history';
    case PhotoContext = 'photo_context';

    /** Poids par défaut, avant appréciation de l'agent. */
    public function weight(): int
    {
        return match ($this) {
            self::OfficialNamedDoc => 40,
            self::PoliceReport => 25,
            self::Invoice => 15,
            self::Anteriority => 10,
            self::AccountHistory => 5,
            self::PhotoContext => 5,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OfficialNamedDoc => 'Document officiel nominatif (carte grise, ACD)',
            self::PoliceReport => 'Récépissé de plainte ou document judiciaire',
            self::Invoice => "Facture nominative d'achat",
            self::Anteriority => 'Antériorité documentaire',
            self::AccountHistory => 'Ancienneté du compte',
            self::PhotoContext => 'Photos horodatées ou éléments de contexte',
        };
    }

    /**
     * Vrai si ce type suffit à rendre une réclamation recevable (ST-0502).
     * Le filtre doit écarter les dossiers vides sans écarter les victimes :
     * seules les pièces qui désignent une personne ou attestent une plainte
     * ouvrent le dossier.
     */
    public function grantsAdmissibility(): bool
    {
        return $this === self::OfficialNamedDoc || $this === self::PoliceReport;
    }
}
