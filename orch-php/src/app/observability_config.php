<?php
declare(strict_types=1);

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function observability_scope_org_id(int $companyId, int $projectId): string
{
    return 'c' . $companyId . '-p' . $projectId;
}

function ensure_project_observability_table(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS project_observability_configs (
            id BIGSERIAL PRIMARY KEY,
            company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            loki_push_url TEXT,
            loki_username VARCHAR(190),
            loki_password_ciphertext TEXT,
            vm_base_url TEXT,
            retention_hours INTEGER NOT NULL DEFAULT 168,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            UNIQUE (company_id, project_id)
        )"
    );
    db()->exec('ALTER TABLE project_observability_configs ADD COLUMN IF NOT EXISTS retention_hours INTEGER NOT NULL DEFAULT 168');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_project_observability_scope ON project_observability_configs (company_id, project_id, status)');

    $ensured = true;
}

function get_project_observability_config(int $companyId, int $projectId): ?array
{
    ensure_project_observability_table();

    $stmt = db()->prepare(
        'SELECT *
         FROM project_observability_configs
         WHERE company_id = :company_id
           AND project_id = :project_id
         LIMIT 1'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
    ]);

    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    if (is_string($row['loki_password_ciphertext'] ?? null) && trim((string) $row['loki_password_ciphertext']) !== '') {
        try {
            $row['loki_password'] = decrypt_secret((string) $row['loki_password_ciphertext']);
        } catch (Throwable $exception) {
            $row['loki_password'] = '';
        }
    } else {
        $row['loki_password'] = '';
    }

    return $row;
}

function first_env_value(array $keys): string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if (!is_string($value)) {
            continue;
        }
        $trimmed = trim($value);
        if ($trimmed !== '') {
            return $trimmed;
        }
    }

    return '';
}

/**
 * @return array{loki_push_url:string,loki_username:string,loki_password:string,vm_base_url:string,retention_hours:int,status:string}
 */
function observability_defaults_from_env(): array
{
    $lokiPushUrl = first_env_value(['OMNINOC_LOKI_PUSH_URL', 'OMNILOGS_LOKI_PUSH_URL']);
    $lokiUsername = first_env_value(['OMNINOC_LOKI_USERNAME', 'OMNILOGS_LOKI_USERNAME']);
    $lokiPassword = first_env_value(['OMNINOC_LOKI_PASSWORD', 'OMNILOGS_LOKI_PASSWORD']);
    $vmBaseUrl = first_env_value(['OMNINOC_VM_BASE_URL', 'OMNILOGS_VM_BASE_URL']);
    $retentionHoursRaw = first_env_value(['OMNINOC_LOG_RETENTION_HOURS']);
    $retentionHours = ctype_digit($retentionHoursRaw) ? (int) $retentionHoursRaw : 168;
    if ($retentionHours < 24) {
        $retentionHours = 24;
    }
    if ($retentionHours > 720) {
        $retentionHours = 720;
    }

    return [
        'loki_push_url' => $lokiPushUrl,
        'loki_username' => $lokiUsername,
        'loki_password' => $lokiPassword,
        'vm_base_url' => $vmBaseUrl,
        'retention_hours' => $retentionHours,
        'status' => $lokiPushUrl !== '' ? 'active' : 'inactive',
    ];
}

function ensure_project_observability_defaults(
    int $companyId,
    int $projectId,
    int $userId,
    bool $forceActive = true
): ?array {
    $existing = get_project_observability_config($companyId, $projectId);
    $defaults = observability_defaults_from_env();
    $hasDefaultLoki = trim((string) $defaults['loki_push_url']) !== '';

    if (!$hasDefaultLoki) {
        return $existing;
    }

    $payload = [
        'loki_push_url' => (string) ($existing['loki_push_url'] ?? ''),
        'loki_username' => (string) ($existing['loki_username'] ?? ''),
        'loki_password' => '',
        'vm_base_url' => (string) ($existing['vm_base_url'] ?? ''),
        'retention_hours' => (int) ($existing['retention_hours'] ?? 0),
        'status' => (string) ($existing['status'] ?? 'inactive'),
    ];

    $changed = false;
    if ($payload['loki_push_url'] === '') {
        $payload['loki_push_url'] = $defaults['loki_push_url'];
        $changed = true;
    }
    if ($payload['loki_username'] === '' && $defaults['loki_username'] !== '') {
        $payload['loki_username'] = $defaults['loki_username'];
        $changed = true;
    }
    if ($payload['vm_base_url'] === '' && $defaults['vm_base_url'] !== '') {
        $payload['vm_base_url'] = $defaults['vm_base_url'];
        $changed = true;
    }
    if ($payload['retention_hours'] <= 0) {
        $payload['retention_hours'] = (int) $defaults['retention_hours'];
        $changed = true;
    }
    if (($existing === null || trim((string) ($existing['loki_password'] ?? '')) === '') && $defaults['loki_password'] !== '') {
        $payload['loki_password'] = $defaults['loki_password'];
        $changed = true;
    }
    if ($forceActive && strtolower(trim($payload['status'])) !== 'active') {
        $payload['status'] = 'active';
        $changed = true;
    }

    if (!$changed) {
        return $existing;
    }

    save_project_observability_config($companyId, $projectId, $userId, $payload);
    return get_project_observability_config($companyId, $projectId);
}

