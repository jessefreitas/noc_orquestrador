<?php
declare(strict_types=1);

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/log_archive.php';
require_once __DIR__ . '/security.php';

function portainer_normalize_base_url(string $value): string
{
    $base = rtrim(trim($value), '/');
    if ($base === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $base)) {
        $base = 'https://' . $base;
    }
    if (!preg_match('#/api$#i', $base)) {
        $base .= '/api';
    }
    return $base;
}

function portainer_account_scopes(?string $scopesJson): array
{
    if (!is_string($scopesJson) || trim($scopesJson) === '') {
        return [];
    }
    $decoded = json_decode($scopesJson, true);
    return is_array($decoded) ? $decoded : [];
}

function list_portainer_accounts(int $companyId, int $projectId): array
{
    $stmt = db()->prepare(
        "SELECT id, label, status, scopes, last_tested_at, last_synced_at, created_at
         FROM provider_accounts
         WHERE company_id = :company_id
           AND project_id = :project_id
           AND provider = 'portainer'
         ORDER BY created_at DESC"
    );
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
    ]);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function get_portainer_account(int $companyId, int $projectId, int $accountId): ?array
{
    $stmt = db()->prepare(
        "SELECT *
         FROM provider_accounts
         WHERE id = :id
           AND company_id = :company_id
           AND project_id = :project_id
           AND provider = 'portainer'
         LIMIT 1"
    );
    $stmt->execute([
        'id' => $accountId,
        'company_id' => $companyId,
        'project_id' => $projectId,
    ]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function create_portainer_account(
    int $companyId,
    int $projectId,
    int $userId,
    string $label,
    string $baseUrl,
    string $apiKey,
    bool $insecureTls = true
): int {
    $safeLabel = trim($label);
    $safeBaseUrl = portainer_normalize_base_url($baseUrl);
    $safeApiKey = trim($apiKey);
    if ($safeLabel === '' || $safeBaseUrl === '' || $safeApiKey === '') {
        throw new InvalidArgumentException('Label, Base URL e API Key sao obrigatorios.');
    }

    $scopes = json_encode(
        ['base_url' => $safeBaseUrl, 'insecure_tls' => $insecureTls],
        JSON_UNESCAPED_SLASHES
    );

    $stmt = db()->prepare(
        "INSERT INTO provider_accounts (
            company_id, project_id, provider, label, token_ciphertext, scopes, status, created_by
         ) VALUES (
            :company_id, :project_id, 'portainer', :label, :token_ciphertext, :scopes::jsonb, :status, :created_by
         )
         RETURNING id"
    );
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'label' => $safeLabel,
        'token_ciphertext' => encrypt_secret($safeApiKey),
        'scopes' => $scopes,
        'status' => 'pending',
        'created_by' => $userId,
    ]);
    $accountId = (int) $stmt->fetchColumn();

    audit_log(
        $companyId,
        $projectId,
        $userId,
        'portainer.account.created',
        'provider_account',
        (string) $accountId,
        null,
        ['label' => $safeLabel, 'base_url' => $safeBaseUrl]
    );
    return $accountId;
}

function update_portainer_account(
    int $companyId,
    int $projectId,
    int $accountId,
    int $userId,
    string $label,
    string $baseUrl,
    ?string $apiKey = null,
    bool $insecureTls = true
): void {
    $account = get_portainer_account($companyId, $projectId, $accountId);
    if ($account === null) {
        throw new RuntimeException('Conta Portainer nao encontrada.');
    }
    $safeLabel = trim($label);
    $safeBaseUrl = portainer_normalize_base_url($baseUrl);
    if ($safeLabel === '' || $safeBaseUrl === '') {
        throw new InvalidArgumentException('Label e Base URL sao obrigatorios.');
    }

    $scopes = json_encode(
        ['base_url' => $safeBaseUrl, 'insecure_tls' => $insecureTls],
        JSON_UNESCAPED_SLASHES
    );

    $apiKeySafe = $apiKey !== null ? trim($apiKey) : '';
    if ($apiKeySafe !== '') {
        $stmt = db()->prepare(
            "UPDATE provider_accounts
             SET label = :label,
                 token_ciphertext = :token_ciphertext,
                 scopes = :scopes::jsonb,
                 status = 'pending',
                 last_tested_at = NULL
             WHERE id = :id
               AND company_id = :company_id
               AND project_id = :project_id
               AND provider = 'portainer'"
        );
        $stmt->execute([
            'id' => $accountId,
            'company_id' => $companyId,
            'project_id' => $projectId,
            'label' => $safeLabel,
            'token_ciphertext' => encrypt_secret($apiKeySafe),
            'scopes' => $scopes,
        ]);
    } else {
        $stmt = db()->prepare(
            "UPDATE provider_accounts
             SET label = :label,
                 scopes = :scopes::jsonb
             WHERE id = :id
               AND company_id = :company_id
               AND project_id = :project_id
               AND provider = 'portainer'"
        );
        $stmt->execute([
            'id' => $accountId,
            'company_id' => $companyId,
            'project_id' => $projectId,
            'label' => $safeLabel,
            'scopes' => $scopes,
        ]);
    }

    audit_log(
        $companyId,
        $projectId,
        $userId,
        'portainer.account.updated',
        'provider_account',
        (string) $accountId,
        null,
        ['label' => $safeLabel, 'base_url' => $safeBaseUrl, 'api_key_updated' => ($apiKeySafe !== '')]
    );
}

