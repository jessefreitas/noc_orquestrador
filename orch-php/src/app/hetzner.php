<?php
declare(strict_types=1);

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/hetzner_catalog.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/tenancy.php';

const HETZNER_API_BASE = 'https://api.hetzner.cloud/v1';
const HETZNER_DISABLE_DELETE_API = true;

function list_hetzner_accounts(int $companyId, int $projectId): array
{
    $stmt = db()->prepare(
        'SELECT pa.id,
                pa.label,
                pa.status,
                pa.last_tested_at,
                pa.last_synced_at,
                pa.created_at,
                COUNT(hs.id)::bigint AS server_count,
                MAX(hs.last_seen_at) AS last_server_seen_at
         FROM provider_accounts pa
         LEFT JOIN hetzner_servers hs
           ON hs.provider_account_id = pa.id
          AND hs.company_id = pa.company_id
          AND hs.project_id = pa.project_id
         WHERE pa.company_id = :company_id
           AND pa.project_id = :project_id
           AND pa.provider = :provider
         GROUP BY pa.id, pa.label, pa.status, pa.last_tested_at, pa.last_synced_at, pa.created_at
         ORDER BY pa.created_at DESC'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'provider' => 'hetzner',
    ]);

    return $stmt->fetchAll();
}

/**
 * @return array<int,array<string,mixed>>
 */
function list_active_hetzner_accounts_global(?int $companyId = null, ?int $projectId = null): array
{
    $where = ['pa.provider = :provider'];
    $params = ['provider' => 'hetzner'];

    if (is_int($companyId) && $companyId > 0) {
        $where[] = 'pa.company_id = :company_id';
        $params['company_id'] = $companyId;
    }
    if (is_int($projectId) && $projectId > 0) {
        $where[] = 'pa.project_id = :project_id';
        $params['project_id'] = $projectId;
    }

    $stmt = db()->prepare(
        'SELECT pa.id,
                pa.company_id,
                pa.project_id,
                pa.label,
                pa.status,
                pa.last_synced_at
         FROM provider_accounts pa
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY pa.company_id ASC, pa.project_id ASC, pa.id ASC'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function resolve_company_actor_user_id(int $companyId): ?int
{
    $stmt = db()->prepare(
        "SELECT cu.user_id
         FROM company_users cu
         WHERE cu.company_id = :company_id
         ORDER BY CASE WHEN cu.role = 'owner' THEN 0 ELSE 1 END, cu.user_id ASC
         LIMIT 1"
    );
    $stmt->execute(['company_id' => $companyId]);
    $candidate = $stmt->fetchColumn();
    if (is_numeric($candidate) && (int) $candidate > 0) {
        return (int) $candidate;
    }

    $fallback = db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if (is_numeric($fallback) && (int) $fallback > 0) {
        return (int) $fallback;
    }
    return null;
}

function create_hetzner_account(int $companyId, int $projectId, int $userId, string $label, string $token): int
{
    $label = trim($label);
    $token = trim($token);

    if ($label === '' || $token === '') {
        throw new InvalidArgumentException('Label e token da conta Hetzner sao obrigatorios.');
    }

    $stmt = db()->prepare(
        'INSERT INTO provider_accounts (
            company_id,
            project_id,
            provider,
            label,
            token_ciphertext,
            created_by
        ) VALUES (
            :company_id,
            :project_id,
            :provider,
            :label,
            :token_ciphertext,
            :created_by
        )
        RETURNING id'
    );

    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'provider' => 'hetzner',
        'label' => $label,
        'token_ciphertext' => encrypt_secret($token),
        'created_by' => $userId,
    ]);

    $accountId = (int) $stmt->fetchColumn();

    audit_log(
        $companyId,
        $projectId,
        $userId,
        'hetzner.account.created',
        'provider_account',
        (string) $accountId,
        null,
        ['label' => $label]
    );

    return $accountId;
}

function get_hetzner_account(int $companyId, int $projectId, int $accountId): ?array
{
    $stmt = db()->prepare(
        'SELECT *
         FROM provider_accounts
         WHERE id = :id
           AND company_id = :company_id
           AND project_id = :project_id
           AND provider = :provider
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $accountId,
        'company_id' => $companyId,
        'project_id' => $projectId,
        'provider' => 'hetzner',
    ]);

    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function list_hetzner_accounts_for_company(int $companyId, ?int $excludeAccountId = null): array
{
    $sql = 'SELECT pa.id,
                   pa.company_id,
                   pa.project_id,
                   pa.label,
                   pa.status,
                   p.name AS project_name
            FROM provider_accounts pa
            INNER JOIN projects p ON p.id = pa.project_id
            WHERE pa.company_id = :company_id
              AND pa.provider = :provider';
    $params = [
        'company_id' => $companyId,
        'provider' => 'hetzner',
    ];

    if (is_int($excludeAccountId) && $excludeAccountId > 0) {
        $sql .= ' AND pa.id <> :exclude_account_id';
        $params['exclude_account_id'] = $excludeAccountId;
    }

    $sql .= ' ORDER BY p.name ASC, pa.label ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function update_hetzner_account(
    int $companyId,
    int $projectId,
    int $accountId,
    int $userId,
    string $label,
    ?string $token = null
): void {
    $current = get_hetzner_account($companyId, $projectId, $accountId);
    if ($current === null) {
        throw new RuntimeException('Conta Hetzner nao encontrada no contexto atual.');
    }

    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('Label do projeto Hetzner e obrigatorio.');
    }

    $tokenNormalized = $token !== null ? trim($token) : '';
    if ($tokenNormalized === '') {
        $stmt = db()->prepare(
            'UPDATE provider_accounts
             SET label = :label
             WHERE id = :id
               AND company_id = :company_id
               AND project_id = :project_id
               AND provider = :provider'
        );
        $stmt->execute([
            'id' => $accountId,
            'company_id' => $companyId,
            'project_id' => $projectId,
            'provider' => 'hetzner',
            'label' => $label,
        ]);
    } else {
        $stmt = db()->prepare(
            'UPDATE provider_accounts
             SET label = :label,
                 token_ciphertext = :token_ciphertext,
                 status = :status,
                 last_tested_at = NULL
             WHERE id = :id
               AND company_id = :company_id
               AND project_id = :project_id
               AND provider = :provider'
        );
        $stmt->execute([
            'id' => $accountId,
            'company_id' => $companyId,
            'project_id' => $projectId,
            'provider' => 'hetzner',
            'label' => $label,
            'token_ciphertext' => encrypt_secret($tokenNormalized),
            'status' => 'pending',
        ]);
    }

    audit_log(
        $companyId,
        $projectId,
        $userId,
        'hetzner.account.updated',
        'provider_account',
        (string) $accountId,
        ['label' => $current['label'] ?? null, 'status' => $current['status'] ?? null],
        ['label' => $label, 'token_rotated' => $tokenNormalized !== '']
    );
}

/**
 * @return array{ok:bool,message:string,deleted_servers:int,deleted_assets:int}
 */
