<?php
declare(strict_types=1);

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/hetzner.php';
require_once __DIR__ . '/jobs.php';

function ensure_snapshot_policy_tables(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS snapshot_policies (
            id BIGSERIAL PRIMARY KEY,
            company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            server_id BIGINT NOT NULL REFERENCES hetzner_servers(id) ON DELETE CASCADE,
            enabled BOOLEAN NOT NULL DEFAULT FALSE,
            schedule_mode VARCHAR(20) NOT NULL DEFAULT 'manual',
            interval_minutes INTEGER,
            retention_days INTEGER,
            retention_count INTEGER,
            last_run_at TIMESTAMPTZ,
            next_run_at TIMESTAMPTZ,
            last_status VARCHAR(20),
            last_error TEXT,
            created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
            updated_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            UNIQUE (server_id)
        )"
    );

    db()->exec('CREATE INDEX IF NOT EXISTS idx_snapshot_policies_scope ON snapshot_policies (company_id, project_id, enabled, next_run_at)');

    db()->exec(
        "CREATE TABLE IF NOT EXISTS snapshot_runs (
            id BIGSERIAL PRIMARY KEY,
            company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            server_id BIGINT NOT NULL REFERENCES hetzner_servers(id) ON DELETE CASCADE,
            policy_id BIGINT REFERENCES snapshot_policies(id) ON DELETE SET NULL,
            run_type VARCHAR(20) NOT NULL DEFAULT 'manual',
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            snapshot_external_id VARCHAR(120),
            message TEXT,
            meta_json JSONB NOT NULL DEFAULT '{}'::jsonb,
            started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            finished_at TIMESTAMPTZ
        )"
    );

    db()->exec('CREATE INDEX IF NOT EXISTS idx_snapshot_runs_scope ON snapshot_runs (company_id, project_id, server_id, started_at DESC)');
    $ensured = true;
}

/**
 * @return array{enabled:bool,schedule_mode:string,interval_minutes:?int,retention_days:?int,retention_count:?int,last_run_at:?string,next_run_at:?string,last_status:?string,last_error:?string}
 */
function snapshot_policy_defaults(): array
{
    return [
        'enabled' => false,
        'schedule_mode' => 'manual',
        'interval_minutes' => null,
        'retention_days' => 7,
        'retention_count' => 14,
        'last_run_at' => null,
        'next_run_at' => null,
        'last_status' => null,
        'last_error' => null,
    ];
}

/**
 * @return array<string,mixed>
 */
function get_server_snapshot_policy(int $companyId, int $projectId, int $serverId): array
{
    ensure_snapshot_policy_tables();
    $defaults = snapshot_policy_defaults();

    $stmt = db()->prepare(
        'SELECT *
         FROM snapshot_policies
         WHERE company_id = :company_id
           AND project_id = :project_id
           AND server_id = :server_id
         LIMIT 1'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'server_id' => $serverId,
    ]);
    $row = $stmt->fetch();

    if (!is_array($row)) {
        return $defaults;
    }

    return array_merge($defaults, $row);
}

/**
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function snapshot_policy_normalize_payload(array $payload): array
{
    $enabled = isset($payload['enabled']) && (string) $payload['enabled'] === '1';
    $scheduleMode = strtolower(trim((string) ($payload['schedule_mode'] ?? 'manual')));
    if (!in_array($scheduleMode, ['manual', 'interval'], true)) {
        $scheduleMode = 'manual';
    }

    $interval = null;
    if (isset($payload['interval_hours']) && trim((string) $payload['interval_hours']) !== '') {
        $hoursRaw = str_replace(',', '.', trim((string) $payload['interval_hours']));
        if (!is_numeric($hoursRaw)) {
            throw new InvalidArgumentException('Intervalo em horas invalido.');
        }
        $hours = (float) $hoursRaw;
        $interval = (int) round($hours * 60);
    } elseif (isset($payload['interval_minutes']) && trim((string) $payload['interval_minutes']) !== '') {
        // Compatibilidade com payload antigo.
        $interval = (int) $payload['interval_minutes'];
    }
    if ($scheduleMode !== 'interval') {
        $interval = null;
    }
    if ($interval !== null && ($interval < 5 || $interval > 10080)) {
        throw new InvalidArgumentException('Intervalo deve ficar entre 1 e 168 horas.');
    }

    $retentionDays = isset($payload['retention_days']) && trim((string) $payload['retention_days']) !== ''
        ? (int) $payload['retention_days']
        : null;
    if ($retentionDays !== null && ($retentionDays < 1 || $retentionDays > 3650)) {
        throw new InvalidArgumentException('Retencao em dias deve ficar entre 1 e 3650.');
    }

    $retentionCount = isset($payload['retention_count']) && trim((string) $payload['retention_count']) !== ''
        ? (int) $payload['retention_count']
        : null;
    if ($retentionCount !== null && ($retentionCount < 1 || $retentionCount > 500)) {
        throw new InvalidArgumentException('Retencao por quantidade deve ficar entre 1 e 500 snapshots.');
    }

    return [
        'enabled' => $enabled,
        'schedule_mode' => $scheduleMode,
        'interval_minutes' => $interval,
        'retention_days' => $retentionDays,
        'retention_count' => $retentionCount,
    ];
}

/**
 * @param array<string,mixed> $payload
 */
