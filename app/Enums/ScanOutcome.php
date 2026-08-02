<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sort d'une proposition de pré-remplissage (ST-0202).
 *
 * `Pending` n'est pas un état transitoire à résorber : un scan qui n'aboutit à
 * aucun enregistrement est un abandon, et c'est une mesure en soi. Le compter
 * comme un succès parce qu'il a extrait quelque chose masquerait exactement ce
 * que la story demande de surveiller.
 */
enum ScanOutcome: string
{
    /** Aucune soumission n'a suivi le scan. */
    case Pending = 'pending';

    /** L'utilisateur a soumis l'identifiant proposé, inchangé. */
    case Accepted = 'accepted';

    /** L'utilisateur a soumis un autre identifiant : l'extraction s'est trompée. */
    case Corrected = 'corrected';
}
