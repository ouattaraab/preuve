<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Achemine une notification hors de l'application (ST-1003, ST-1004).
 *
 * SEULE LA SORTIE EST MISE EN FILE, jamais la notification elle-même. La ligne
 * du centre in-app est écrite dans la requête qui la déclenche : c'est la trace
 * durable, et un propriétaire qui ouvre l'application doit y trouver l'alerte
 * même si aucune passerelle n'a jamais répondu. Mettre l'écriture en file
 * ferait dépendre le registre d'un travailleur.
 *
 * CE QUE LA FILE APPORTE, ET QUI N'ÉTAIT PAS POSSIBLE AVANT : le RÉESSAI. En
 * synchrone, un échec de passerelle était absorbé et l'alerte perdue — il n'y
 * avait rien d'autre à faire sans faire échouer l'action métier qui l'avait
 * déclenchée. Une déclaration de vol ne peut pas être refusée parce qu'un
 * agrégateur SMS répond mal. En file, l'échec est rejoué.
 *
 * UN DOUBLON VAUT MIEUX QU'UNE PERTE. Si la passerelle a bien émis mais que sa
 * réponse s'est perdue, le réessai enverra deux fois. Pour une alerte de
 * tentative d'enregistrement frauduleux ou de transfert engagé, recevoir deux
 * messages est un désagrément ; n'en recevoir aucun peut coûter le bien.
 *
 * `afterCommit` : le travailleur charge la notification par son identifiant.
 * Dépêché avant le COMMIT de la transaction métier, il pourrait la chercher
 * avant qu'elle n'existe.
 */
final class DeliverNotification implements ShouldQueue
{
    use Queueable;

    /** Trois tentatives espacées : au-delà, la passerelle est durablement en panne. */
    public int $tries = 3;

    public function __construct(public readonly int $notificationId)
    {
        $this->onQueue('notifications');

        // Le travailleur charge la notification par son identifiant : dépêché
        // avant le COMMIT de la transaction métier, il pourrait la chercher
        // avant qu'elle n'existe.
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(NotificationService $notifications): void
    {
        $notification = Notification::find($this->notificationId);

        // Disparue entre-temps : rien à acheminer, et rien à signaler.
        if (! $notification instanceof Notification) {
            return;
        }

        $notifications->deliverQueued($notification);
    }
}
