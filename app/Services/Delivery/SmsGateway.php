<?php

declare(strict_types=1);

namespace App\Services\Delivery;

/**
 * Envoi d'un SMS de notification (ST-1004).
 *
 * Contrat distinct de celui des codes OTP, mais MÊME passerelle configurée :
 * un loueur qui a branché son agrégateur pour les codes n'a pas à le refaire
 * pour les alertes. Ce qui diffère est l'usage — un code est un secret à usage
 * unique, une alerte est un texte libre — pas le tuyau.
 */
interface SmsGateway
{
    public function isConfigured(): bool;

    /** Doit lever en cas d'échec : un envoi perdu en silence ne se rattrape pas. */
    public function send(string $destination, string $message): void;
}