function portainer_api_request(array $account, string $method, string $path, array $query = []): array
{
    $scopes = portainer_account_scopes((string) ($account['scopes'] ?? ''));
    $baseUrl = portainer_normalize_base_url((string) ($scopes['base_url'] ?? ''));
    if ($baseUrl === '') {
        throw new RuntimeException('Base URL do Portainer nao configurada.');
    }
    $insecureTls = (bool) ($scopes['insecure_tls'] ?? true);
    $apiKey = decrypt_secret((string) ($account['token_ciphertext'] ?? ''));
    $url = $baseUrl . '/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $runRequest = static function () use ($url, $method, $apiKey, $insecureTls): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar requisicao Portainer.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-API-Key: ' . $apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 60,
        ]);
        if ($insecureTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'body' => $body,
            'status' => $status,
            'error' => $error,
        ];
    };

    $attempt = $runRequest();
    $body = $attempt['body'];
    $status = (int) ($attempt['status'] ?? 0);
    $error = (string) ($attempt['error'] ?? '');

    if (($body === false || $error !== '') && stripos($error, 'timed out') !== false) {
        $attempt = $runRequest();
        $body = $attempt['body'];
        $status = (int) ($attempt['status'] ?? 0);
        $error = (string) ($attempt['error'] ?? '');
    }

    if ($body === false || $error !== '') {
        throw new RuntimeException('Falha ao chamar Portainer: ' . $error);
    }

    $decoded = json_decode((string) $body, true);
    return [
        'status' => $status,
        'raw' => (string) $body,
        'body' => is_array($decoded) ? $decoded : null,
    ];
}

function test_portainer_account(int $companyId, int $projectId, int $accountId): array
{
    $account = get_portainer_account($companyId, $projectId, $accountId);
    if ($account === null) {
        return ['ok' => false, 'message' => 'Conta Portainer nao encontrada.'];
    }

    try {
        $statusResp = portainer_api_request($account, 'GET', '/status');
        $ok = $statusResp['status'] >= 200 && $statusResp['status'] < 300;
        $stmt = db()->prepare(
            "UPDATE provider_accounts
             SET status = :status,
                 last_tested_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'id' => $accountId,
            'status' => $ok ? 'active' : 'error',
        ]);

        if ($ok) {
            $version = (string) (($statusResp['body']['Version'] ?? $statusResp['body']['version'] ?? '') ?: '');
            return ['ok' => true, 'message' => 'Conexao OK.' . ($version !== '' ? ' Versao: ' . $version : '')];
        }
        return ['ok' => false, 'message' => 'Portainer retornou HTTP ' . (int) $statusResp['status'] . '.'];
    } catch (Throwable $exception) {
        $stmt = db()->prepare("UPDATE provider_accounts SET status = 'error', last_tested_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $accountId]);
        return ['ok' => false, 'message' => 'Falha ao testar: ' . $exception->getMessage()];
    }
}

function ensure_portainer_log_archives_table(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS portainer_log_archives (
            id BIGSERIAL PRIMARY KEY,
            company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            provider_account_id BIGINT NOT NULL REFERENCES provider_accounts(id) ON DELETE CASCADE,
            actor_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
            endpoint_id BIGINT NOT NULL,
            container_id VARCHAR(128) NOT NULL,
            container_name VARCHAR(255),
            range_window VARCHAR(20),
            bucket_name VARCHAR(255) NOT NULL,
            object_key TEXT NOT NULL,
            object_size BIGINT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'stored',
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )"
    );
    db()->exec('CREATE INDEX IF NOT EXISTS idx_portainer_log_archives_scope ON portainer_log_archives (company_id, project_id, provider_account_id, created_at DESC)');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_portainer_log_archives_container ON portainer_log_archives (company_id, project_id, container_id, created_at DESC)');
    $ensured = true;
}

