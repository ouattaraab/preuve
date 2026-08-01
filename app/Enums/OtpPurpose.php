<?php

declare(strict_types=1);

namespace App\Enums;

/** Motif d'émission d'un code OTP — un code ne vaut que pour son motif. */
enum OtpPurpose: string
{
    case Login = 'login';
    case Register = 'register';
    case SensitiveAction = 'sensitive_action';
    case Transfer = 'transfer';
    case GuestPayment = 'guest_payment';
}