function delete_hetzner_account(
    int $companyId,
    int $projectId,
    int $accountId,
    int $userId
): array {
    $account = get_hetzner_account($companyId, $projectId, $accountId);
    if ($account === null) {
        return ['ok' => false, 'message' => 'Conta Hetzner nao encontrada no contexto atual.', 'deleted_servers' => 0, 'deleted_assets' => 0];
    }

    $countServersStmt = db()->prepare(
        'SELECT COUNT(*)
         FROM hetzner_servers
         WHERE company_id = :company_id
           AND project_id = :project_id
           AND provider_account_id = :account_id'
    );
    $countServersStmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'account_id' => $accountId,
    ]);
    $deletedServers = (int) $countServersStmt->fetchColumn();

    $deletedAssets = 0;
    if (db_table_exists('public.hetzner_assets')) {
        $countAssetsStmt = db()->prepare(
            'SELECT COUNT(*)
             FROM hetzner_assets
             WHERE company_id = :company_id
               AND project_id = :project_id
               AND provider_account_id = :account_id'
        );
        $countAssetsStmt->execute([
            'company_id' => $companyId,
            'project_id' => $projectId,
            'account_id' => $accountId,
        ]);
        $deletedAssets = (int) $countAssetsStmt->fetchColumn();
    }

    $deleteStmt = db()->prepare(
        'DELETE FROM provider_accounts
         WHERE id = :id
           AND company_id = :company_id
           AND project_id = :project_id
           AND provider = :provider'
    );
    $deleteStmt->execute([
        'id' => $accountId,
        'company_id' => $companyId,
        'project_id' => $projectId,
        'provider' => 'hetzner',
    ]);

    if ($deleteStmt->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Conta Hetzner nao foi removida.', 'deleted_servers' => 0, 'deleted_assets' => 0];
    }

    audit_log(
        $companyId,
        $projectId,
        $userId,
        'hetzner.account.deleted',
        'provider_account',
        (string) $accountId,
        ['label' => $account['label'] ?? null, 'status' => $account['status'] ?? null],
        ['deleted_servers' => $deletedServers, 'deleted_assets' => $deletedAssets]
    );

    return [
        'ok' => true,
        'message' => 'Projeto Hetzner removido da plataforma.',
        'deleted_servers' => $deletedServers,
        'deleted_assets' => $deletedAssets,
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function delete_hetzner_server_from_platform(
    int $companyId,
    int $projectId,
    int $accountId,
    int $serverId,
    int $userId
): array {
    $serverStmt = db()->prepare(
        'SELECT id, external_id, name, status
         FROM hetzner_servers
         WHERE id = :id
           AND company_id = :company_id
           AND project_id = :project_id
           AND provider_account_id = :account_id
         LIMIT 1'
    );
    $serverStmt->execute([
        'id' => $serverId,
        'company_id' => $companyId,
        'project_id' => $projectId,
        'account_id' => $accountId,
    ]);
    $server = $serverStmt->fetch();
    if (!is_array($server)) {
        return ['ok' => false, 'message' => 'Servidor nao encontrado no projeto atual.'];
    }

    $deleteStmt = db()->prepare(
        'DELETE FROM hetzner_servers
         WHERE id = :id
           AND company_id = :company_id
           AND project_id = :project_id
           AND provider_account_id = :account_id'
    );
    $deleteStmt->execute([
        'id' => $serverId,
        'company_id' => $companyId,
        'project_id' => $projectId,
        'account_id' => $accountId,
    ]);

    if ($deleteStmt->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Servidor nao foi removido.'];
    }

    audit_log(
        $companyId,
        $projectId,
        $userId,
        'hetzner.server.deleted_from_platform',
        'hetzner_server',
        (string) $serverId,
        [
            'external_id' => $server['external_id'] ?? null,
            'name' => $server['name'] ?? null,
            'status' => $server['status'] ?? null,
        ],
        null
    );

    return ['ok' => true, 'message' => 'Servidor removido da plataforma.'];
}

function db_table_exists(string $tableName): bool
{
    static $cache = [];
    $key = trim($tableName);
    if ($key === '') {
        return false;
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = db()->prepare("SELECT to_regclass(:table_name) IS NOT NULL");
    $stmt->execute(['table_name' => $key]);
    $raw = $stmt->fetchColumn();
    $exists = ($raw === true || $raw === 't' || $raw === 1 || $raw === '1');
    $cache[$key] = $exists;
    return $exists;
}

/**
 * @param array<int,int> $serverIds
 * @return array{ok:bool,message:string,moved:int,skipped:int}
 */
function migrate_hetzner_servers_between_accounts(
    int $companyId,
    int $sourceProjectId,
    int $sourceAccountId,
    int $targetAccountId,
    int $userId,
    array $serverIds = []
): array {
    $sourceAccount = get_hetzner_account($companyId, $sourceProjectId, $sourceAccountId);
    if ($sourceAccount === null) {
        return ['ok' => false, 'message' => 'Conta Hetzner de origem nao encontrada.', 'moved' => 0, 'skipped' => 0];
    }

    $targetStmt = db()->prepare(
        'SELECT id, company_id, project_id, label
         FROM provider_accounts
         WHERE id = :id
           AND company_id = :company_id
           AND provider = :provider
         LIMIT 1'
    );
    $targetStmt->execute([
        'id' => $targetAccountId,
        'company_id' => $companyId,
        'provider' => 'hetzner',
    ]);
    $targetAccount = $targetStmt->fetch();
    if (!is_array($targetAccount)) {
        return ['ok' => false, 'message' => 'Conta Hetzner de destino nao encontrada na mesma empresa.', 'moved' => 0, 'skipped' => 0];
    }

    $targetProjectId = (int) ($targetAccount['project_id'] ?? 0);
    if ($targetProjectId <= 0) {
        return ['ok' => false, 'message' => 'Projeto de destino invalido.', 'moved' => 0, 'skipped' => 0];
    }

    $params = [
        'company_id' => $companyId,
        'source_project_id' => $sourceProjectId,
        'source_account_id' => $sourceAccountId,
    ];
    $where = 'company_id = :company_id
              AND project_id = :source_project_id
              AND provider_account_id = :source_account_id';

    if ($serverIds !== []) {
        $normalizedIds = [];
        foreach ($serverIds as $sid) {
            $sidInt = (int) $sid;
            if ($sidInt > 0) {
                $normalizedIds[$sidInt] = true;
            }
        }
        $serverIds = array_keys($normalizedIds);
        if ($serverIds === []) {
            return ['ok' => false, 'message' => 'Nenhum servidor valido informado para migracao.', 'moved' => 0, 'skipped' => 0];
        }

        $idPlaceholders = [];
        foreach ($serverIds as $idx => $sid) {
            $key = 'sid_' . $idx;
            $idPlaceholders[] = ':' . $key;
            $params[$key] = $sid;
        }
        $where .= ' AND id IN (' . implode(', ', $idPlaceholders) . ')';
    }

    $sourceServersStmt = db()->prepare(
        'SELECT id, external_id, name
         FROM hetzner_servers
         WHERE ' . $where . '
         ORDER BY id ASC'
    );
    $sourceServersStmt->execute($params);
    $sourceServers = $sourceServersStmt->fetchAll();
    if (!is_array($sourceServers) || $sourceServers === []) {
        return ['ok' => false, 'message' => 'Nenhum servidor encontrado para migrar.', 'moved' => 0, 'skipped' => 0];
    }

    $moved = 0;
    $skipped = 0;
    $movedServerIds = [];
    db()->beginTransaction();
    try {
        $updateServerStmt = db()->prepare(
            'UPDATE hetzner_servers
             SET project_id = :target_project_id,
                 provider_account_id = :target_account_id
             WHERE id = :server_id
               AND company_id = :company_id
               AND project_id = :source_project_id
               AND provider_account_id = :source_account_id'
        );

        foreach ($sourceServers as $serverRow) {
            $serverId = (int) ($serverRow['id'] ?? 0);
            if ($serverId <= 0) {
                continue;
            }

            try {
                $updateServerStmt->execute([
                    'target_project_id' => $targetProjectId,
                    'target_account_id' => $targetAccountId,
                    'server_id' => $serverId,
                    'company_id' => $companyId,
                    'source_project_id' => $sourceProjectId,
                    'source_account_id' => $sourceAccountId,
                ]);
                if ($updateServerStmt->rowCount() > 0) {
                    $moved++;
                    $movedServerIds[] = $serverId;
                } else {
                    $skipped++;
                }
            } catch (Throwable $exception) {
                $sqlState = (string) $exception->getCode();
                if ($sqlState === '23505') {
                    $skipped++;
                    continue;
                }
                throw $exception;
            }
        }

        if ($movedServerIds !== []) {
            $projectOnlyTables = [
                'public.snapshot_policies' => 'snapshot_policies',
                'public.snapshot_runs' => 'snapshot_runs',
                'public.server_ai_diagnostics' => 'server_ai_diagnostics',
                'public.server_ai_chat_messages' => 'server_ai_chat_messages',
                'public.server_log_archives' => 'server_log_archives',
            ];

            foreach ($projectOnlyTables as $qualifiedName => $tableName) {
                if (!db_table_exists($qualifiedName)) {
                    continue;
                }
                $relatedStmt = db()->prepare(
                    'UPDATE ' . $tableName . '
                     SET project_id = :target_project_id
                     WHERE company_id = :company_id
                       AND project_id = :source_project_id
                       AND server_id = :server_id'
                );
                foreach ($movedServerIds as $serverId) {
                    $relatedStmt->execute([
                        'target_project_id' => $targetProjectId,
                        'company_id' => $companyId,
                        'source_project_id' => $sourceProjectId,
                        'server_id' => $serverId,
                    ]);
                }
            }
        }

        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return [
            'ok' => false,
            'message' => 'Falha ao migrar servidores: ' . $exception->getMessage(),
            'moved' => $moved,
            'skipped' => $skipped,
        ];
    }

    audit_log(
        $companyId,
        $sourceProjectId,
        $userId,
        'hetzner.servers.migrated',
        'provider_account',
        (string) $sourceAccountId,
        null,
        [
            'source_account_id' => $sourceAccountId,
            'target_account_id' => $targetAccountId,
            'source_project_id' => $sourceProjectId,
            'target_project_id' => $targetProjectId,
            'moved' => $moved,
            'skipped' => $skipped,
        ]
    );

    return [
        'ok' => true,
        'message' => 'Migracao concluida.',
        'moved' => $moved,
        'skipped' => $skipped,
    ];
}

function hetzner_api_request_ex(
    string $token,
    string $method,
    string $path,
    array $query = [],
    ?array $payload = null
): array
{
    $methodUpper = strtoupper($method);
    if (HETZNER_DISABLE_DELETE_API && $methodUpper === 'DELETE') {
        throw new RuntimeException('Operacoes DELETE na API da Hetzner estao desativadas nesta plataforma.');
    }

    $base = rtrim((string) getenv('HETZNER_API_BASE_URL'), '/');
    $baseUrl = $base !== '' ? $base : HETZNER_API_BASE;
    $url = $baseUrl . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Nao foi possivel iniciar requisicao HTTP.');
    }

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $methodUpper,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]
    );

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Falha ao chamar API da Hetzner: ' . $error);
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    return [
        'status' => $httpCode,
        'body' => $decoded,
    ];
}

