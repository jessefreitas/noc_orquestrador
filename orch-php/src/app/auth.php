<?php
declare(strict_types=1);

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

function csrf_token(): string
{
    start_session();

    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['_csrf'];
}

function validate_csrf(?string $token): bool
{
    start_session();
    if (!isset($_SESSION['_csrf']) || !is_string($token)) {
        return false;
    }

    return hash_equals($_SESSION['_csrf'], $token);
}

function is_authenticated(): bool
{
    start_session();
    return isset($_SESSION['user']) && is_array($_SESSION['user']);
}

function current_user(): ?array
{
    start_session();
    $user = $_SESSION['user'] ?? null;
    return is_array($user) ? $user : null;
}

function current_auth_user(): ?array
{
    start_session();
    $authUser = $_SESSION['auth_user'] ?? null;
    if (is_array($authUser)) {
        return $authUser;
    }

    $user = $_SESSION['user'] ?? null;
    return is_array($user) ? $user : null;
}

function login_user(array $user): void
{
    start_session();
    session_regenerate_id(true);

    $payload = [
        'id' => (int) ($user['id'] ?? 0),
        'name' => (string) ($user['name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
    ];
    $_SESSION['auth_user'] = $payload;
    $_SESSION['user'] = $payload;
    unset($_SESSION['impersonation']);
}

function logout_user(): void
{
    start_session();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?: 'Lax',
            ]
        );
    }

    session_destroy();
}

function redirect(string $path): never
{
    header("Location: {$path}");
    exit;
}

function require_auth(): void
{
    if (!is_authenticated()) {
        redirect('/login.php');
    }
}

function platform_owner_email(): string
{
    $envValue = getenv('PLATFORM_OWNER_EMAIL');
    $email = is_string($envValue) ? trim($envValue) : '';
    if ($email === '') {
        $email = 'admin@local.test';
    }
    return strtolower($email);
}

function is_platform_owner(?array $user = null): bool
{
    $targetUser = $user;
    if ($targetUser === null) {
        $targetUser = current_user();
    }
    if (!is_array($targetUser)) {
        return false;
    }

    $email = strtolower(trim((string) ($targetUser['email'] ?? '')));
    if ($email === '') {
        return false;
    }

    return $email === platform_owner_email();
}

function is_platform_owner_effective(?array $actingUser = null): bool
{
    $target = $actingUser;
    if ($target === null) {
        $target = current_user();
    }

    if (is_platform_owner($target)) {
        return true;
    }

    $authUser = current_auth_user();
    if (is_array($authUser) && is_platform_owner($authUser)) {
        return true;
    }

    return false;
}

function impersonation_info(): ?array
{
    start_session();
    $info = $_SESSION['impersonation'] ?? null;
    return is_array($info) ? $info : null;
}

function is_impersonating(): bool
{
    $authUser = current_auth_user();
    $actingUser = current_user();
    if (!is_array($authUser) || !is_array($actingUser)) {
        return false;
    }
    return (int) ($authUser['id'] ?? 0) !== (int) ($actingUser['id'] ?? 0);
}

function start_impersonation_as(array $targetUser): void
{
    start_session();

    $authUser = current_auth_user();
    if (!is_array($authUser)) {
        throw new RuntimeException('Usuario autenticado nao encontrado.');
    }

    if (!is_platform_owner($authUser)) {
        throw new RuntimeException('Somente o gestor global pode emular usuarios.');
    }

    $targetPayload = [
        'id' => (int) ($targetUser['id'] ?? 0),
        'name' => (string) ($targetUser['name'] ?? ''),
        'email' => (string) ($targetUser['email'] ?? ''),
    ];
    if ($targetPayload['id'] <= 0) {
        throw new RuntimeException('Usuario alvo invalido.');
    }
    if ($targetPayload['id'] === (int) ($authUser['id'] ?? 0)) {
        throw new RuntimeException('Nao e necessario emular o proprio usuario.');
    }

    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'id' => (int) ($authUser['id'] ?? 0),
        'name' => (string) ($authUser['name'] ?? ''),
        'email' => (string) ($authUser['email'] ?? ''),
    ];
    $_SESSION['user'] = $targetPayload;
    $_SESSION['impersonation'] = [
        'started_at' => gmdate('c'),
        'target_user_id' => $targetPayload['id'],
        'target_email' => $targetPayload['email'],
    ];
}

function stop_impersonation(): void
{
    start_session();
    $authUser = current_auth_user();
    if (!is_array($authUser)) {
        return;
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) ($authUser['id'] ?? 0),
        'name' => (string) ($authUser['name'] ?? ''),
        'email' => (string) ($authUser['email'] ?? ''),
    ];
    $_SESSION['auth_user'] = $_SESSION['user'];
    unset($_SESSION['impersonation']);
}
