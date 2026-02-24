<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function flash_set(string $type, string $message): void
{
    start_session();
    $_SESSION['_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flash_pull(): ?array
{
    start_session();
    $flash = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);

    return is_array($flash) ? $flash : null;
}