/**
 * @param array<string,mixed> $payload
 */
function save_project_observability_config(
    int $companyId,
    int $projectId,
    int $userId,
    array $payload
): void {
    ensure_project_observability_table();

    $lokiPushUrl = trim((string) ($payload['loki_push_url'] ?? ''));
    $lokiUsername = trim((string) ($payload['loki_username'] ?? ''));
    $lokiPassword = (string) ($payload['loki_password'] ?? '');
    $vmBaseUrl = trim((string) ($payload['vm_base_url'] ?? ''));
    $retentionHours = (int) ($payload['retention_hours'] ?? 168);
    $status = strtolower(trim((string) ($payload['status'] ?? 'active')));
    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }
    if ($retentionHours < 24 || $retentionHours > 720) {
        throw new InvalidArgumentException('Retencao de logs deve estar entre 24h e 720h.');
    }

    if ($lokiPushUrl !== '' && filter_var($lokiPushUrl, FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException('Loki Push URL invalida.');
    }
    if ($vmBaseUrl !== '' && filter_var($vmBaseUrl, FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException('VictoriaMetrics URL invalida.');
    }

    $existing = get_project_observability_config($companyId, $projectId);
    $passwordCiphertext = $existing['loki_password_ciphertext'] ?? null;
    if ($lokiPassword !== '') {
        $passwordCiphertext = encrypt_secret($lokiPassword);
    }

    $stmt = db()->prepare(
        'INSERT INTO project_observability_configs (
            company_id,
            project_id,
            loki_push_url,
            loki_username,
            loki_password_ciphertext,
            vm_base_url,
            retention_hours,
            status,
            updated_at
         ) VALUES (
            :company_id,
            :project_id,
            :loki_push_url,
            :loki_username,
            :loki_password_ciphertext,
            :vm_base_url,
            :retention_hours,
            :status,
            NOW()
         )
         ON CONFLICT (company_id, project_id)
         DO UPDATE SET
            loki_push_url = EXCLUDED.loki_push_url,
            loki_username = EXCLUDED.loki_username,
            loki_password_ciphertext = COALESCE(EXCLUDED.loki_password_ciphertext, project_observability_configs.loki_password_ciphertext),
            vm_base_url = EXCLUDED.vm_base_url,
            retention_hours = EXCLUDED.retention_hours,
            status = EXCLUDED.status,
            updated_at = NOW()'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
        'loki_push_url' => $lokiPushUrl !== '' ? $lokiPushUrl : null,
        'loki_username' => $lokiUsername !== '' ? $lokiUsername : null,
        'loki_password_ciphertext' => is_string($passwordCiphertext) && trim($passwordCiphertext) !== '' ? $passwordCiphertext : null,
        'vm_base_url' => $vmBaseUrl !== '' ? $vmBaseUrl : null,
        'retention_hours' => $retentionHours,
        'status' => $status,
    ]);

    audit_log(
        $companyId,
        $projectId,
        $userId,
        'observability.config.saved',
        'project',
        (string) $projectId,
        null,
        [
            'loki_push_url' => $lokiPushUrl,
            'loki_username' => $lokiUsername,
            'vm_base_url' => $vmBaseUrl,
            'retention_hours' => $retentionHours,
            'status' => $status,
            'loki_password_updated' => $lokiPassword !== '',
        ]
    );
}
