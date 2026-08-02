<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Codes à usage unique (ST-0101 / ST-0102)
    |--------------------------------------------------------------------------
    |
    | Ces valeurs arbitrent la friction : un code trop court ou trop durable
    | s'attaque par force brute, un plafond trop bas empêche un utilisateur en
    | 3G instable de recevoir son SMS (CT-05). Toute modification doit être
    | reportée dans les tests, qui les vérifient explicitement.
    |
    */

    'length' => 6,

    'ttl_minutes' => 5,

    'max_attempts' => 3,

    /*
    | Rythme des envois vers une même destination. Le délai minimal protège
    | l'abonné d'un pilonnage de SMS déclenché par un tiers ; le plafond
    | horaire protège le budget SMS de la plateforme.
    */
    'resend_delay_seconds' => 60,

    'max_requests_per_hour' => 5,

    /*
    | Verrouillage progressif après épuisement des tentatives. La durée dépend
    | du nombre de verrouillages déjà subis par la destination sur la période
    | d'observation : un utilisateur qui se trompe une fois attend cinq
    | minutes, un automate qui s'acharne attend un jour.
    */
    'lockout_minutes' => [5, 15, 60, 1440],

    'lockout_window_hours' => 24,

];
