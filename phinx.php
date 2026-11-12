<?php

declare(strict_types=1);

$connection = static fn(string $name): array => [
    'adapter' => 'pgsql',
    'host' => getenv('DB_HOST') ?: 'db',
    'name' => $name,
    // Migrations run as the database owner; the application itself uses the restricted `app_rw` role.
    'user' => getenv('DB_OWNER_USER') ?: (getenv('DB_USER') ?: 'app'),
    'pass' => getenv('DB_OWNER_PASSWORD') ?: (getenv('DB_PASSWORD') ?: ''),
    'port' => (int) (getenv('DB_PORT') ?: 5432),
    'charset' => 'utf8',
];

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => $connection(getenv('DB_NAME') ?: 'app'),
        'testing' => $connection(getenv('DB_NAME_TEST') ?: 'app_test'),
    ],
    'version_order' => 'creation',
];
