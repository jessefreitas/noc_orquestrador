<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $cfg = db_config();
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['database']
    );

    $connection = new PDO(
        $dsn,
        $cfg['username'],
        $cfg['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return $connection;
}

function find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT id, name, email, password_hash FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => strtolower(trim($email))]);

    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function find_user_by_id(int $userId): ?array
{
    $stmt = db()->prepare('SELECT id, name, email, password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);

    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function list_impersonable_users(): array
{
    $stmt = db()->query(
        "SELECT u.id,
                u.name,
                u.email,
                COALESCE(STRING_AGG(DISTINCT c.name, ', ' ORDER BY c.name), '-') AS companies,
                COALESCE(STRING_AGG(DISTINCT cu.role, ', ' ORDER BY cu.role), '-') AS company_roles,
                COUNT(DISTINCT c.id) AS companies_count
         FROM users u
         LEFT JOIN company_users cu ON cu.user_id = u.id
         LEFT JOIN companies c ON c.id = cu.company_id
         GROUP BY u.id, u.name, u.email
         ORDER BY u.name, u.email"
    );

    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function user_has_company_links(int $userId): bool
{
    $stmt = db()->prepare(
        'SELECT 1
         FROM company_users
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchColumn() !== false;
}
