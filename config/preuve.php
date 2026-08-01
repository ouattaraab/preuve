<?php

declare(strict_types=1);

return [
    // Fenêtre de contestation d'un enregistrement récent (V-PRV → V-ACT)
    'provisional_days' => (int) env('PREUVE_PROVISIONAL_DAYS', 30),

    // Délai laissé au déclarant pour consolider un vol par récépissé de plainte
    'theft_consolidation_days' => (int) env('PREUVE_THEFT_CONSOLIDATION_DAYS', 15),

    // Durée de validité d'un jeton de preuve de détention, en minutes
    'detention_proof_ttl_minutes' => (int) env('PREUVE_DETENTION_TTL', 30),

    'lookup_rate_limit' => [
        // Règle métier absolue : 10 consultations par heure et par IP anonyme
        'anonymous_per_hour' => (int) env('PREUVE_LOOKUP_LIMIT', 10),
        // Les comptes authentifiés ne sont pas limités
        'authenticated_per_hour' => null,
    ],

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 5,
        'max_attempts' => 3,
        // Verrouillage progressif après épuisement des tentatives, en minutes
        'lockout_steps_minutes' => [1, 5, 15, 60],
    ],

    // Conservation des consultations : politique déclarée à l'ARTCI
    'lookup_retention_months' => 12,
];
