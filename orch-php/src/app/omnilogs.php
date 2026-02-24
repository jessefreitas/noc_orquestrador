<?php
declare(strict_types=1);

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/observability_config.php';

function omnilogs_humanize_ssh_error(int $exitCode, string $output): string
{
    $normalized = strtolower($output);

    if (str_contains($normalized, 'permission denied')) {
        return 'Falha de autenticacao SSH: usuario/senha invalidos ou login por senha desabilitado no servidor.';
    }
    if (str_contains($normalized, 'connection refused')) {
        return 'Conexao SSH recusada: verifique host/IP, porta SSH e firewall.';
    }
    if (str_contains($normalized, 'operation timed out') || str_contains($normalized, 'connection timed out')) {
        return 'Timeout de conexao SSH: servidor inacessivel pela rede ou porta bloqueada.';
    }
    if (str_contains($normalized, 'no route to host') || str_contains($normalized, 'name or service not known')) {
        return 'Host SSH invalido ou sem rota: verifique DNS/IP e conectividade.';
    }
    if (str_contains($normalized, 'host key verification failed')) {
        return 'Falha de verificacao de host SSH (host key).';
    }
    if (str_contains($normalized, 'sudo:') && str_contains($normalized, 'password')) {
        return 'O usuario SSH nao possui permissao sudo sem senha para instalar dependencias.';
    }
    if ($exitCode === 124) {
        return 'Timeout na instalacao remota do OmniLogs.';
    }

    return 'Falha ao instalar OmniLogs (exit ' . $exitCode . ').';
}

/**
 * @param array<string,mixed> $input
 * @return array{host:string,port:int,username:string,password:string,errors:array<int,string>}
 */
function omnilogs_normalize_install_input(array $input): array
{
    $host = trim((string) ($input['host'] ?? ''));
    $portRaw = trim((string) ($input['port'] ?? '22'));
    $username = trim((string) ($input['username'] ?? 'root'));
    $password = (string) ($input['password'] ?? '');

    $errors = [];
    if ($host === '') {
        $errors[] = 'Host do servidor e obrigatorio.';
    }
    if ($username === '') {
        $errors[] = 'Usuario SSH e obrigatorio.';
    }
    if ($password === '') {
        $errors[] = 'Senha SSH e obrigatoria para instalar o agente.';
    }
    $port = 22;
    if ($portRaw !== '' && ctype_digit($portRaw)) {
        $port = (int) $portRaw;
    } else {
        $errors[] = 'Porta SSH invalida.';
    }

    if ($port < 1 || $port > 65535) {
        $errors[] = 'Porta SSH deve estar entre 1 e 65535.';
    }

    return [
        'host' => $host,
        'port' => $port,
        'username' => $username,
        'password' => $password,
        'errors' => $errors,
    ];
}

/**
 * @return array{ok:bool,message:string,output:string}
 */
