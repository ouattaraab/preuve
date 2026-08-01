<?php

declare(strict_types=1);

namespace App\Enums;

/** Type d'acteur dans la chaîne d'audit. */
enum ActorType: string
{
    case User = 'user';
    case Agent = 'agent';
    case System = 'system';
}
