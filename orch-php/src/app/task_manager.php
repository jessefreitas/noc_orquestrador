<?php
declare(strict_types=1);

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/db.php';

function ensure_ai_task_tables(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS ai_tasks (
            id BIGSERIAL PRIMARY KEY,
            company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            server_id BIGINT REFERENCES hetzner_servers(id) ON DELETE SET NULL,
            diagnostic_id BIGINT,
            actor_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'ai_v4',
            title VARCHAR(200) NOT NULL,
            description TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'todo',
            priority VARCHAR(20) NOT NULL DEFAULT 'medium',
            range_window VARCHAR(20),
            query_text TEXT,
            context_json JSONB NOT NULL DEFAULT '{}'::jsonb,
            completed_at TIMESTAMPTZ NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )"
    );
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ai_tasks_scope ON ai_tasks (company_id, project_id, created_at DESC)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ai_tasks_status ON ai_tasks (company_id, project_id, status, priority, created_at DESC)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ai_tasks_server ON ai_tasks (company_id, project_id, server_id, created_at DESC)");

    $ensured = true;
}

function ai_task_normalize_status(string $status): string
{
    $raw = strtolower(trim($status));
    if (in_array($raw, ['todo', 'in_progress', 'blocked', 'done'], true)) {
        return $raw;
    }
    return 'todo';
}

function ai_task_normalize_priority(string $priority): string
{
    $raw = strtolower(trim($priority));
    if (in_array($raw, ['low', 'medium', 'high', 'critical'], true)) {
        return $raw;
    }
    return 'medium';
}

function ai_task_priority_from_text(string $text): string
{
    $t = strtolower($text);
    if (preg_match('/\b(critic|urgente|imediat|ataque|brute force|ransom|vazamento)\b/u', $t) === 1) {
        return 'critical';
    }
    if (preg_match('/\b(alta|erro grave|incidente|falha recorrente|oom|no space|timeout)\b/u', $t) === 1) {
        return 'high';
    }
    if (preg_match('/\b(baixa|melhoria|ajuste fino)\b/u', $t) === 1) {
        return 'low';
    }
    return 'medium';
}

/**
 * @return array<int,array{title:string,description:string,priority:string}>
 */
function ai_task_extract_candidates(string $analysisText, string $serverName): array
{
    $analysisText = trim($analysisText);
    if ($analysisText === '') {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $analysisText) ?: [];
    $tasks = [];
    $seen = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        $line = preg_replace('/^\s*[-*]\s*/', '', $line) ?? $line;
        $line = preg_replace('/^\s*\d+[\)\.\-]\s*/', '', $line) ?? $line;
        if (mb_strlen($line) < 18) {
            continue;
        }

        if (preg_match('/\b(acao|mitiga|corrig|ajust|bloque|habilit|investig|revis|rotacion|monitor|patch|atualiz|hardening|limitar|firewall|snapshot|backup)\w*/iu', $line) !== 1) {
            continue;
        }

        $title = $line;
        if (mb_strlen($title) > 180) {
            $title = mb_substr($title, 0, 177) . '...';
        }
        $key = mb_strtolower($title, 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $tasks[] = [
            'title' => $title,
            'description' => 'Tarefa sugerida automaticamente pela IA para ' . ($serverName !== '' ? $serverName : 'o servidor') . '.',
            'priority' => ai_task_priority_from_text($line),
        ];
        if (count($tasks) >= 5) {
            break;
        }
    }

    $text = mb_strtolower($analysisText, 'UTF-8');
    $appendFallback = static function (string $title, string $description, string $priority) use (&$tasks, &$seen): void {
        if (count($tasks) >= 5) {
            return;
        }
        $key = mb_strtolower($title, 'UTF-8');
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $tasks[] = ['title' => $title, 'description' => $description, 'priority' => $priority];
    };

    if (str_contains($text, 'brute') || str_contains($text, 'ssh') || str_contains($text, 'failed password')) {
        $appendFallback(
            'Endurecer SSH e bloquear tentativas de brute force',
            'Aplicar hardening (porta/chave/allowlist), fail2ban e rate-limit para reduzir ataque.',
            'critical'
        );
    }
    if (str_contains($text, 'timeout') || str_contains($text, '502') || str_contains($text, '504') || str_contains($text, 'upstream')) {
        $appendFallback(
            'Revisar timeout e cadeia proxy/upstream',
            'Validar Nginx/Traefik/app, healthchecks e capacidade do backend para eliminar 502/504.',
            'high'
        );
    }
    if (str_contains($text, 'oom') || str_contains($text, 'out of memory') || str_contains($text, 'memory')) {
        $appendFallback(
            'Mitigar OOM e ajustar consumo de memoria',
            'Revisar limites de memoria por servico, leaks e swap para evitar quedas.',
            'high'
        );
    }
    if (str_contains($text, 'disk') || str_contains($text, 'disco') || str_contains($text, 'no space') || str_contains($text, 'inode')) {
        $appendFallback(
            'Corrigir pressao de disco/inode',
            'Limpar artefatos, revisar retenção de logs e definir alertas de capacidade.',
            'high'
        );
    }

    if ($tasks === []) {
        $appendFallback(
            'Validar diagnostico IA e executar plano de mitigacao',
            'Conferir evidencias do diagnostico salvo, aplicar mitigacoes e registrar resultado.',
            ai_task_priority_from_text($analysisText)
        );
    }

    return $tasks;
}

