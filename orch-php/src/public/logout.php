<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/login.php');
}

$csrf = $_POST['_csrf'] ?? null;
if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
    http_response_code(403);
    exit('CSRF token invalido.');
}

logout_user();
redirect('/login.php');