function hetzner_api_request(string $token, string $path, array $query = []): array
{
    return hetzner_api_request_ex($token, 'GET', $path, $query, null);
}

function hetzner_resolve_path_template(string $pathTemplate, array $pathParams): string
{
    return (string) preg_replace_callback(
        '/\{([a-zA-Z0-9_]+)\}/',
        static function (array $matches) use ($pathParams): string {
            $key = (string) ($matches[1] ?? '');
            $value = $pathParams[$key] ?? null;
            if ($value === null || trim((string) $value) === '') {
                throw new InvalidArgumentException('Parametro de path ausente: ' . $key);
            }
            return rawurlencode((string) $value);
        },
        $pathTemplate
    );
}

function execute_hetzner_operation(
    int $companyId,
    int $projectId,
    int $accountId,
    int $userId,
    string $operationId,
    array $pathParams = [],
    array $queryParams = [],
    ?array $payload = null
): array {
    $operation = hetzner_endpoint_by_id($operationId);
    if (!is_array($operation)) {
        throw new InvalidArgumentException('Operacao Hetzner invalida.');
    }

    $account = get_hetzner_account($companyId, $projectId, $accountId);
    if ($account === null) {
        throw new RuntimeException('Conta Hetzner nao encontrada no contexto atual.');
    }

    $method = strtoupper((string) ($operation['method'] ?? 'GET'));
    if (HETZNER_DISABLE_DELETE_API && $method === 'DELETE') {
        throw new RuntimeException('Endpoint DELETE bloqueado: remocao na origem Hetzner esta desativada.');
    }

    $pathTemplate = (string) ($operation['path'] ?? '');
    if ($pathTemplate === '') {
        throw new RuntimeException('Path da operacao vazio.');
    }

    $resolvedPath = hetzner_resolve_path_template($pathTemplate, $pathParams);
    if (preg_match('/\{[a-zA-Z0-9_]+\}/', $resolvedPath) === 1) {
        throw new InvalidArgumentException('Ainda existem placeholders nao resolvidos no path.');
    }

    $token = decrypt_secret((string) $account['token_ciphertext']);
    $response = hetzner_api_request_ex($token, $method, $resolvedPath, $queryParams, $payload);

    audit_log(
        $companyId,
        $projectId,
        $userId,
        'hetzner.operation.executed',
        'provider_account',
        (string) $accountId,
        null,
        [
            'operation_id' => $operationId,
            'method' => $method,
            'path' => $resolvedPath,
            'status' => (int) ($response['status'] ?? 0),
        ]
    );

    return [
        'operation' => $operation,
        'request' => [
            'method' => $method,
            'path' => $resolvedPath,
            'query' => $queryParams,
            'payload' => $payload,
        ],
        'response' => $response,
    ];
}

function update_provider_account_status(int $accountId, string $status, bool $updateSync = false): void
{
    $sql = $updateSync
        ? 'UPDATE provider_accounts
           SET status = :status,
               last_tested_at = NOW(),
               last_synced_at = NOW()
           WHERE id = :id'
        : 'UPDATE provider_accounts
           SET status = :status,
               last_tested_at = NOW()
           WHERE id = :id';

    $stmt = db()->prepare($sql);
    $stmt->execute([
        'id' => $accountId,
        'status' => $status,
    ]);
}

function test_hetzner_account(int $companyId, int $projectId, int $accountId, int $userId): array
{
    $account = get_hetzner_account($companyId, $projectId, $accountId);
    if ($account === null) {
        return ['ok' => false, 'message' => 'Conta Hetzner nao encontrada no projeto atual.'];
    }

    try {
        $token = decrypt_secret((string) $account['token_ciphertext']);
        $response = hetzner_api_request($token, '/servers', ['per_page' => 1, 'page' => 1]);
    } catch (Throwable $exception) {
        update_provider_account_status($accountId, 'error');
        return ['ok' => false, 'message' => 'Erro de conexao: ' . $exception->getMessage()];
    }

    $ok = $response['status'] >= 200 && $response['status'] < 300;
    update_provider_account_status($accountId, $ok ? 'active' : 'invalid');

    audit_log(
        $companyId,
        $projectId,
        $userId,
        'hetzner.account.tested',
        'provider_account',
        (string) $accountId,
        ['status' => $account['status']],
        ['status' => $ok ? 'active' : 'invalid', 'http_status' => $response['status']]
    );

    if ($ok) {
        return ['ok' => true, 'message' => 'Token valido e API da Hetzner respondeu com sucesso.'];
    }

    return ['ok' => false, 'message' => 'Falha ao validar token (HTTP ' . $response['status'] . ').'];
}