/**
 * @return array{created:int,skipped:int,ids:array<int,int>}
 */
function create_ai_tasks_from_analysis(
    int $companyId,
    int $projectId,
    int $serverId,
    int $actorUserId,
    int $diagnosticId,
    string $analysisText,
    string $rangeWindow = '',
    string $queryText = '',
    string $serverName = ''
): array {
    ensure_ai_task_tables();
    $candidates = ai_task_extract_candidates($analysisText, $serverName);
    $created = 0;
    $skipped = 0;
    $ids = [];

    foreach ($candidates as $candidate) {
        $title = trim((string) ($candidate['title'] ?? ''));
        if ($title === '') {
            $skipped++;
            continue;
        }

        $existsStmt = db()->prepare(
            "SELECT id
             FROM ai_tasks
             WHERE company_id = :company_id
               AND project_id = :project_id
               AND server_id = :server_id
               AND lower(title) = lower(:title)
               AND status IN ('todo', 'in_progress', 'blocked')
               AND created_at >= NOW() - INTERVAL '14 days'
             LIMIT 1"
        );
        $existsStmt->execute([
            'company_id' => $companyId,
            'project_id' => $projectId,
            'server_id' => $serverId,
            'title' => $title,
        ]);
        $existingId = $existsStmt->fetchColumn();
        if (is_numeric($existingId) && (int) $existingId > 0) {
            $skipped++;
            continue;
        }

        $insertStmt = db()->prepare(
            "INSERT INTO ai_tasks (
                company_id, project_id, server_id, diagnostic_id, actor_user_id, source,
                title, description, status, priority, range_window, query_text, context_json
            ) VALUES (
                :company_id, :project_id, :server_id, :diagnostic_id, :actor_user_id, :source,
                :title, :description, :status, :priority, :range_window, :query_text, :context_json::jsonb
            )
            RETURNING id"
        );
        $insertStmt->execute([
            'company_id' => $companyId,
            'project_id' => $projectId,
            'server_id' => $serverId,
            'diagnostic_id' => $diagnosticId > 0 ? $diagnosticId : null,
            'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'source' => 'ai_v4',
            'title' => substr($title, 0, 200),
            'description' => trim((string) ($candidate['description'] ?? '')) !== '' ? (string) $candidate['description'] : null,
            'status' => 'todo',
            'priority' => ai_task_normalize_priority((string) ($candidate['priority'] ?? 'medium')),
            'range_window' => trim($rangeWindow) !== '' ? trim($rangeWindow) : null,
            'query_text' => trim($queryText) !== '' ? trim($queryText) : null,
            'context_json' => json_encode(['origin' => 'server_logs_ai'], JSON_UNESCAPED_SLASHES),
        ]);
        $taskId = (int) $insertStmt->fetchColumn();
        if ($taskId <= 0) {
            $skipped++;
            continue;
        }
        $ids[] = $taskId;
        $created++;
    }

    if ($created > 0) {
        audit_log(
            $companyId,
            $projectId,
            $actorUserId > 0 ? $actorUserId : null,
            'ai.tasks.created',
            'hetzner_server',
            (string) $serverId,
            null,
            ['diagnostic_id' => $diagnosticId, 'created' => $created, 'skipped' => $skipped, 'task_ids' => $ids]
        );
    }

    return ['created' => $created, 'skipped' => $skipped, 'ids' => $ids];
}

