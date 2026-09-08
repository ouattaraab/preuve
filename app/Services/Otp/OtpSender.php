<?php

declare(strict_types=1);

namespace App\Services\Otp;

use App\Enums\OtpPurpose;

/**
 * Acheminement d'un code OTP vers son destinataire.
 *
 * Le fournisseur SMS pour la Côte d'Ivoire n'est pas arbitré (question ouverte
 * dans activeContext.md) : cette interface existe pour que le choix reste une
 * ligne de configuration, et non une réécriture du service d'authentification.
 * L'implémentation par défaut en développement écrit dans les journaux.
 */
interface OtpSender
{
    public function send(string $destination, string $code, OtpPurpose $purpose): void;
}
