<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * État d'un envoi différé (ST-0206).
 *
 * `Assembling` existe pour une seule raison : c'est le drapeau qui empêche
 * deux requêtes arrivées en même temps de constituer deux fois le même
 * justificatif. Il est posé sous verrou, dans la transaction qui reçoit le
 * dernier morceau.
 */
enum UploadStatus: string
{
    /** Des morceaux sont attendus. */
    case Open = 'open';

    /** Le dernier morceau est arrivé ; le fichier est en cours de constitution. */
    case Assembling = 'assembling';

    /** Le justificatif existe : la session n'a plus qu'à être purgée. */
    case Completed = 'completed';

    /** Empreinte fausse ou contenu refusé : le client doit recommencer. */
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