/**
 * @param array<string,mixed> $filters
 * @return array<int,array<string,mixed>>
 */
function list_ai_tasks(int $companyId, int $projectId, array $filters = [], int $limit = 200): array
{
    ensure_ai_task_tables();

    $where = ['t.company_id = :company_id', 't.project_id = :project_id'];
    $params = [
        'company_id' => $companyId,
        'project_id' => $projectId,
    ];

    $statusFilter = strtolower(trim((string) ($filters['status'] ?? 'all')));
    if ($statusFilter === 'open') {
        $where[] = "t.status IN ('todo', 'in_progress', 'blocked')";
    } elseif (in_array($statusFilter, ['todo', 'in_progress', 'blocked', 'done'], true)) {
        $where[] = 't.status = :status';
        $params['status'] = $statusFilter;
    }

    $priorityFilter = strtolower(trim((string) ($filters['priority'] ?? 'all')));
    if (in_array($priorityFilter, ['low', 'medium', 'high', 'critical'], true)) {
        $where[] = 't.priority = :priority';
        $params['priority'] = $priorityFilter;
    }

    $serverId = (int) ($filters['server_id'] ?? 0);
    if ($serverId > 0) {
        $where[] = 't.server_id = :server_id';
        $params['server_id'] = $serverId;
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = "(t.title ILIKE :q OR COALESCE(t.description, '') ILIKE :q OR COALESCE(hs.name, '') ILIKE :q)";
        $params['q'] = '%' . $q . '%';
    }

    $stmt = db()->prepare(
        'SELECT t.*,
                hs.name AS server_name,
                hs.ipv4 AS server_ipv4
         FROM ai_tasks t
         LEFT JOIN hetzner_servers hs ON hs.id = t.server_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY
            CASE t.priority WHEN \'critical\' THEN 1 WHEN \'high\' THEN 2 WHEN \'medium\' THEN 3 ELSE 4 END,
            CASE t.status WHEN \'in_progress\' THEN 1 WHEN \'todo\' THEN 2 WHEN \'blocked\' THEN 3 ELSE 4 END,
            t.created_at DESC
         LIMIT :limit'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function update_ai_task_status(
    int $companyId,
    int $projectId,
    int $taskId,
    string $status,
    int $actorUserId
): bool {
    ensure_ai_task_tables();
    $normalizedStatus = ai_task_normalize_status($status);

    $stmt = db()->prepare(
        'UPDATE ai_tasks
         SET status = :status,
             completed_at = CASE WHEN :status = \'done\' THEN NOW() ELSE NULL END,
             updated_at = NOW()
         WHERE id = :id
           AND company_id = :company_id
           AND project_id = :project_id'
    );
    $stmt->execute([
        'id' => $taskId,
        'company_id' => $companyId,
        'project_id' => $projectId,
        'status' => $normalizedStatus,
    ]);
    if ($stmt->rowCount() <= 0) {
        return false;
    }

    audit_log(
        $companyId,
        $projectId,
        $actorUserId > 0 ? $actorUserId : null,
        'ai.task.status_updated',
        'ai_task',
        (string) $taskId,
        null,
        ['status' => $normalizedStatus]
    );
    return true;
}
