<?php

return [
    'name' => $_ENV['APP_NAME'] ?? 'Sistema de Horarios',
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url' => $_ENV['APP_URL'] ?? '',
    'key' => $_ENV['APP_KEY'] ?? '',
    'timezone' => 'America/Bogota',
    'locale' => 'es_CO',

    'session' => [
        'name' => $_ENV['SESSION_NAME'] ?? 'horarios_session',
        'lifetime_minutes' => (int) ($_ENV['SESSION_LIFETIME'] ?? 480),
    ],
];
