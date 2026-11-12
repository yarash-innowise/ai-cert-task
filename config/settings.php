<?php

declare(strict_types=1);

$env = static fn(string $name, string $default = ''): string => (string) (getenv($name) !== false ? getenv($name) : $default);

return [
    'app' => [
        'env' => $env('APP_ENV', 'production'),
        'base_path' => dirname(__DIR__),
        'url' => $env('APP_URL', 'https://localhost:18443'),
    ],
    'db' => [
        'host' => $env('DB_HOST', 'db'),
        'port' => $env('DB_PORT', '5432'),
        'name' => $env('APP_ENV') === 'testing' ? $env('DB_NAME_TEST', 'app_test') : $env('DB_NAME', 'app'),
        'user' => $env('DB_USER', 'app'),
        'password' => $env('DB_PASSWORD'),
    ],
    // Private upload storage (logos, photos, tmp), outside the web root.
    'storage' => [
        'path' => $env('STORAGE_PATH', dirname(__DIR__) . '/var/storage'),
    ],
    'mail' => [
        'dsn' => $env('MAILER_DSN', 'smtp://mailpit:1025'),
        'from' => $env('MAIL_FROM', 'no-reply@example.com'),
    ],
    'trusted_proxies' => array_values(array_filter(explode(',', $env('TRUSTED_PROXIES')))),
];
