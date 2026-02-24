<?php
declare(strict_types=1);

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function db_config(): array
{
    return [
        'host' => env_value('DB_HOST', 'db'),
        'port' => (int) env_value('DB_PORT', '5432'),
        'database' => env_value('DB_DATABASE', 'noc_orquestrador'),
        'username' => env_value('DB_USERNAME', 'noc_user'),
        'password' => env_value('DB_PASSWORD', 'noc_pass_local'),
    ];
}