function omnilogs_install_agent(
    int $companyId,
    int $projectId,
    int $userId,
    int $serverId,
    string $serverExternalId,
    string $host,
    int $port,
    string $username,
    string $password,
    string $lokiPushUrl,
    string $lokiUsername = '',
    string $lokiPassword = ''
): array {
    $jobId = job_start($companyId, $projectId, 'omnilogs.install_agent', [
        'server_id' => $serverId,
        'server_external_id' => $serverExternalId,
        'host' => $host,
        'port' => $port,
        'username' => $username,
    ]);

    $sanitize = static function (string $value): string {
        return preg_replace('/[^a-zA-Z0-9._:@\\/-]/', '', $value) ?? '';
    };
    if (filter_var($lokiPushUrl, FILTER_VALIDATE_URL) === false || str_contains($lokiPushUrl, "\n") || str_contains($lokiPushUrl, "\r")) {
        $msg = 'Loki push URL invalida.';
        job_finish($jobId, 'error', $msg, ['server_id' => $serverId]);
        return ['ok' => false, 'message' => $msg, 'output' => ''];
    }
    $safeLokiUrl = $lokiPushUrl;
    $safeServerExternalId = $sanitize($serverExternalId);
    $scopeOrgId = observability_scope_org_id($companyId, $projectId);
    $safeCompanyId = (string) max(1, $companyId);
    $safeProjectId = (string) max(1, $projectId);

    $remoteScript = <<<'BASH'
set -euo pipefail

if [ "$(id -u)" -eq 0 ]; then
  SUDO=""
else
  SUDO="sudo"
fi

if ! command -v docker >/dev/null 2>&1; then
  if command -v apt-get >/dev/null 2>&1; then
    $SUDO apt-get update -y
    $SUDO apt-get install -y docker.io
  elif command -v dnf >/dev/null 2>&1; then
    $SUDO dnf install -y docker
  elif command -v yum >/dev/null 2>&1; then
    $SUDO yum install -y docker
  elif command -v apk >/dev/null 2>&1; then
    $SUDO apk add --no-cache docker
  else
    echo "ERROR: sem gerenciador de pacotes suportado para instalar Docker."
    exit 21
  fi
  $SUDO systemctl enable --now docker >/dev/null 2>&1 || true
fi

$SUDO mkdir -p /opt/omnilogs
$SUDO tee /opt/omnilogs/promtail-config.yml >/dev/null <<'YAML'
server:
  http_listen_port: 9080
  grpc_listen_port: 0

positions:
  filename: /tmp/positions.yaml

clients:
  - url: __LOKI_PUSH_URL__
    tenant_id: __LOKI_TENANT_ID__
__LOKI_BASIC_AUTH__

scrape_configs:
  - job_name: omnilogs-varlog
    static_configs:
      - targets:
          - localhost
        labels:
          job: omnilogs
          host: __HOST_LABEL__
          company_id: __COMPANY_ID__
          project_id: __PROJECT_ID__
          server_external_id: __SERVER_EXTERNAL_ID__
          __path__: /var/log/**/*.log
YAML

$SUDO docker rm -f omnilogs-agent >/dev/null 2>&1 || true
$SUDO docker run -d --name omnilogs-agent \
  --restart unless-stopped \
  -v /var/log:/var/log:ro \
  -v /opt/omnilogs/promtail-config.yml:/etc/promtail/config.yml:ro \
  grafana/promtail:2.9.8 \
  -config.file=/etc/promtail/config.yml >/dev/null

echo "OK: OmniLogs agent instalado e em execucao."
BASH;

    $basicAuthYaml = '';
    if (trim($lokiUsername) !== '') {
        $basicAuthYaml = "    basic_auth:\n"
            . "      username: " . $sanitize($lokiUsername) . "\n"
            . "      password: " . str_replace(["\n", "\r"], '', $lokiPassword) . "\n";
    }

    $remoteScript = str_replace(
        ['__LOKI_PUSH_URL__', '__LOKI_TENANT_ID__', '__LOKI_BASIC_AUTH__', '__HOST_LABEL__', '__COMPANY_ID__', '__PROJECT_ID__', '__SERVER_EXTERNAL_ID__'],
        [$safeLokiUrl, $sanitize($scopeOrgId), $basicAuthYaml, $sanitize($host), $safeCompanyId, $safeProjectId, $safeServerExternalId],
        $remoteScript
    );

    $sshpassPath = trim((string) shell_exec('command -v sshpass 2>/dev/null || true'));
    if ($sshpassPath === '') {
        $msg = 'Dependencia ausente: sshpass nao encontrado no ambiente do PHP.';
        job_finish($jobId, 'error', $msg, ['server_id' => $serverId, 'host' => $host]);
        return ['ok' => false, 'message' => $msg, 'output' => ''];
    }

    $target = $username . '@' . $host;
    $timeoutPath = trim((string) shell_exec('command -v timeout 2>/dev/null || true'));
    $commandPrefix = $timeoutPath !== '' ? 'timeout 180 ' : '';
    $command = $commandPrefix . 'sshpass -e ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -p '
        . (int) $port . ' ' . escapeshellarg($target) . ' bash -s';

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = array_merge($_ENV, ['SSHPASS' => $password]);
    $process = proc_open($command, $descriptors, $pipes, null, $env);

    if (!is_resource($process)) {
        $msg = 'Falha ao iniciar processo de instalacao OmniLogs.';
        job_finish($jobId, 'error', $msg, ['server_id' => $serverId, 'host' => $host]);
        return ['ok' => false, 'message' => $msg, 'output' => ''];
    }

    fwrite($pipes[0], $remoteScript);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $output = trim($stdout . "\n" . $stderr);
    $output = preg_replace("/^warning: permanently added .*known hosts.*$/mi", '', $output) ?? $output;
    $output = trim($output);
    $shortOutput = substr($output, 0, 4000);
    $ok = ($exitCode === 0);
    $message = $ok
        ? 'OmniLogs instalado com sucesso no servidor remoto.'
        : omnilogs_humanize_ssh_error($exitCode, $shortOutput);

    job_finish($jobId, $ok ? 'success' : 'error', $message, [
        'server_id' => $serverId,
        'server_external_id' => $serverExternalId,
        'host' => $host,
        'port' => $port,
        'username' => $username,
        'output' => $shortOutput,
    ]);

    audit_log(
        $companyId,
        $projectId,
        $userId,
        'omnilogs.agent.install',
        'server',
        (string) $serverId,
        null,
        [
            'server_external_id' => $serverExternalId,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'status' => $ok ? 'success' : 'error',
        ]
    );

    return [
        'ok' => $ok,
        'message' => $message,
        'output' => $shortOutput,
    ];
}

/**
 * @return array<int,array{status:string,message:string,started_at:string,finished_at:string}>
 */
function omnilogs_latest_install_status_by_server(int $companyId, int $projectId): array
{
    $stmt = db()->prepare(
        "WITH ranked AS (
            SELECT
                (jr.meta_json->>'server_id')::bigint AS server_id,
                jr.status,
                COALESCE(jr.message, '') AS message,
                COALESCE(jr.started_at::text, '') AS started_at,
                COALESCE(jr.finished_at::text, '') AS finished_at,
                ROW_NUMBER() OVER (
                    PARTITION BY (jr.meta_json->>'server_id')
                    ORDER BY jr.started_at DESC, jr.id DESC
                ) AS rn
            FROM job_runs jr
            WHERE jr.company_id = :company_id
              AND jr.project_id = :project_id
              AND jr.job_type = 'omnilogs.install_agent'
              AND COALESCE(jr.meta_json->>'server_id', '') ~ '^[0-9]+$'
        )
        SELECT server_id, status, message, started_at, finished_at
        FROM ranked
        WHERE rn = 1"
    );
    $stmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
    ]);

    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        return [];
    }

    $byServer = [];
    foreach ($rows as $row) {
        $serverId = (int) ($row['server_id'] ?? 0);
        if ($serverId <= 0) {
            continue;
        }
        $byServer[$serverId] = [
            'status' => (string) ($row['status'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'finished_at' => (string) ($row['finished_at'] ?? ''),
        ];
    }

    return $byServer;
}