function upsert_hetzner_server(
    int $companyId,
    int $projectId,
    int $accountId,
    array $server
): void {
    $externalId = (int) ($server['id'] ?? 0);
    if ($externalId <= 0) {
        return;
    }

    $datacenter = (string) ($server['datacenter']['location']['name'] ?? '');
    $ipv4 = (string) ($server['public_net']['ipv4']['ip'] ?? '');
    $labels = $server['labels'] ?? [];
    if (!is_array($labels)) {
        $labels = [];
    }

    $stmt = db()->prepare(
        'INSERT INTO hetzner_servers (
            company_id,
            project_id,
            provider_account_id,
            external_id,
            name,
            status,
            datacenter,
            ipv4,
            labels_json,
            raw_json,
            last_seen_at
        ) VALUES (
            :company_id,
            :project_id,
            :provider_account_id,
            :external_id,
            :name,
            :status,
            :datacenter,
            :ipv4,
            :labels_json::jsonb,
            :raw_json::jsonb,
            NOW()
        )
        ON CONFLICT (provider_account_id, external_id)
        DO UPDATE SET
            name = EXCLUDED.name,
            status = EXCLUDED.status,
            datacenter = EXCLUDED.datacenter,
            ipv4 = EXCLUDED.ipv4,
            labels_json = EXCLUDED.labels_json,
            raw_json = EXCLUDED.raw_json,
            last_seen_at = NOW()'
    );

    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'provider_account_id' => $accountId,
        'external_id' => $externalId,
        'name' => (string) ($server['name'] ?? ('srv-' . $externalId)),
        'status' => (string) ($server['status'] ?? 'unknown'),
        'datacenter' => $datacenter !== '' ? $datacenter : null,
        'ipv4' => $ipv4 !== '' ? $ipv4 : null,
        'labels_json' => json_encode($labels, JSON_UNESCAPED_SLASHES),
        'raw_json' => json_encode($server, JSON_UNESCAPED_SLASHES),
    ]);
}

function sync_hetzner_servers(int $companyId, int $projectId, int $accountId, int $userId): array
{
    $account = get_hetzner_account($companyId, $projectId, $accountId);
    if ($account === null) {
        return ['ok' => false, 'message' => 'Conta Hetzner nao encontrada no projeto atual.', 'count' => 0, 'deleted' => 0];
    }

    $jobId = job_start($companyId, $projectId, 'hetzner.sync_servers', ['account_id' => $accountId]);
    $count = 0;
    $deleted = 0;
    $seenExternalIds = [];

    try {
        $token = decrypt_secret((string) $account['token_ciphertext']);
        $page = 1;
        $nextPage = 1;

        while ($nextPage !== null) {
            $response = hetzner_api_request($token, '/servers', ['page' => $page, 'per_page' => 50]);
            $status = (int) $response['status'];
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('Hetzner retornou HTTP ' . $status . '.');
            }

            $servers = $response['body']['servers'] ?? [];
            if (!is_array($servers)) {
                $servers = [];
            }

            foreach ($servers as $server) {
                if (is_array($server)) {
                    $externalId = trim((string) ($server['id'] ?? ''));
                    if ($externalId !== '') {
                        $seenExternalIds[$externalId] = true;
                    }
                    upsert_hetzner_server($companyId, $projectId, $accountId, $server);
                    $count++;
                }
            }

            $pagination = $response['body']['meta']['pagination'] ?? [];
            $nextRaw = is_array($pagination) ? ($pagination['next_page'] ?? null) : null;
            $nextPage = is_numeric($nextRaw) ? (int) $nextRaw : null;
            $page = $nextPage ?? 0;
        }

        if ($seenExternalIds === []) {
            $deleteStmt = db()->prepare(
                'DELETE FROM hetzner_servers
                 WHERE company_id = :company_id
                   AND project_id = :project_id
                   AND provider_account_id = :account_id'
            );
            $deleteStmt->execute([
                'company_id' => $companyId,
                'project_id' => $projectId,
                'account_id' => $accountId,
            ]);
            $deleted = (int) $deleteStmt->rowCount();
        } else {
            $quoted = [];
            $params = [
                'company_id' => $companyId,
                'project_id' => $projectId,
                'account_id' => $accountId,
            ];
            $i = 0;
            foreach (array_keys($seenExternalIds) as $externalId) {
                $key = 'external_' . $i;
                $quoted[] = ':' . $key;
                $params[$key] = $externalId;
                $i++;
            }
            $deleteStmt = db()->prepare(
                'DELETE FROM hetzner_servers
                 WHERE company_id = :company_id
                   AND project_id = :project_id
                   AND provider_account_id = :account_id
                   AND external_id NOT IN (' . implode(', ', $quoted) . ')'
            );
            $deleteStmt->execute($params);
            $deleted = (int) $deleteStmt->rowCount();
        }

        update_provider_account_status($accountId, 'active', true);
        job_finish($jobId, 'success', 'Sincronizacao concluida.', ['count' => $count, 'deleted' => $deleted]);

        audit_log(
            $companyId,
            $projectId,
            $userId,
            'hetzner.servers.synced',
            'provider_account',
            (string) $accountId,
            null,
            ['count' => $count, 'deleted' => $deleted]
        );

        return [
            'ok' => true,
            'message' => 'Sincronizacao concluida com ' . $count . ' servidor(es) e ' . $deleted . ' removido(s) da plataforma.',
            'count' => $count,
            'deleted' => $deleted,
        ];
    } catch (Throwable $exception) {
        update_provider_account_status($accountId, 'error');
        job_finish($jobId, 'error', $exception->getMessage(), ['count' => $count, 'deleted' => $deleted]);
        return ['ok' => false, 'message' => 'Falha na sincronizacao: ' . $exception->getMessage(), 'count' => $count, 'deleted' => $deleted];
    }
}

