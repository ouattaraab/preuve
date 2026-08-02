<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\User;

/**
 * Notification push (ST-1003).
 *
 * Le jeton d'appareil appartient au client mobile, qui le transmet à
 * l'inscription et à chaque renouvellement. Un utilisateur sans jeton — parce
 * qu'il n'a jamais ouvert l'application, ou qu'il a refusé les notifications —
 * n'est pas une erreur : c'est le cas majoritaire au démarrage, et le SMS reste
 * là pour le critique.
 */
interface PushTransport
{
    public function isConfigured(): bool;

    /** @param array<string, mixed> $donnees */
    public function send(User $destinataire, string $titre, string $corps, array $donnees = []): void;
}
