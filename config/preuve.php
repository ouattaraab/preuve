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

    /*
    | Tarification des enregistrements au-delà du quota gratuit (ST-0804) et
    | paliers d'abonnement de flotte (ST-0805).
    |
    | Les paliers font baisser le coût par véhicule : sans eux, un loueur de
    | cent véhicules paierait cent fois le prix d'un particulier pour le même
    | service, et n'aurait aucune raison de tout déclarer — alors que c'est
    | précisément la couverture complète du parc qui fait la valeur du registre.
    */
    'asset_slot_price_fcfa' => (int) env('PREUVE_SLOT_PRICE', 500),

    /*
    | Frais de dossier de réclamation (ST-0501), remboursés si la réclamation
    | aboutit. Ils filtrent les dossiers de nuisance — contester la propriété
    | d'autrui doit coûter quelque chose — sans jamais conditionner le dépôt :
    | une victime démunie ne doit pas se voir fermer son seul recours.
    */
    'claim_fee_fcfa' => (int) env('PREUVE_CLAIM_FEE', 2000),

    'fleet_tiers' => [
        // taille du parc à partir de laquelle s'applique le prix unitaire
        1 => 500,
        10 => 400,
        50 => 300,
        200 => 200,
    ],

    // Conservation des consultations : politique déclarée à l'ARTCI
    'lookup_retention_months' => 12,

    /*
    | Conservation des traces de scan (ST-0202). Bien plus courte que celle des
    | consultations : ces lignes ne servent qu'à mesurer si le pré-remplissage
    | fonctionne, et trois mois suffisent à trancher. Les garder un an
    | reviendrait à conserver, pour rien, une trace de qui a photographié une
    | carte grise et quand.
    */
    'scan_retention_days' => 90,

    /*
    | Sauvegardes (ST-0904). `disk` doit pointer AILLEURS que la machine
    | sauvegardée : une sauvegarde qui vit sur le disque qu'elle sauvegarde ne
    | protège ni d'une panne, ni d'un effacement volontaire. Vide = sauvegarde
    | désactivée, et la commande le dit plutôt que d'échouer en silence.
    */
    'backup' => [
        'disk' => env('PREUVE_BACKUP_DISK'),
        'mysqldump' => env('PREUVE_MYSQLDUMP_PATH', 'mysqldump'),
        // Client utilisé par l'exercice de restauration (`preuve:restore-drill`).
        'mysql' => env('PREUVE_MYSQL_PATH', 'mysql'),
        /*
        | Identifiants d'EXPLOITATION pour l'exercice de restauration. Le
        | compte applicatif ne doit pas pouvoir créer de bases : ce droit ne
        | servirait qu'une fois par trimestre et resterait ouvert le reste du
        | temps, sur le compte qu'un attaquant atteint en premier. À défaut,
        | préparez une base vide et passez-la en `--database=`.
        */
        'restore_username' => env('PREUVE_RESTORE_DB_USERNAME'),
        'restore_password' => env('PREUVE_RESTORE_DB_PASSWORD'),
    ],

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

    /*
    | Envois différés (ST-0206). Le disque de travail reçoit des fichiers
    | INCOMPLETS, morceau par morceau : il doit être local, car on y ajoute des
    | octets en place — ce qu'un stockage objet ne permet pas. Les pièces
    | constituées partent ensuite sur le bucket chiffré ci-dessus, et le
    | fichier de travail est effacé.
    */
    'uploads' => [
        'staging_disk' => env('PREUVE_UPLOADS_STAGING_DISK', 'local'),
        // Au-delà, un envoi abandonné n'a plus de chance d'être repris et
        // n'occupe plus que de la place. Deux jours laissent le temps de
        // retrouver du réseau.
        'session_ttl_hours' => (int) env('PREUVE_UPLOADS_TTL_HOURS', 48),
    ],
];