function save_server_snapshot_policy(
    int $companyId,
    int $projectId,
    int $serverId,
    int $userId,
    array $payload
): void {
    ensure_snapshot_policy_tables();
    $data = snapshot_policy_normalize_payload($payload);
    $before = get_server_snapshot_policy($companyId, $projectId, $serverId);

    $nextRunExpr = 'NULL';
    if ($data['enabled'] && $data['schedule_mode'] === 'interval' && is_int($data['interval_minutes'])) {
        $nextRunExpr = 'NOW() + make_interval(mins => :interval_minutes)';
    }

    $sql = 'INSERT INTO snapshot_policies (
                company_id,
                project_id,
                server_id,
                enabled,
                schedule_mode,
                interval_minutes,
                retention_days,
                retention_count,
                next_run_at,
                created_by,
                updated_by,
                created_at,
                updated_at
            ) VALUES (
                :company_id,
                :project_id,
                :server_id,
                :enabled,
                :schedule_mode,
                :interval_minutes,
                :retention_days,
                :retention_count,
                ' . $nextRunExpr . ',
                :created_by,
                :updated_by,
                NOW(),
                NOW()
            )
            ON CONFLICT (server_id)
            DO UPDATE SET
                enabled = EXCLUDED.enabled,
                schedule_mode = EXCLUDED.schedule_mode,
                interval_minutes = EXCLUDED.interval_minutes,
                retention_days = EXCLUDED.retention_days,
                retention_count = EXCLUDED.retention_count,
                next_run_at = EXCLUDED.next_run_at,
                updated_by = EXCLUDED.updated_by,
                updated_at = NOW()';

    $stmt = db()->prepare($sql);
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'server_id' => $serverId,
        'enabled' => $data['enabled'] ? 1 : 0,
        'schedule_mode' => $data['schedule_mode'],
        'interval_minutes' => $data['interval_minutes'],
        'retention_days' => $data['retention_days'],
        'retention_count' => $data['retention_count'],
        'created_by' => $userId,
        'updated_by' => $userId,
    ]);

    $after = get_server_snapshot_policy($companyId, $projectId, $serverId);
    audit_log(
        $companyId,
        $projectId,
        $userId,
        'snapshot.policy.updated',
        'hetzner_server',
        (string) $serverId,
        is_array($before) ? $before : null,
        is_array($after) ? $after : null
    );
}

/**
 * @return array<int,array<string,mixed>>
 */