/**
 * @return array{ok:bool,error:?string,archive_id?:int,object_key?:string}
 */
function archive_portainer_container_logs_to_r2(
    int $companyId,
    int $projectId,
    int $providerAccountId,
    int $actorUserId,
    int $endpointId,
    string $containerId,
    string $containerName,
    string $logsText,
    string $rangeWindow = ''
): array {
    ensure_portainer_log_archives_table();
    $storage = resolve_company_r2_storage($companyId);
    if (($storage['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => (string) ($storage['error'] ?? 'Storage R2 indisponivel.')];
    }

    $safeContainer = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $containerName !== '' ? $containerName : $containerId) ?? 'container';
    $safeContainer = trim($safeContainer, '-');
    if ($safeContainer === '') {
        $safeContainer = 'container';
    }
    $dateDir = gmdate('Y/m/d');
    $stamp = gmdate('Ymd-His');
    $objectKey = 'company-' . $companyId
        . '/project-' . $projectId
        . '/portainer/account-' . $providerAccountId
        . '/endpoint-' . $endpointId
        . '/container-' . $safeContainer
        . '/logs/' . $dateDir . '/' . $safeContainer . '-' . $stamp . '.log';

    $put = s3_signed_request(
        'PUT',
        (string) $storage['endpoint_url'],
        (string) $storage['region'],
        (string) $storage['access_key_id'],
        (string) $storage['secret_access_key'],
        (string) $storage['bucket'],
        $objectKey,
        (bool) $storage['force_path_style'],
        $logsText
    );
    if (!($put['ok'] ?? false)) {
        return ['ok' => false, 'error' => 'Falha ao enviar logs para R2 (HTTP ' . (int) ($put['status'] ?? 0) . ').'];
    }

    $stmt = db()->prepare(
        'INSERT INTO portainer_log_archives (
            company_id, project_id, provider_account_id, actor_user_id, endpoint_id,
            container_id, container_name, range_window, bucket_name, object_key, object_size, status
        ) VALUES (
            :company_id, :project_id, :provider_account_id, :actor_user_id, :endpoint_id,
            :container_id, :container_name, :range_window, :bucket_name, :object_key, :object_size, :status
        )
        RETURNING id'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'provider_account_id' => $providerAccountId,
        'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
        'endpoint_id' => $endpointId,
        'container_id' => $containerId,
        'container_name' => $containerName !== '' ? $containerName : null,
        'range_window' => $rangeWindow !== '' ? $rangeWindow : null,
        'bucket_name' => (string) $storage['bucket'],
        'object_key' => $objectKey,
        'object_size' => strlen($logsText),
        'status' => 'stored',
    ]);
    $archiveId = (int) $stmt->fetchColumn();

    audit_log(
        $companyId,
        $projectId,
        $actorUserId,
        'portainer.container.logs.archived_r2',
        'provider_account',
        (string) $providerAccountId,
        null,
        [
            'endpoint_id' => $endpointId,
            'container_id' => $containerId,
            'container_name' => $containerName,
            'archive_id' => $archiveId,
            'object_key' => $objectKey,
        ]
    );

    return ['ok' => true, 'error' => null, 'archive_id' => $archiveId, 'object_key' => $objectKey];
}

function list_portainer_log_archives(
    int $companyId,
    int $projectId,
    int $providerAccountId,
    int $limit = 30
): array {
    ensure_portainer_log_archives_table();
    $stmt = db()->prepare(
        'SELECT *
         FROM portainer_log_archives
         WHERE company_id = :company_id
           AND project_id = :project_id
           AND provider_account_id = :provider_account_id
         ORDER BY created_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
    $stmt->bindValue(':project_id', $projectId, PDO::PARAM_INT);
    $stmt->bindValue(':provider_account_id', $providerAccountId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function list_portainer_log_archives_all(
    int $companyId,
    int $projectId,
    int $limit = 30
): array {
    ensure_portainer_log_archives_table();
    $stmt = db()->prepare(
        'SELECT *
         FROM portainer_log_archives
         WHERE company_id = :company_id
           AND project_id = :project_id
         ORDER BY created_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
    $stmt->bindValue(':project_id', $projectId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, min(400, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function count_portainer_log_archives_total(int $companyId, int $projectId): int
{
    ensure_portainer_log_archives_table();
    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM portainer_log_archives
         WHERE company_id = :company_id
           AND project_id = :project_id'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
    ]);
    return (int) $stmt->fetchColumn();
}
