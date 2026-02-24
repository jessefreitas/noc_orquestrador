<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';

$status = 'ok';
$database = 'ok';

try {
    db()->query('SELECT 1');
} catch (Throwable $exception) {
    $status = 'degraded';
    $database = 'error';
}

http_response_code($status === 'ok' ? 200 : 503);
header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    [
        'status' => $status,
        'app' => 'noc-orquestrador-php',
        'database' => $database,
        'timestamp' => gmdate('c'),
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);