function list_server_snapshot_runs(int $companyId, int $projectId, int $serverId, int $limit = 20): array
{
    ensure_snapshot_policy_tables();
    $stmt = db()->prepare(
        'SELECT *
         FROM snapshot_runs
         WHERE company_id = :company_id
           AND project_id = :project_id
           AND server_id = :server_id
         ORDER BY started_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
    $stmt->bindValue(':project_id', $projectId, PDO::PARAM_INT);
    $stmt->bindValue(':server_id', $serverId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

/**
 * @return array<int,array<string,mixed>>
 */
function list_project_snapshot_policy_overview(int $companyId, int $projectId): array
{
    ensure_snapshot_policy_tables();
    $stmt = db()->prepare(
        'SELECT hs.id AS server_id,
                hs.external_id AS server_external_id,
                hs.name AS server_name,
                hs.status AS server_status,
                hs.provider_account_id,
                pa.label AS account_label,
                sp.id AS policy_id,
                sp.enabled,
                sp.schedule_mode,
                sp.interval_minutes,
                sp.retention_days,
                sp.retention_count,
                sp.last_run_at,
                sp.next_run_at,
                sp.last_status,
                sp.last_error
         FROM hetzner_servers hs
         INNER JOIN provider_accounts pa ON pa.id = hs.provider_account_id
         LEFT JOIN snapshot_policies sp
           ON sp.server_id = hs.id
          AND sp.company_id = hs.company_id
          AND sp.project_id = hs.project_id
         WHERE hs.company_id = :company_id
           AND hs.project_id = :project_id
         ORDER BY hs.name ASC'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
    ]);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

/**
 * @return array<int,array<string,mixed>>
 */
function list_project_snapshot_runs(int $companyId, int $projectId, int $limit = 60): array
{
    ensure_snapshot_policy_tables();
    $stmt = db()->prepare(
        'SELECT sr.*,
                hs.name AS server_name,
                hs.external_id AS server_external_id
         FROM snapshot_runs sr
         INNER JOIN hetzner_servers hs ON hs.id = sr.server_id
         WHERE sr.company_id = :company_id
           AND sr.project_id = :project_id
         ORDER BY sr.started_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
    $stmt->bindValue(':project_id', $projectId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, min(300, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

/**
 * @return array<string,mixed>
 */
function snapshot_run_start(int $companyId, int $projectId, int $serverId, ?int $policyId, string $runType, array $meta = []): array
{
    ensure_snapshot_policy_tables();
    $stmt = db()->prepare(
        'INSERT INTO snapshot_runs (
            company_id,
            project_id,
            server_id,
            policy_id,
            run_type,
            status,
            meta_json,
            started_at
        ) VALUES (
            :company_id,
            :project_id,
            :server_id,
            :policy_id,
            :run_type,
            :status,
            :meta_json::jsonb,
            NOW()
        )
        RETURNING id'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'server_id' => $serverId,
        'policy_id' => $policyId,
        'run_type' => $runType,
        'status' => 'running',
        'meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES),
    ]);
    return ['id' => (int) $stmt->fetchColumn()];
}

function snapshot_run_finish(int $runId, string $status, ?string $snapshotExternalId, string $message, array $meta = []): void
{
    ensure_snapshot_policy_tables();
    $stmt = db()->prepare(
        'UPDATE snapshot_runs
         SET status = :status,
             snapshot_external_id = :snapshot_external_id,
             message = :message,
             meta_json = :meta_json::jsonb,
             finished_at = NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        'id' => $runId,
        'status' => $status,
        'snapshot_external_id' => $snapshotExternalId,
        'message' => $message,
        'meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES),
    ]);
}

/**
 * @return array<string,mixed>
 */
function snapshot_resolve_actor_user_id(int $companyId, ?int $userId): int
{
    if (is_int($userId) && $userId > 0) {
        return $userId;
    }

    $stmt = db()->prepare(
        'SELECT user_id
         FROM company_users
         WHERE company_id = :company_id
         ORDER BY CASE WHEN role = \'owner\' THEN 0 ELSE 1 END, user_id ASC
         LIMIT 1'
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

    throw new RuntimeException('Nao foi possivel resolver usuario ator para execucao automatica.');
}

/**
 * @return array<string,mixed>
 */
function snapshot_find_policy_row(int $companyId, int $projectId, int $serverId): ?array
{
    ensure_snapshot_policy_tables();
    $stmt = db()->prepare(
        'SELECT *
         FROM snapshot_policies
         WHERE company_id = :company_id
           AND project_id = :project_id
           AND server_id = :server_id
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
 * @return array<string,mixed>
 */
function snapshot_create_now_for_server(
    int $companyId,
    int $projectId,
    int $serverId,
    ?int $userId = null,
    string $runType = 'manual'
): array {
    ensure_snapshot_policy_tables();
    $server = get_project_server_by_id($companyId, $projectId, $serverId);
    if (!is_array($server)) {
        return ['ok' => false, 'message' => 'Servidor nao encontrado.'];
    }

    $accountId = (int) ($server['provider_account_id'] ?? 0);
    $serverExternalId = trim((string) ($server['external_id'] ?? ''));
    if ($accountId <= 0 || $serverExternalId === '') {
        return ['ok' => false, 'message' => 'Servidor sem conta Hetzner vinculada/external_id.'];
    }

    $account = get_hetzner_account($companyId, $projectId, $accountId);
    if (!is_array($account)) {
        return ['ok' => false, 'message' => 'Conta Hetzner nao encontrada no contexto atual.'];
    }

    $policy = snapshot_find_policy_row($companyId, $projectId, $serverId);
    $policyId = is_array($policy) ? (int) ($policy['id'] ?? 0) : null;
    $run = snapshot_run_start($companyId, $projectId, $serverId, $policyId, $runType, [
        'server_external_id' => $serverExternalId,
        'provider_account_id' => $accountId,
    ]);
    $runId = (int) ($run['id'] ?? 0);

    $jobId = job_start($companyId, $projectId, 'snapshot.run', [
        'server_id' => $serverId,
        'server_external_id' => $serverExternalId,
        'run_type' => $runType,
    ]);
    $actorUserId = snapshot_resolve_actor_user_id($companyId, $userId);

    try {
        $token = decrypt_secret((string) $account['token_ciphertext']);
        $description = 'omninoc-c' . $companyId . '-p' . $projectId . '-' . (string) ($server['name'] ?? 'server') . '-' . gmdate('YmdHis');
        $response = hetzner_api_request_ex(
            $token,
            'POST',
            '/servers/' . rawurlencode($serverExternalId) . '/actions/create_image',
            [],
            ['type' => 'snapshot', 'description' => $description]
        );
        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Hetzner retornou HTTP ' . $status . ' ao criar snapshot.');
        }

        $snapshotExternalId = null;
        $imageId = $response['body']['image']['id'] ?? null;
        if ($imageId !== null && is_scalar($imageId)) {
            $snapshotExternalId = trim((string) $imageId);
        }

        sync_hetzner_inventory($companyId, $projectId, $accountId, $actorUserId);

        if (is_array($policy)) {
            $nextRun = null;
            if (($policy['enabled'] ?? false) && (string) ($policy['schedule_mode'] ?? 'manual') === 'interval') {
                $interval = (int) ($policy['interval_minutes'] ?? 0);
                if ($interval > 0) {
                    $nextRun = 'NOW() + make_interval(mins => :interval_minutes)';
                }
            }
            $sql = 'UPDATE snapshot_policies
                    SET last_run_at = NOW(),
                        last_status = :last_status,
                        last_error = NULL,
                        next_run_at = ' . ($nextRun ?? 'NULL') . ',
                        updated_at = NOW()
                    WHERE id = :id';
            $stmt = db()->prepare($sql);
            $params = ['last_status' => 'success', 'id' => (int) $policy['id']];
            if ($nextRun !== null) {
                $params['interval_minutes'] = (int) ($policy['interval_minutes'] ?? 0);
            }
            $stmt->execute($params);
        }

        snapshot_run_finish(
            $runId,
            'success',
            $snapshotExternalId,
            'Snapshot criado com sucesso.',
            ['http_status' => $status, 'response' => $response['body'] ?? []]
        );
        job_finish($jobId, 'success', 'Snapshot criado com sucesso.', ['run_id' => $runId, 'snapshot_external_id' => $snapshotExternalId]);

        audit_log(
            $companyId,
            $projectId,
            $actorUserId,
            'snapshot.run.success',
            'hetzner_server',
            (string) $serverId,
            null,
            ['run_id' => $runId, 'run_type' => $runType, 'snapshot_external_id' => $snapshotExternalId]
        );

        $retention = snapshot_apply_retention_for_server($companyId, $projectId, $serverId, $userId);
        $retentionText = '';
        if (($retention['ok'] ?? false) && (int) ($retention['deleted_count'] ?? 0) > 0) {
            $retentionText = ' Retencao removeu ' . (int) $retention['deleted_count'] . ' snapshot(s) antigo(s).';
        }

        return [
            'ok' => true,
            'message' => 'Snapshot solicitado com sucesso e inventario atualizado.' . $retentionText,
            'snapshot_external_id' => $snapshotExternalId,
            'run_id' => $runId,
            'retention' => $retention,
        ];
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        snapshot_run_finish($runId, 'error', null, $error, ['exception' => $error]);
        job_finish($jobId, 'error', $error, ['run_id' => $runId]);

        if (is_array($policy)) {
            $nextRun = null;
            if (($policy['enabled'] ?? false) && (string) ($policy['schedule_mode'] ?? 'manual') === 'interval') {
                $interval = (int) ($policy['interval_minutes'] ?? 0);
                if ($interval > 0) {
                    $nextRun = 'NOW() + make_interval(mins => :interval_minutes)';
                }
            }
            $sql = 'UPDATE snapshot_policies
                    SET last_run_at = NOW(),
                        last_status = :last_status,
                        last_error = :last_error,
                        next_run_at = ' . ($nextRun ?? 'NULL') . ',
                        updated_at = NOW()
                    WHERE id = :id';
            $stmt = db()->prepare($sql);
            $params = ['last_status' => 'error', 'last_error' => $error, 'id' => (int) $policy['id']];
            if ($nextRun !== null) {
                $params['interval_minutes'] = (int) ($policy['interval_minutes'] ?? 0);
            }
            $stmt->execute($params);
        }

        audit_log(
            $companyId,
            $projectId,
            $actorUserId,
            'snapshot.run.error',
            'hetzner_server',
            (string) $serverId,
            null,
            ['run_id' => $runId, 'run_type' => $runType, 'error' => $error]
        );
        return ['ok' => false, 'message' => 'Falha ao criar snapshot: ' . $error];
    }
}

/**
 * @return array<int,array<string,mixed>>
 */
function snapshot_fetch_live_for_server(
    int $companyId,
    int $projectId,
    int $serverId
): array {
    $server = get_project_server_by_id($companyId, $projectId, $serverId);
    if (!is_array($server)) {
        return [];
    }
    $accountId = (int) ($server['provider_account_id'] ?? 0);
    $serverExternalId = trim((string) ($server['external_id'] ?? ''));
    if ($accountId <= 0 || $serverExternalId === '') {
        return [];
    }

    $account = get_hetzner_account($companyId, $projectId, $accountId);
    if (!is_array($account)) {
        return [];
    }

    $token = decrypt_secret((string) $account['token_ciphertext']);
    $images = hetzner_fetch_paginated_collection($token, '/images', 'images', 50, ['type' => 'snapshot']);

    $rows = [];
    foreach ($images as $image) {
        $sourceId = trim((string) ($image['created_from']['id'] ?? ''));
        if ($sourceId !== $serverExternalId) {
            continue;
        }

        $id = trim((string) ($image['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $created = (string) ($image['created'] ?? '');
        $createdTs = strtotime($created);
        $rows[] = [
            'id' => $id,
            'created' => $created,
            'created_ts' => $createdTs !== false ? $createdTs : 0,
            'description' => (string) ($image['description'] ?? ''),
            'raw' => $image,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return ((int) ($b['created_ts'] ?? 0)) <=> ((int) ($a['created_ts'] ?? 0));
    });

    return $rows;
}

/**
 * @return array{ok:bool,message:string,deleted_count:int,deleted_ids:array<int,string>}
 */
function snapshot_apply_retention_for_server(
    int $companyId,
    int $projectId,
    int $serverId,
    ?int $userId = null
): array {
    ensure_snapshot_policy_tables();
    $server = get_project_server_by_id($companyId, $projectId, $serverId);
    if (!is_array($server)) {
        return ['ok' => false, 'message' => 'Servidor nao encontrado.', 'deleted_count' => 0, 'deleted_ids' => []];
    }
    $policy = snapshot_find_policy_row($companyId, $projectId, $serverId);
    if (!is_array($policy)) {
        return ['ok' => true, 'message' => 'Sem politica de retencao configurada.', 'deleted_count' => 0, 'deleted_ids' => []];
    }

    $retentionDays = isset($policy['retention_days']) ? (int) $policy['retention_days'] : 0;
    $retentionCount = isset($policy['retention_count']) ? (int) $policy['retention_count'] : 0;
    if ($retentionDays <= 0 && $retentionCount <= 0) {
        return ['ok' => true, 'message' => 'Retencao desativada na politica.', 'deleted_count' => 0, 'deleted_ids' => []];
    }

    $accountId = (int) ($server['provider_account_id'] ?? 0);
    if ($accountId <= 0) {
        return ['ok' => false, 'message' => 'Servidor sem conta Hetzner.', 'deleted_count' => 0, 'deleted_ids' => []];
    }
    $account = get_hetzner_account($companyId, $projectId, $accountId);
    if (!is_array($account)) {
        return ['ok' => false, 'message' => 'Conta Hetzner nao encontrada.', 'deleted_count' => 0, 'deleted_ids' => []];
    }
    $token = decrypt_secret((string) $account['token_ciphertext']);

    $snapshots = snapshot_fetch_live_for_server($companyId, $projectId, $serverId);
    if ($snapshots === []) {
        return ['ok' => true, 'message' => 'Sem snapshots para aplicar retencao.', 'deleted_count' => 0, 'deleted_ids' => []];
    }

    $keepByCount = [];
    if ($retentionCount > 0) {
        foreach (array_slice($snapshots, 0, $retentionCount) as $row) {
            $keepByCount[(string) ($row['id'] ?? '')] = true;
        }
    }

    $cutoffTs = null;
    if ($retentionDays > 0) {
        $cutoffTs = time() - ($retentionDays * 86400);
    }

    $toDelete = [];
    foreach ($snapshots as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            continue;
        }

        $delete = false;
        if ($retentionCount > 0 && !isset($keepByCount[$id])) {
            $delete = true;
        }
        if ($cutoffTs !== null && (int) ($row['created_ts'] ?? 0) > 0 && (int) $row['created_ts'] < $cutoffTs) {
            $delete = true;
        }

        if ($delete) {
            $toDelete[$id] = true;
        }
    }

    if ($toDelete === []) {
        return ['ok' => true, 'message' => 'Retencao aplicada sem exclusoes.', 'deleted_count' => 0, 'deleted_ids' => []];
    }

    $deletedIds = [];
    foreach (array_keys($toDelete) as $snapshotId) {
        $response = hetzner_api_request_ex($token, 'DELETE', '/images/' . rawurlencode($snapshotId));
        $status = (int) ($response['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            $deletedIds[] = $snapshotId;
        }
    }

    if ($deletedIds !== []) {
        $actorUserId = snapshot_resolve_actor_user_id($companyId, $userId);
        sync_hetzner_inventory($companyId, $projectId, $accountId, $actorUserId);
        audit_log(
            $companyId,
            $projectId,
            $actorUserId,
            'snapshot.retention.deleted',
            'hetzner_server',
            (string) $serverId,
            null,
            ['deleted_ids' => $deletedIds, 'policy_id' => (int) ($policy['id'] ?? 0)]
        );
    }

    return [
        'ok' => true,
        'message' => 'Retencao aplicada.',
        'deleted_count' => count($deletedIds),
        'deleted_ids' => $deletedIds,
    ];
}

/**
 * @return array{processed:int,success:int,failed:int,details:array<int,array<string,mixed>>}
 */
function run_due_snapshot_policies(int $limit = 20): array
{
    ensure_snapshot_policy_tables();
    $stmt = db()->prepare(
        'SELECT sp.id AS policy_id,
                sp.company_id,
                sp.project_id,
                sp.server_id
         FROM snapshot_policies sp
         INNER JOIN hetzner_servers hs ON hs.id = sp.server_id
         WHERE sp.enabled = TRUE
           AND sp.schedule_mode = :schedule_mode
           AND sp.interval_minutes IS NOT NULL
           AND sp.interval_minutes > 0
           AND (sp.next_run_at IS NULL OR sp.next_run_at <= NOW())
         ORDER BY sp.next_run_at NULLS FIRST, sp.id ASC
         LIMIT :limit'
    );
    $stmt->bindValue(':schedule_mode', 'interval');
    $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        $rows = [];
    }

    $result = [
        'processed' => 0,
        'success' => 0,
        'failed' => 0,
        'details' => [],
    ];

    foreach ($rows as $row) {
        $companyId = (int) ($row['company_id'] ?? 0);
        $projectId = (int) ($row['project_id'] ?? 0);
        $serverId = (int) ($row['server_id'] ?? 0);
        if ($companyId <= 0 || $projectId <= 0 || $serverId <= 0) {
            continue;
        }

        $result['processed']++;
        $run = snapshot_create_now_for_server($companyId, $projectId, $serverId, null, 'scheduled');
        if (($run['ok'] ?? false) === true) {
            $result['success']++;
        } else {
            $result['failed']++;
        }
        $result['details'][] = [
            'company_id' => $companyId,
            'project_id' => $projectId,
            'server_id' => $serverId,
            'ok' => (bool) ($run['ok'] ?? false),
            'message' => (string) ($run['message'] ?? ''),
        ];
    }

    return $result;
}