function list_project_servers(int $companyId, int $projectId): array
{
    $stmt = db()->prepare(
        'SELECT hs.id,
                hs.external_id,
                hs.name,
                hs.status,
                hs.datacenter,
                hs.ipv4,
                hs.last_seen_at,
                hs.provider_account_id,
                hs.raw_json,
                NULLIF(hs.raw_json->\'server_type\'->>\'cores\', \'\') AS cpu_cores,
                NULLIF(hs.raw_json->\'server_type\'->>\'memory\', \'\') AS memory_gb,
                NULLIF(hs.raw_json->\'server_type\'->>\'disk\', \'\') AS disk_gb,
                NULLIF(hs.raw_json->\'server_type\'->>\'name\', \'\') AS server_type_name,
                NULLIF(hs.raw_json->\'image\'->>\'os_flavor\', \'\') AS os_flavor,
                NULLIF(hs.raw_json->\'image\'->>\'name\', \'\') AS image_name,
                NULLIF(hs.raw_json->\'public_net\'->\'ipv6\'->>\'ip\', \'\') AS ipv6,
                pa.label AS account_label
         FROM hetzner_servers hs
         INNER JOIN provider_accounts pa ON pa.id = hs.provider_account_id
         WHERE hs.company_id = :company_id
           AND hs.project_id = :project_id
         ORDER BY hs.name'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
    ]);
    return $stmt->fetchAll();
}

function get_project_server_by_id(int $companyId, int $projectId, int $serverId): ?array
{
    $stmt = db()->prepare(
        'SELECT hs.*,
                pa.label AS account_label
         FROM hetzner_servers hs
         INNER JOIN provider_accounts pa ON pa.id = hs.provider_account_id
         WHERE hs.company_id = :company_id
           AND hs.project_id = :project_id
           AND hs.id = :server_id
         LIMIT 1'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'server_id' => $serverId,
    ]);

    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * @param array<string,mixed> $server
 * @return array{cpu_cores:int|null,memory_gb:float|null,disk_gb:float|null,server_type:?string,os_name:?string,ipv6:?string}
 */
function hetzner_server_metrics(array $server): array
{
    $toInt = static function ($value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return null;
    };

    $toFloat = static function ($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        return null;
    };

    $cpuCores = $toInt($server['cpu_cores'] ?? null);
    $memoryGb = $toFloat($server['memory_gb'] ?? null);
    $diskGb = $toFloat($server['disk_gb'] ?? null);
    $serverType = null;
    $osName = null;
    $ipv6 = null;

    $raw = $server['raw_json'] ?? null;
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            if ($cpuCores === null) {
                $cpuCores = $toInt($decoded['server_type']['cores'] ?? null);
            }
            if ($memoryGb === null) {
                $memoryGb = $toFloat($decoded['server_type']['memory'] ?? null);
            }
            if ($diskGb === null) {
                $diskGb = $toFloat($decoded['server_type']['disk'] ?? null);
            }
            $serverTypeRaw = $decoded['server_type']['name'] ?? null;
            if (is_string($serverTypeRaw) && trim($serverTypeRaw) !== '') {
                $serverType = trim($serverTypeRaw);
            }
            $osFlavor = $decoded['image']['os_flavor'] ?? null;
            $imageName = $decoded['image']['name'] ?? null;
            if (is_string($osFlavor) && trim($osFlavor) !== '') {
                $osName = trim($osFlavor);
            } elseif (is_string($imageName) && trim($imageName) !== '') {
                $osName = trim($imageName);
            }
            $ipv6Raw = $decoded['public_net']['ipv6']['ip'] ?? null;
            if (is_string($ipv6Raw) && trim($ipv6Raw) !== '') {
                $ipv6 = trim($ipv6Raw);
            }
        }
    }

    if ($serverType === null && is_string($server['server_type_name'] ?? null) && trim((string) $server['server_type_name']) !== '') {
        $serverType = trim((string) $server['server_type_name']);
    }
    if ($osName === null && is_string($server['os_flavor'] ?? null) && trim((string) $server['os_flavor']) !== '') {
        $osName = trim((string) $server['os_flavor']);
    } elseif ($osName === null && is_string($server['image_name'] ?? null) && trim((string) $server['image_name']) !== '') {
        $osName = trim((string) $server['image_name']);
    }
    if ($ipv6 === null && is_string($server['ipv6'] ?? null) && trim((string) $server['ipv6']) !== '') {
        $ipv6 = trim((string) $server['ipv6']);
    }

    return [
        'cpu_cores' => $cpuCores,
        'memory_gb' => $memoryGb,
        'disk_gb' => $diskGb,
        'server_type' => $serverType,
        'os_name' => $osName,
        'ipv6' => $ipv6,
    ];
}

/**
 * @param array<int,array<string,mixed>> $servers
 * @return array{servers_total:int,servers_running:int,cpu_total:int,memory_total_gb:float,disk_total_gb:float}
 */
function summarize_hetzner_capacity(array $servers): array
{
    $serversTotal = count($servers);
    $serversRunning = 0;
    $cpuTotal = 0;
    $memoryTotal = 0.0;
    $diskTotal = 0.0;

    foreach ($servers as $server) {
        $status = strtolower((string) ($server['status'] ?? ''));
        if (in_array($status, ['running', 'ok', 'active', 'healthy'], true)) {
            $serversRunning++;
        }

        $metrics = hetzner_server_metrics($server);
        if (is_int($metrics['cpu_cores'])) {
            $cpuTotal += $metrics['cpu_cores'];
        }
        if (is_float($metrics['memory_gb'])) {
            $memoryTotal += $metrics['memory_gb'];
        }
        if (is_float($metrics['disk_gb'])) {
            $diskTotal += $metrics['disk_gb'];
        }
    }

    return [
        'servers_total' => $serversTotal,
        'servers_running' => $serversRunning,
        'cpu_total' => $cpuTotal,
        'memory_total_gb' => $memoryTotal,
        'disk_total_gb' => $diskTotal,
    ];
}

function ensure_hetzner_assets_table(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS hetzner_assets (
            id BIGSERIAL PRIMARY KEY,
            company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            provider_account_id BIGINT NOT NULL REFERENCES provider_accounts(id) ON DELETE CASCADE,
            asset_type VARCHAR(80) NOT NULL,
            external_id VARCHAR(120) NOT NULL,
            name VARCHAR(190),
            status VARCHAR(80),
            datacenter VARCHAR(120),
            ipv4 VARCHAR(80),
            raw_json JSONB NOT NULL DEFAULT '{}'::jsonb,
            last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            UNIQUE (provider_account_id, asset_type, external_id)
        )"
    );
    db()->exec('CREATE INDEX IF NOT EXISTS idx_hetzner_assets_scope ON hetzner_assets (company_id, project_id, asset_type, status)');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_hetzner_assets_account ON hetzner_assets (provider_account_id, asset_type, last_seen_at DESC)');

    $ensured = true;
}

/**
 * @return array<string,array{path:string,collection_key:string,query?:array<string,string>}>
 */
function hetzner_inventory_catalog(): array
{
    return [
        'servers' => ['path' => '/servers', 'collection_key' => 'servers'],
        'volumes' => ['path' => '/volumes', 'collection_key' => 'volumes'],
        'load_balancers' => ['path' => '/load_balancers', 'collection_key' => 'load_balancers'],
        'floating_ips' => ['path' => '/floating_ips', 'collection_key' => 'floating_ips'],
        'primary_ips' => ['path' => '/primary_ips', 'collection_key' => 'primary_ips'],
        'firewalls' => ['path' => '/firewalls', 'collection_key' => 'firewalls'],
        'networks' => ['path' => '/networks', 'collection_key' => 'networks'],
        'placement_groups' => ['path' => '/placement_groups', 'collection_key' => 'placement_groups'],
        'snapshots' => ['path' => '/images', 'collection_key' => 'images', 'query' => ['type' => 'snapshot']],
        'backups' => ['path' => '/images', 'collection_key' => 'images', 'query' => ['type' => 'backup']],
        'app_images' => ['path' => '/images', 'collection_key' => 'images', 'query' => ['type' => 'app']],
        'system_images' => ['path' => '/images', 'collection_key' => 'images', 'query' => ['type' => 'system']],
        'ssh_keys' => ['path' => '/ssh_keys', 'collection_key' => 'ssh_keys'],
    ];
}

/**
 * @return array<int,array<string,mixed>>
 */
function hetzner_fetch_paginated_collection(
    string $token,
    string $path,
    string $collectionKey,
    int $perPage = 50,
    array $query = []
): array
{
    $items = [];
    $page = 1;
    $nextPage = 1;

    while ($nextPage !== null) {
        $requestQuery = array_merge($query, ['page' => $page, 'per_page' => $perPage]);
        $response = hetzner_api_request($token, $path, $requestQuery);
        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Hetzner retornou HTTP ' . $status . ' para ' . $path);
        }

        $chunk = $response['body'][$collectionKey] ?? [];
        if (is_array($chunk)) {
            foreach ($chunk as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        $pagination = $response['body']['meta']['pagination'] ?? [];
        $nextRaw = is_array($pagination) ? ($pagination['next_page'] ?? null) : null;
        $nextPage = is_numeric($nextRaw) ? (int) $nextRaw : null;
        $page = $nextPage ?? 0;
    }

    return $items;
}

/**
 * @param array<string,mixed> $asset
 */
function hetzner_asset_external_id(array $asset): ?string
{
    $externalIdRaw = $asset['id'] ?? ($asset['name'] ?? null);
    if ($externalIdRaw === null || trim((string) $externalIdRaw) === '') {
        return null;
    }
    return trim((string) $externalIdRaw);
}

/**
 * @param array<string,mixed> $asset
 */
function upsert_hetzner_asset(
    int $companyId,
    int $projectId,
    int $accountId,
    string $assetType,
    array $asset
): ?string {
    ensure_hetzner_assets_table();

    $externalId = hetzner_asset_external_id($asset);
    if ($externalId === null) {
        return null;
    }

    $nameCandidates = [
        $asset['name'] ?? null,
        $asset['description'] ?? null,
        $asset['domain'] ?? null,
    ];
    $name = null;
    foreach ($nameCandidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            $name = trim($candidate);
            break;
        }
    }
    if ($name === null) {
        $name = $assetType . '-' . $externalId;
    }

    $status = null;
    foreach (['status', 'state', 'protection'] as $statusKey) {
        $value = $asset[$statusKey] ?? null;
        if (is_string($value) && trim($value) !== '') {
            $status = trim($value);
            break;
        }
        if (is_array($value) && $statusKey === 'protection') {
            $status = 'protection:' . implode(',', array_keys(array_filter($value, static fn ($v): bool => (bool) $v)));
            break;
        }
    }
    if ($status === null) {
        $status = 'unknown';
    }

    $datacenter = null;
    $datacenterCandidates = [
        $asset['datacenter']['name'] ?? null,
        $asset['datacenter']['location']['name'] ?? null,
        $asset['location']['name'] ?? null,
        $asset['home_location']['name'] ?? null,
    ];
    foreach ($datacenterCandidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            $datacenter = trim($candidate);
            break;
        }
    }

    $ipv4 = null;
    $ipv4Candidates = [
        $asset['public_net']['ipv4']['ip'] ?? null,
        $asset['ip'] ?? null,
        $asset['public_ip'] ?? null,
    ];
    foreach ($ipv4Candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            $ipv4 = trim($candidate);
            break;
        }
    }

    $stmt = db()->prepare(
        "INSERT INTO hetzner_assets (
            company_id,
            project_id,
            provider_account_id,
            asset_type,
            external_id,
            name,
            status,
            datacenter,
            ipv4,
            raw_json,
            last_seen_at,
            updated_at
        ) VALUES (
            :company_id,
            :project_id,
            :provider_account_id,
            :asset_type,
            :external_id,
            :name,
            :status,
            :datacenter,
            :ipv4,
            :raw_json::jsonb,
            NOW(),
            NOW()
        )
        ON CONFLICT (provider_account_id, asset_type, external_id)
        DO UPDATE SET
            name = EXCLUDED.name,
            status = EXCLUDED.status,
            datacenter = EXCLUDED.datacenter,
            ipv4 = EXCLUDED.ipv4,
            raw_json = EXCLUDED.raw_json,
            last_seen_at = NOW(),
            updated_at = NOW()"
    );

    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'provider_account_id' => $accountId,
        'asset_type' => $assetType,
        'external_id' => $externalId,
        'name' => $name,
        'status' => $status,
        'datacenter' => $datacenter,
        'ipv4' => $ipv4,
        'raw_json' => json_encode($asset, JSON_UNESCAPED_SLASHES),
    ]);

    return $externalId;
}

