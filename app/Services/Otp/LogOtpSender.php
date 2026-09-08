<?php

declare(strict_types=1);

namespace App\Services\Otp;

use App\Enums\OtpPurpose;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Achemine le code dans les journaux applicatifs, pour le développement et les
 * démonstrations tant qu'aucun fournisseur SMS n'est arbitré.
 *
 * Refuse catégoriquement de s'exécuter en production : un code d'accès en clair
 * dans un fichier de journal d'un hébergement mutualisé serait lisible par
 * quiconque accède au compte, et resterait dans les sauvegardes.
 */
final class LogOtpSender implements OtpSender
{
    public function send(string $destination, string $code, OtpPurpose $purpose): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'LogOtpSender est interdit en production : il écrirait les codes OTP en clair dans les journaux. '.
                'Configurez un fournisseur SMS avant tout déploiement.'
            );
        }

        Log::info('Code OTP émis (développement)', [
            'destination' => $destination,
            'purpose' => $purpose->value,
            'code' => $code,
        ]);
    }
}
