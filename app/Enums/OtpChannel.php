<?php

declare(strict_types=1);

namespace App\Enums;

/** Canal d'acheminement d'un code OTP. Le SMS est le seul canal du MVP. */
enum OtpChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
}