/**
 * @param array<int,string> $externalIds
 */
function prune_hetzner_assets_by_external_ids(
    int $companyId,
    int $projectId,
    int $accountId,
    string $assetType,
    array $externalIds
): void {
    ensure_hetzner_assets_table();

    $params = [
        'company_id' => $companyId,
        'project_id' => $projectId,
        'account_id' => $accountId,
        'asset_type' => $assetType,
    ];

    if ($externalIds === []) {
        $stmt = db()->prepare(
            'DELETE FROM hetzner_assets
             WHERE company_id = :company_id
               AND project_id = :project_id
               AND provider_account_id = :account_id
               AND asset_type = :asset_type'
        );
        $stmt->execute($params);
        return;
    }

    $placeholders = [];
    foreach (array_values($externalIds) as $index => $externalId) {
        $key = 'ext_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $externalId;
    }

    $stmt = db()->prepare(
        'DELETE FROM hetzner_assets
         WHERE company_id = :company_id
           AND project_id = :project_id
           AND provider_account_id = :account_id
           AND asset_type = :asset_type
           AND external_id NOT IN (' . implode(', ', $placeholders) . ')'
    );
    $stmt->execute($params);
}

/**
 * @return array{ok:bool,message:string,total:int,by_type:array<string,int>}
 */
function sync_hetzner_inventory(int $companyId, int $projectId, int $accountId, int $userId): array
{
    ensure_hetzner_assets_table();

    $account = get_hetzner_account($companyId, $projectId, $accountId);
    if ($account === null) {
        return ['ok' => false, 'message' => 'Conta Hetzner nao encontrada no projeto atual.', 'total' => 0, 'by_type' => []];
    }

    $jobId = job_start($companyId, $projectId, 'hetzner.sync_inventory', ['account_id' => $accountId]);
    $byType = [];
    $total = 0;

    try {
        $token = decrypt_secret((string) $account['token_ciphertext']);

        // Cleanup de compatibilidade com versao anterior que gravava tudo como "images".
        db()->prepare(
            'DELETE FROM hetzner_assets
             WHERE company_id = :company_id
               AND project_id = :project_id
               AND provider_account_id = :account_id
               AND asset_type = :asset_type'
        )->execute([
            'company_id' => $companyId,
            'project_id' => $projectId,
            'account_id' => $accountId,
            'asset_type' => 'images',
        ]);

        foreach (hetzner_inventory_catalog() as $assetType => $meta) {
            $path = (string) ($meta['path'] ?? '');
            $collectionKey = (string) ($meta['collection_key'] ?? '');
            $query = $meta['query'] ?? [];
            if (!is_array($query)) {
                $query = [];
            }
            if ($path === '' || $collectionKey === '') {
                continue;
            }

            $items = hetzner_fetch_paginated_collection($token, $path, $collectionKey, 50, $query);
            $count = 0;
            $seenExternalIds = [];
            foreach ($items as $item) {
                $externalId = upsert_hetzner_asset($companyId, $projectId, $accountId, $assetType, $item);
                if ($externalId !== null) {
                    $seenExternalIds[$externalId] = true;
                    $count++;
                }
            }
            prune_hetzner_assets_by_external_ids(
                $companyId,
                $projectId,
                $accountId,
                $assetType,
                array_keys($seenExternalIds)
            );

            $byType[$assetType] = $count;
            $total += $count;
        }

        job_finish($jobId, 'success', 'Coleta de inventario concluida.', ['total' => $total, 'by_type' => $byType]);
        audit_log(
            $companyId,
            $projectId,
            $userId,
            'hetzner.inventory.synced',
            'provider_account',
            (string) $accountId,
            null,
            ['total' => $total, 'by_type' => $byType]
        );

        return [
            'ok' => true,
            'message' => 'Inventario coletado com sucesso.',
            'total' => $total,
            'by_type' => $byType,
        ];
    } catch (Throwable $exception) {
        job_finish($jobId, 'error', $exception->getMessage(), ['total' => $total, 'by_type' => $byType]);
        return [
            'ok' => false,
            'message' => 'Falha na coleta de inventario: ' . $exception->getMessage(),
            'total' => $total,
            'by_type' => $byType,
        ];
    }
}

/**
 * @return array{total:int,ok:int,failed:int,items:array<int,array<string,mixed>>}
 */
function sync_all_hetzner_inventory(
    ?int $companyId = null,
    ?int $projectId = null,
    int $limit = 100
): array {
    $accounts = list_active_hetzner_accounts_global($companyId, $projectId);
    if ($limit > 0) {
        $accounts = array_slice($accounts, 0, $limit);
    }

    $report = [
        'total' => count($accounts),
        'ok' => 0,
        'failed' => 0,
        'items' => [],
    ];

    foreach ($accounts as $account) {
        $accId = (int) ($account['id'] ?? 0);
        $accCompanyId = (int) ($account['company_id'] ?? 0);
        $accProjectId = (int) ($account['project_id'] ?? 0);
        if ($accId <= 0 || $accCompanyId <= 0 || $accProjectId <= 0) {
            continue;
        }
        $actorUserId = resolve_company_actor_user_id($accCompanyId);
        if (!is_int($actorUserId) || $actorUserId <= 0) {
            $report['failed']++;
            $report['items'][] = [
                'account_id' => $accId,
                'company_id' => $accCompanyId,
                'project_id' => $accProjectId,
                'ok' => false,
                'message' => 'Sem usuario ator valido para auditoria/sync.',
            ];
            continue;
        }

        $result = sync_hetzner_inventory($accCompanyId, $accProjectId, $accId, $actorUserId);
        if (($result['ok'] ?? false) === true) {
            $report['ok']++;
        } else {
            $report['failed']++;
        }
        $report['items'][] = [
            'account_id' => $accId,
            'company_id' => $accCompanyId,
            'project_id' => $accProjectId,
            'label' => (string) ($account['label'] ?? ''),
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'total' => (int) ($result['total'] ?? 0),
            'by_type' => is_array($result['by_type'] ?? null) ? $result['by_type'] : [],
        ];
    }

    return $report;
}

