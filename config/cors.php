<?php

declare(strict_types=1);

/*
 * CORS — POSTURE FERMÉE, FIGÉE DANS LE DÉPÔT.
 *
 * Ce fichier n'existait pas : `HandleCors` lisait alors `config('cors.paths',
 * [])` → vide → aucun en-tête `Access-Control-Allow-Origin` émis. La posture
 * était donc correcte (aucune lecture cross-origin par navigateur), mais par
 * ACCIDENT, non par décision. Un `php artisan config:publish cors` ultérieur y
 * aurait posé les défauts du framework — `allowed_origins => ['*']` sur
 * `api/*` — ouvrant l'API en lecture cross-origin sans que personne ne l'ait
 * voulu.
 *
 * On n'en a aucun besoin : l'application mobile est un client natif qui porte
 * un jeton (pas soumis à CORS), et la console d'administration est servie sur
 * la MÊME origine que l'API qu'elle interroge. `paths => []` n'ouvre donc
 * aucune route au CORS, et `supports_credentials => false` interdit qu'un
 * cookie de session parte vers une origine tierce.
 *
 * Cohérent avec la philosophie du projet : ce qui est dans le dépôt survit au
 * redéploiement ; ce qui repose sur un défaut implicite disparaît sans prévenir.
 */

return [
    'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
