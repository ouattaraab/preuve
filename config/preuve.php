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
        // Période d'observation des récidives : au-delà, le compteur de
        // verrouillages repart de zéro et la sanction redevient légère
        'lockout_window_hours' => 24,
        // Délai minimal entre deux envois vers un même numéro : protège
        // l'abonné d'un pilonnage de SMS déclenché par un tiers
        'resend_delay_seconds' => 60,
        // Plafond horaire d'envois vers un même numéro : protège le budget SMS
        'max_requests_per_hour' => 5,
    ],

    /*
    | Détection des pics de consultation (ST-0405). Un pic signale qu'un bien
    | est montré à plusieurs acheteurs : revente en cours, ou tentative de
    | vente par quelqu'un qui n'en est pas propriétaire.
    |
    | Le seuil est délibérément modeste : sur ce marché, un bien consulté cinq
    | fois en une journée sort déjà de l'ordinaire. Trop haut, l'alerte
    | n'arrive qu'après la vente ; trop bas, elle devient du bruit et finit
    | désactivée.
    */
    'lookup_spike' => [
        'threshold' => (int) env('PREUVE_SPIKE_THRESHOLD', 5),
        'window_hours' => (int) env('PREUVE_SPIKE_WINDOW_HOURS', 24),
        // Délai minimal entre deux alertes sur un même bien : sans lui, un
        // pic durable produirait une alerte à chaque passage du job.
        'cooldown_hours' => (int) env('PREUVE_SPIKE_COOLDOWN_HOURS', 24),
    ],

    /*
    | Prix du rapport détaillé, en FCFA. Le tarif exact (500 ou 1000) reste à
    | arbitrer : il est ici pour qu'un changement de grille tarifaire soit une
    | ligne de configuration, pas une livraison.
    */
    'report_price_fcfa' => (int) env('PREUVE_REPORT_PRICE', 1000),

    // Conservation des consultations : politique déclarée à l'ARTCI
    'lookup_retention_months' => 12,

    'documents' => [
        // Bucket chiffré au repos (MinIO en production). Les pièces déposées
        // contiennent des données personnelles — carte grise, facture
        // nominative : jamais de disque public, jamais d'URL devinable.
        'disk' => env('PREUVE_DOCUMENTS_DISK', 's3'),
        // Taille maximale d'un justificatif, en kilo-octets. Volontairement
        // modeste : le dépôt se fait souvent en 3G (CT-05), et une carte grise
        // photographiée y tient largement.
        'max_kb' => (int) env('PREUVE_DOCUMENTS_MAX_KB', 8192),
    ],
];