/**
 * @return array<int,array<string,mixed>>
 */
function list_project_assets(int $companyId, int $projectId, ?int $accountId = null, ?string $assetType = null): array
{
    ensure_hetzner_assets_table();

    $where = [
        'ha.company_id = :company_id',
        'ha.project_id = :project_id',
    ];
    $params = [
        'company_id' => $companyId,
        'project_id' => $projectId,
    ];

    if (is_int($accountId) && $accountId > 0) {
        $where[] = 'ha.provider_account_id = :account_id';
        $params['account_id'] = $accountId;
    }

    if (is_string($assetType) && trim($assetType) !== '') {
        $where[] = 'ha.asset_type = :asset_type';
        $params['asset_type'] = trim($assetType);
    }

    $stmt = db()->prepare(
        'SELECT ha.id,
                ha.provider_account_id,
                ha.asset_type,
                ha.external_id,
                ha.name,
                ha.status,
                ha.datacenter,
                ha.ipv4,
                ha.last_seen_at,
                ha.raw_json,
                pa.label AS account_label
         FROM hetzner_assets ha
         INNER JOIN provider_accounts pa ON pa.id = ha.provider_account_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY ha.asset_type ASC, ha.name ASC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * @param array<int,array<string,mixed>> $assets
 * @return array<string,int>
 */
function summarize_project_assets(array $assets): array
{
    $summary = [];
    foreach ($assets as $asset) {
        $type = strtolower(trim((string) ($asset['asset_type'] ?? 'unknown')));
        if ($type === '') {
            $type = 'unknown';
        }
        if (!array_key_exists($type, $summary)) {
            $summary[$type] = 0;
        }
        $summary[$type]++;
    }
    ksort($summary);
    return $summary;
}

/**
 * @return array<int,array{external_id:string,name:string,kind:string,status:string,created_at:string,size_label:string,description:string,source_server_id:string}>
 */
function list_server_snapshot_assets(
    int $companyId,
    int $projectId,
    int $accountId,
    string $serverExternalId
): array {
    $serverExternalId = trim($serverExternalId);
    if ($serverExternalId === '') {
        return [];
    }

    $assets = list_project_assets($companyId, $projectId, $accountId, null);
    $rows = [];

    foreach ($assets as $asset) {
        $kind = strtolower(trim((string) ($asset['asset_type'] ?? '')));
        if (!in_array($kind, ['snapshots', 'backups'], true)) {
            continue;
        }

        $raw = $asset['raw_json'] ?? null;
        $decoded = [];
        if (is_string($raw) && trim($raw) !== '') {
            $candidate = json_decode($raw, true);
            if (is_array($candidate)) {
                $decoded = $candidate;
            }
        }

        $sourceServerId = trim((string) ($decoded['created_from']['id'] ?? ''));
        $boundToMatch = false;
        $boundTo = $decoded['bound_to'] ?? null;
        if (is_scalar($boundTo)) {
            $boundToMatch = trim((string) $boundTo) === $serverExternalId;
        } elseif (is_array($boundTo)) {
            foreach ($boundTo as $boundCandidate) {
                if (is_scalar($boundCandidate) && trim((string) $boundCandidate) === $serverExternalId) {
                    $boundToMatch = true;
                    break;
                }
            }
        }

        if ($sourceServerId !== $serverExternalId && !$boundToMatch) {
            continue;
        }

        $diskSizeRaw = $decoded['disk_size'] ?? $decoded['size'] ?? null;
        $sizeLabel = '-';
        if (is_numeric($diskSizeRaw)) {
            $sizeLabel = number_format((float) $diskSizeRaw, 1, ',', '.') . ' GB';
        }

        $createdAt = (string) ($decoded['created'] ?? $asset['last_seen_at'] ?? '-');
        $status = (string) ($decoded['status'] ?? $asset['status'] ?? '-');
        $description = trim((string) ($decoded['description'] ?? ''));
        if ($description === '') {
            $description = (string) ($asset['name'] ?? '-');
        }

        $rows[] = [
            'external_id' => (string) ($asset['external_id'] ?? '-'),
            'name' => (string) ($asset['name'] ?? '-'),
            'kind' => $kind,
            'status' => $status,
            'created_at' => $createdAt,
            'size_label' => $sizeLabel,
            'description' => $description,
            'source_server_id' => $sourceServerId !== '' ? $sourceServerId : '-',
        ];
    }

    usort(
        $rows,
        static function (array $a, array $b): int {
            $aTs = strtotime((string) ($a['created_at'] ?? ''));
            $bTs = strtotime((string) ($b['created_at'] ?? ''));
            if ($aTs === false && $bTs === false) {
                return 0;
            }
            if ($aTs === false) {
                return 1;
            }
            if ($bTs === false) {
                return -1;
            }
            return $bTs <=> $aTs;
        }
    );

    return $rows;
}

/**
 * @param array<string,mixed> $server
 * @return array<string,mixed>
 */
function hetzner_server_decode_raw(array $server): array
{
    $raw = $server['raw_json'] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string,mixed> $server
 * @return array<string,array<int,string>>
 */
function hetzner_server_related_ids(array $server): array
{
    $raw = hetzner_server_decode_raw($server);

    $collectIds = static function ($value): array {
        $ids = [];
        if (is_scalar($value) && trim((string) $value) !== '') {
            $ids[] = trim((string) $value);
        } elseif (is_array($value)) {
            foreach ($value as $entry) {
                if (is_scalar($entry) && trim((string) $entry) !== '') {
                    $ids[] = trim((string) $entry);
                    continue;
                }
                if (is_array($entry) && isset($entry['id']) && is_scalar($entry['id']) && trim((string) $entry['id']) !== '') {
                    $ids[] = trim((string) $entry['id']);
                }
            }
        }
        return array_values(array_unique($ids));
    };

    $firewallIds = [];
    if (isset($raw['public_net']['firewalls']) && is_array($raw['public_net']['firewalls'])) {
        foreach ($raw['public_net']['firewalls'] as $fw) {
            if (is_array($fw) && isset($fw['id']) && is_scalar($fw['id']) && trim((string) $fw['id']) !== '') {
                $firewallIds[] = trim((string) $fw['id']);
            }
        }
    }

    $primaryIpIds = [];
    foreach (['ipv4', 'ipv6'] as $ipFamily) {
        $candidate = $raw['public_net'][$ipFamily]['id'] ?? null;
        if (is_scalar($candidate) && trim((string) $candidate) !== '') {
            $primaryIpIds[] = trim((string) $candidate);
        }
    }

    return [
        'firewalls' => array_values(array_unique($firewallIds)),
        'primary_ips' => array_values(array_unique($primaryIpIds)),
        'floating_ips' => $collectIds($raw['public_net']['floating_ips'] ?? []),
        'volumes' => $collectIds($raw['volumes'] ?? []),
        'networks' => $collectIds(array_map(
            static function ($network): mixed {
                if (is_array($network)) {
                    return $network['network'] ?? null;
                }
                return null;
            },
            is_array($raw['private_net'] ?? null) ? $raw['private_net'] : []
        )),
        'placement_groups' => $collectIds($raw['placement_group']['id'] ?? null),
    ];
}

/**
 * @return array{counts:array<string,int>,rows:array<int,array<string,string>>}
 */
function list_server_related_assets(
    int $companyId,
    int $projectId,
    int $accountId,
    string $serverExternalId,
    array $server
): array {
    $serverExternalId = trim($serverExternalId);
    if ($serverExternalId === '' || $accountId <= 0) {
        return ['counts' => [], 'rows' => []];
    }

    $relatedIds = hetzner_server_related_ids($server);
    $trackTypes = ['firewalls', 'primary_ips', 'floating_ips', 'volumes', 'networks', 'placement_groups', 'load_balancers'];
    $counts = [];
    foreach ($trackTypes as $type) {
        $counts[$type] = 0;
    }

    $assets = list_project_assets($companyId, $projectId, $accountId, null);
    $rows = [];

    foreach ($assets as $asset) {
        $type = strtolower(trim((string) ($asset['asset_type'] ?? '')));
        if (!in_array($type, $trackTypes, true)) {
            continue;
        }

        $externalId = trim((string) ($asset['external_id'] ?? ''));
        if ($externalId === '') {
            continue;
        }

        $matched = false;
        if (in_array($externalId, $relatedIds[$type] ?? [], true)) {
            $matched = true;
        }

        $raw = $asset['raw_json'] ?? null;
        $decoded = [];
        if (is_string($raw) && trim($raw) !== '') {
            $candidate = json_decode($raw, true);
            if (is_array($candidate)) {
                $decoded = $candidate;
            }
        }

        if (!$matched && $type === 'primary_ips') {
            $assigneeId = $decoded['assignee_id'] ?? null;
            if (is_scalar($assigneeId) && trim((string) $assigneeId) === $serverExternalId) {
                $matched = true;
            }
        }

        if (!$matched && $type === 'floating_ips') {
            $serverId = $decoded['server'] ?? null;
            if (is_scalar($serverId) && trim((string) $serverId) === $serverExternalId) {
                $matched = true;
            }
        }

        if (!$matched && $type === 'load_balancers') {
            $targets = $decoded['targets'] ?? [];
            if (is_array($targets)) {
                foreach ($targets as $target) {
                    $targetServerId = is_array($target) ? ($target['server']['id'] ?? null) : null;
                    if (is_scalar($targetServerId) && trim((string) $targetServerId) === $serverExternalId) {
                        $matched = true;
                        break;
                    }
                }
            }
        }

        if (!$matched) {
            continue;
        }

        $counts[$type]++;
        $rows[] = [
            'asset_type' => $type,
            'external_id' => $externalId,
            'name' => trim((string) ($asset['name'] ?? '')) !== '' ? trim((string) ($asset['name'] ?? '')) : ('item-' . $externalId),
            'status' => trim((string) ($asset['status'] ?? '')) !== '' ? trim((string) ($asset['status'] ?? '')) : '-',
            'datacenter' => trim((string) ($asset['datacenter'] ?? '')) !== '' ? trim((string) ($asset['datacenter'] ?? '')) : '-',
        ];
    }

    usort(
        $rows,
        static function (array $a, array $b): int {
            $typeCmp = strcmp((string) ($a['asset_type'] ?? ''), (string) ($b['asset_type'] ?? ''));
            if ($typeCmp !== 0) {
                return $typeCmp;
            }
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        }
    );

    return ['counts' => $counts, 'rows' => $rows];
}

/**
 * @param array<string,mixed> $server
 * @return array{currency:string,location:?string,source:string,hourly_net:?float,hourly_gross:?float,monthly_net:?float,monthly_gross:?float,daily_gross:?float,mtd_gross:?float,forecast_month_gross:?float}
 */
function hetzner_server_cost_estimate(array $server): array
{
    $raw = hetzner_server_decode_raw($server);
    $prices = $raw['server_type']['prices'] ?? [];
    if (!is_array($prices)) {
        $prices = [];
    }

    $location = null;
    $locationCandidates = [
        $raw['datacenter']['location']['name'] ?? null,
        $server['datacenter'] ?? null,
    ];
    foreach ($locationCandidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            $location = trim($candidate);
            break;
        }
    }

    $chosen = null;
    if ($location !== null) {
        foreach ($prices as $price) {
            if (!is_array($price)) {
                continue;
            }
            $priceLocation = trim((string) ($price['location'] ?? ''));
            if ($priceLocation !== '' && strcasecmp($priceLocation, $location) === 0) {
                $chosen = $price;
                break;
            }
        }
    }
    if ($chosen === null && isset($prices[0]) && is_array($prices[0])) {
        $chosen = $prices[0];
    }

    $toFloat = static function ($value): ?float {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && trim($value) !== '' && is_numeric($value)) {
            return (float) $value;
        }
        return null;
    };

    $currency = trim((string) (($chosen['price_monthly']['currency'] ?? $chosen['price_hourly']['currency'] ?? 'EUR')));
    if ($currency === '') {
        $currency = 'EUR';
    }

    $hourlyNet = $toFloat($chosen['price_hourly']['net'] ?? null);
    $hourlyGross = $toFloat($chosen['price_hourly']['gross'] ?? null);
    $monthlyNet = $toFloat($chosen['price_monthly']['net'] ?? null);
    $monthlyGross = $toFloat($chosen['price_monthly']['gross'] ?? null);

    $daysInMonth = (int) date('t');
    $dayOfMonth = (int) date('j');
    $dailyGross = null;
    $mtdGross = null;
    if ($monthlyGross !== null && $daysInMonth > 0) {
        $dailyGross = $monthlyGross / $daysInMonth;
        $mtdGross = $dailyGross * $dayOfMonth;
    }

    return [
        'currency' => $currency,
        'location' => $location,
        'source' => $chosen === null ? 'indisponivel' : 'server_type.prices',
        'hourly_net' => $hourlyNet,
        'hourly_gross' => $hourlyGross,
        'monthly_net' => $monthlyNet,
        'monthly_gross' => $monthlyGross,
        'daily_gross' => $dailyGross,
        'mtd_gross' => $mtdGross,
        'forecast_month_gross' => $monthlyGross,
    ];
}

/**
 * @return array{source:string,as_of:?string,rates:array<string,float>}
 */
function fetch_brl_exchange_rates(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $url = trim((string) env_value('FX_RATES_URL', 'https://economia.awesomeapi.com.br/json/last/USD-BRL,EUR-BRL'));
    $result = [
        'source' => $url,
        'as_of' => null,
        'rates' => [],
    ];

    if ($url === '') {
        $cached = $result;
        return $result;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        $cached = $result;
        return $result;
    }

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]
    );
    $body = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body) || $body === '' || $httpStatus < 200 || $httpStatus >= 300) {
        $cached = $result;
        return $result;
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        $cached = $result;
        return $result;
    }

    $pairs = [
        'USD' => 'USDBRL',
        'EUR' => 'EURBRL',
    ];

    foreach ($pairs as $base => $key) {
        $pair = $decoded[$key] ?? null;
        if (!is_array($pair)) {
            continue;
        }
        $bidRaw = $pair['bid'] ?? null;
        if (is_string($bidRaw) && is_numeric($bidRaw)) {
            $result['rates'][$base] = (float) $bidRaw;
        } elseif (is_float($bidRaw) || is_int($bidRaw)) {
            $result['rates'][$base] = (float) $bidRaw;
        }
        if ($result['as_of'] === null) {
            $asOfRaw = $pair['create_date'] ?? null;
            if (is_string($asOfRaw) && trim($asOfRaw) !== '') {
                $result['as_of'] = trim($asOfRaw);
            }
        }
    }

    $cached = $result;
    return $result;
}

function convert_amount_to_brl(?float $value, string $currency, array $rates): ?float
{
    if ($value === null) {
        return null;
    }

    $from = strtoupper(trim($currency));
    if ($from === 'BRL') {
        return $value;
    }

    $map = $rates['rates'] ?? [];
    if (!is_array($map)) {
        return null;
    }
    $rate = $map[$from] ?? null;
    if (!is_float($rate) && !is_int($rate)) {
        return null;
    }

    return $value * (float) $rate;
}
