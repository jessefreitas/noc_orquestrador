<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/ai_assistant.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/hetzner.php';
require_once __DIR__ . '/../app/llm.php';
require_once __DIR__ . '/../app/omnilogs.php';
require_once __DIR__ . '/../app/observability_config.php';
require_once __DIR__ . '/../app/snapshot_policy.php';
require_once __DIR__ . '/../app/tenancy.php';
require_once __DIR__ . '/../app/ui.php';

/**
 * @return array{rows:array<int,array{ts:string,labels:string,line:string}>,error:?string}
 */
function fetch_server_loki_logs(
    string $lokiPushUrl,
    string $lokiUsername,
    string $lokiPassword,
    string $scopeOrgId,
    int $companyId,
    int $projectId,
    string $serverExternalId,
    string $rangeWindow = '15m',
    string $queryText = '',
    int $limit = 250
): array {
    if ($lokiPushUrl === '' || $serverExternalId === '') {
        return ['rows' => [], 'error' => 'Configuracao de logs incompleta.'];
    }

    $rangeMap = [
        '15m' => 15 * 60,
        '1h' => 60 * 60,
        '6h' => 6 * 60 * 60,
        '24h' => 24 * 60 * 60,
        '7d' => 7 * 24 * 60 * 60,
    ];
    if (!array_key_exists($rangeWindow, $rangeMap)) {
        $rangeWindow = '15m';
    }
    $seconds = $rangeMap[$rangeWindow];
    $endNs = (int) floor(microtime(true) * 1000000000);
    $startNs = $endNs - ($seconds * 1000000000);

    $baseQuery = '{job="omnilogs",company_id="' . $companyId . '",project_id="' . $projectId . '",server_external_id="' . addcslashes($serverExternalId, "\\\"") . '"}';
    $queryText = trim($queryText);
    if ($queryText !== '') {
        $queryTextSafe = str_replace('"', '\\"', $queryText);
        $baseQuery .= ' |= "' . $queryTextSafe . '"';
    }

    $queryUrl = preg_replace('#/loki/api/v1/push/?$#', '/loki/api/v1/query_range', $lokiPushUrl);
    if (!is_string($queryUrl) || trim($queryUrl) === '') {
        return ['rows' => [], 'error' => 'Loki Push URL invalida para consulta.'];
    }

    $fullUrl = $queryUrl
        . '?query=' . rawurlencode($baseQuery)
        . '&start=' . rawurlencode((string) $startNs)
        . '&end=' . rawurlencode((string) $endNs)
        . '&direction=BACKWARD'
        . '&limit=' . rawurlencode((string) max(1, min(5000, $limit)));

    $ch = curl_init($fullUrl);
    if ($ch === false) {
        return ['rows' => [], 'error' => 'Falha ao iniciar consulta de logs.'];
    }

    $headers = ['Accept: application/json'];
    if (trim($scopeOrgId) !== '') {
        $headers[] = 'X-Scope-OrgID: ' . $scopeOrgId;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
    ]);

    if (trim($lokiUsername) !== '') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $lokiUsername . ':' . $lokiPassword);
    }

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (!is_string($body) || $body === '') {
        $msg = $curlError !== '' ? $curlError : 'sem resposta';
        return ['rows' => [], 'error' => 'Loki sem resposta: ' . $msg];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        return ['rows' => [], 'error' => 'Loki retornou HTTP ' . $httpCode . '.'];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['rows' => [], 'error' => 'Resposta invalida do Loki.'];
    }

    $streams = $decoded['data']['result'] ?? [];
    if (!is_array($streams)) {
        return ['rows' => [], 'error' => null];
    }

    $rows = [];
    foreach ($streams as $stream) {
        if (!is_array($stream)) {
            continue;
        }
        $labels = $stream['stream'] ?? [];
        $values = $stream['values'] ?? [];
        if (!is_array($values)) {
            continue;
        }
        $labelsText = '';
        if (is_array($labels) && $labels !== []) {
            $parts = [];
            foreach ($labels as $k => $v) {
                $parts[] = (string) $k . '=' . (string) $v;
            }
            $labelsText = implode(', ', $parts);
        }
        foreach ($values as $entry) {
            if (!is_array($entry) || count($entry) < 2) {
                continue;
            }
            $tsRaw = (string) ($entry[0] ?? '');
            $line = (string) ($entry[1] ?? '');
            $tsIso = $tsRaw;
            if (ctype_digit($tsRaw) && strlen($tsRaw) >= 10) {
                $sec = (int) substr($tsRaw, 0, 10);
                $tsIso = gmdate('Y-m-d H:i:s', $sec) . ' UTC';
            }
            $rows[] = [
                'ts' => $tsIso,
                'labels' => $labelsText,
                'line' => $line,
            ];
        }
    }

    return ['rows' => $rows, 'error' => null];
}

/**
 * @param array<string,mixed> $payload
 */
function sse_emit_event(string $event, array $payload): void
{
    echo 'event: ' . $event . "\n";
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
        $json = '{}';
    }
    foreach (explode("\n", $json) as $line) {
        echo 'data: ' . $line . "\n";
    }
    echo "\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}

function build_devops_logs_prompt(array $server, array $rows, string $rangeWindow, string $queryText): string
{
    $serverName = (string) ($server['name'] ?? 'server');
    $serverIp = (string) ($server['ipv4'] ?? '-');
    $serverStatus = (string) ($server['status'] ?? '-');
    $queryText = trim($queryText);

    $lines = [];
    foreach (array_slice($rows, 0, 180) as $row) {
        $ts = (string) ($row['ts'] ?? '');
        $line = trim((string) ($row['line'] ?? ''));
        if ($line === '') {
            continue;
        }
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;
        if (strlen($line) > 300) {
            $line = substr($line, 0, 300) . '...';
        }
        $lines[] = '[' . $ts . '] ' . $line;
    }

    $header = "Contexto:\n"
        . "- Servidor: {$serverName}\n"
        . "- IP: {$serverIp}\n"
        . "- Status atual: {$serverStatus}\n"
        . "- Janela analisada: {$rangeWindow}\n"
        . "- Filtro de texto: " . ($queryText !== '' ? $queryText : '(sem filtro)') . "\n\n";

    $body = "Logs:\n" . implode("\n", $lines);
    if (trim($body) === 'Logs:') {
        $body .= "\n(sem linhas de log relevantes)";
    }

    return $header . $body;
}

function suggest_ai_diagnostic_title(string $analysis, array $server, string $rangeWindow, string $queryText = ''): string
{
    $serverName = trim((string) ($server['name'] ?? 'servidor'));
    if ($serverName === '') {
        $serverName = 'servidor';
    }
    $normalized = strtolower(trim($analysis));
    $incident = '';

    $incidentMap = [
        '/brute\s*force|forca\s*bruta|falhas?\s+de\s+autenticacao|sshd|invalid\s+user|failed\s+password/i' => 'SSH brute force',
        '/timeout|gateway\s+timeout|504|502|bad\s+gateway|upstream/i' => 'Timeout/proxy',
        '/oom|out\s+of\s+memory|killed\s+process|exit\s+137|memory\s+esgotada/i' => 'OOM/memoria',
        '/disk|disco\s+cheio|no\s+space\s+left|inode/i' => 'Disco/inodes',
        '/cpu\s+alta|load\s+average|cpu/i' => 'CPU/load',
        '/latencia|latency|erro\s+de\s+rede|network|conexao/i' => 'Rede/latencia',
        '/docker|container|swarm|healthcheck|restart|crash/i' => 'Container/servico',
        '/certificado|tls|ssl/i' => 'TLS/certificado',
    ];

    foreach ($incidentMap as $pattern => $label) {
        if (preg_match($pattern, $analysis) === 1) {
            $incident = $label;
            break;
        }
    }

    if ($incident === '') {
        $lines = preg_split('/\r\n|\r|\n/', $analysis) ?: [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\d+\)/', $line) === 1) {
                continue;
            }
            if (preg_match('/^(diagnostico|evidencias|linha do tempo|camada|classificacao|causa|explicacao|severidade|mitigacao|correcao|hardening|comandos|risco)/i', $line) === 1) {
                continue;
            }
            $line = preg_replace('/\s+/', ' ', $line) ?? $line;
            if (strlen($line) > 42) {
                $line = substr($line, 0, 42) . '...';
            }
            if ($line !== '') {
                $incident = $line;
                break;
            }
        }
    }

    if ($incident === '') {
        $incident = 'Diagnostico operacional';
    }

    $severity = '';
    if (preg_match('/\bcritica?\b/i', $analysis) === 1) {
        $severity = 'Critica';
    } elseif (preg_match('/\balta\b/i', $analysis) === 1) {
        $severity = 'Alta';
    } elseif (preg_match('/\bmedia\b/i', $analysis) === 1) {
        $severity = 'Media';
    } elseif (preg_match('/\bbaixa\b/i', $analysis) === 1) {
        $severity = 'Baixa';
    }

    $windowLabelMap = [
        '15m' => '15m',
        '1h' => '1h',
        '6h' => '6h',
        '24h' => '24h',
        '7d' => '7d',
    ];
    $windowLabel = $windowLabelMap[$rangeWindow] ?? $rangeWindow;
    $suffix = gmdate('d/m');
    $title = $incident . ' - ' . $serverName;
    if ($severity !== '') {
        $title .= ' - Sev ' . $severity;
    }
    if (trim($queryText) !== '') {
        $title .= ' - filtro';
    }
    $title .= ' - ' . $windowLabel . ' - ' . $suffix;
    return substr($title, 0, 180);
}

/**
 * @return array{ok:bool,content:string,error:?string,meta:array<string,mixed>}
 */
function analyze_server_logs_with_ai(
    int $userId,
    int $companyId,
    array $server,
    array $rows,
    string $rangeWindow,
    string $queryText
): array {
    $runtime = llm_runtime_for_devops_analysis($userId, $companyId);
    if (!is_array($runtime)) {
        return [
            'ok' => false,
            'content' => '',
            'error' => 'Nenhuma credencial LLM disponivel para analise. Configure em /llm.php ou defina LLM_GLOBAL_API_KEY/LLM_GLOBAL_PROVIDER/LLM_GLOBAL_MODEL.',
            'meta' => [],
        ];
    }

    $systemPrompt = <<<'PROMPT'
Voce e um SRE / DevOps / SecOps Senior especialista em:

- Linux (Debian/Ubuntu/CentOS)
- VPS em producao
- Docker standalone e Docker Swarm
- Traefik / Nginx
- Portainer
- Redes e firewall
- Performance e capacidade
- Seguranca ofensiva e defensiva
- Troubleshooting avancado

Assuma sempre:

- Ambiente de PRODUCAO
- Servicos expostos a internet
- Possibilidade de ataque ativo
- Ambiente com multiplos containers
- Impacto financeiro em caso de indisponibilidade

Sua funcao e INVESTIGAR e DIAGNOSTICAR de forma tecnica.

Voce deve identificar problemas relacionados a:

1) Infraestrutura VPS
   - CPU alta
   - Load average alto
   - Memoria esgotada
   - OOM Killer
   - Disco cheio
   - Inodes esgotados
   - IO alto
   - Swap excessivo
   - File descriptors esgotados

2) Docker
   - Container restartando
   - Exit codes (explicar o significado tecnico)
   - OOM em container
   - Problemas de volume
   - Problemas de rede overlay
   - Falha de healthcheck
   - Crash de servico em swarm
   - Imagem corrompida

3) Proxy reverso
   - 502
   - 504
   - Timeout
   - Backend indisponivel
   - Certificado invalido
   - Loop de redirecionamento

4) Seguranca
   - Brute force
   - Scan automatizado
   - Exploracao ativa
   - Tentativa de RCE
   - Escalacao de privilegio

OBRIGATORIO:

- Correlacionar eventos por timestamp
- Identificar camada afetada
- Classificar o tipo de incidente
- Explicar tecnicamente o erro (inclusive codigos de exit do Docker)
- Sugerir comandos praticos para diagnostico
- Sugerir comandos praticos para mitigacao
- Sugerir correcao definitiva
- Sugerir hardening preventivo

Formato obrigatorio da resposta:

1) Diagnostico resumido
2) Evidencias (ate 8 linhas de log)
3) Linha do tempo resumida
4) Camada afetada
5) Classificacao do problema
6) Causa raiz provavel
7) Explicacao tecnica detalhada (incluindo codigos de erro se houver)
8) Severidade (Baixa / Media / Alta / Critica)
9) Mitigacao imediata (com comandos)
10) Correcao definitiva
11) Hardening recomendado
12) Comandos de verificacao
13) Risco de nao corrigir
14) Prioridade do incidente (P1 / P2 / P3)
15) Estimativa de MTTR
16) Nivel de confianca do diagnostico (%)
17) Score de risco tecnico
18) Abrir incidente formal? (Sim/Nao + justificativa)
19) Recomendacao de rollback (quando aplicavel)

Se nao houver problema claro:
- Declarar explicitamente
- Sugerir monitoramentos preventivos
- Indicar metricas que devem ser observadas

Se houver exit code de container, explicar:

0   -> parada normal
1   -> erro generico
125 -> erro no docker run
126 -> comando nao executavel
127 -> comando nao encontrado
137 -> OOM killed ou SIGKILL
139 -> segmentation fault
143 -> SIGTERM
255 -> falha grave da aplicacao

Se houver indicio de exaustao de recurso:
- Informar se o problema e estrutural (VPS subdimensionada)
- Informar se e vazamento de memoria
- Informar se e crescimento nao controlado de logs
- Sugerir upgrade ou tuning

Quando o problema envolver memoria, inclua no minimo:
- free -m
- top
- htop
- docker stats
- dmesg | grep -i oom

Quando envolver disco, inclua no minimo:
- df -h
- df -i
- du -sh /*
- journalctl --vacuum-time=7d
- docker system df
- docker system prune -a

Quando envolver CPU/Load, inclua no minimo:
- uptime
- top
- htop
- mpstat -P ALL 1

Quando envolver Docker, inclua no minimo:
- docker ps -a
- docker inspect <container>
- docker logs <container> --tail 100
- docker events
PROMPT;

    $userPrompt = build_devops_logs_prompt($server, $rows, $rangeWindow, $queryText);
    return llm_openai_compatible_chat($runtime, $systemPrompt, $userPrompt, 60);
}

require_auth();
$user = current_user();
if ($user === null) {
    redirect('/login.php');
}
$userId = (int) $user['id'];

$context = load_user_context($userId);
$companyId = $context['company_id'];
$projectId = $context['project_id'];
$providerType = context_provider_type($context);
$canManage = is_int($companyId) ? user_can_manage_company_context($userId, $companyId, $user) : false;
$acceptHeader = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
$isAjaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || str_contains($acceptHeader, 'application/json');
$isSseRequest = str_contains($acceptHeader, 'text/event-stream');

if (!is_int($companyId) || !is_int($projectId) || $providerType !== 'hetzner') {
    flash_set('warning', 'Selecione empresa/fornecedor antes de abrir servidor.');
    redirect('/projects.php');
}

$serverId = (int) ($_GET['id'] ?? 0);
if ($serverId <= 0) {
    flash_set('warning', 'Servidor invalido.');
    redirect('/servers.php');
}

$server = get_project_server_by_id($companyId, $projectId, $serverId);
if ($server === null) {
    flash_set('danger', 'Servidor nao encontrado no contexto atual.');
    redirect('/servers.php');
}

$tab = (string) ($_GET['tab'] ?? 'overview');
$allowedTabs = ['overview', 'services', 'logs', 'ai_chat', 'costs', 'snapshots', 'config'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'overview';
}

$omniLogsForm = [
    'host' => trim((string) ($server['ipv4'] ?? '')),
    'port' => '22',
    'username' => 'root',
];
$observabilityConfig = ensure_project_observability_defaults($companyId, $projectId, $userId, true);
$tenantLokiPushUrl = trim((string) ($observabilityConfig['loki_push_url'] ?? ''));
$tenantLokiUsername = trim((string) ($observabilityConfig['loki_username'] ?? ''));
$tenantLokiPassword = (string) ($observabilityConfig['loki_password'] ?? '');
$tenantLokiStatus = strtolower(trim((string) ($observabilityConfig['status'] ?? 'inactive')));
$tenantLokiReady = $tenantLokiPushUrl !== '' && $tenantLokiStatus === 'active';
$tenantRetentionHours = (int) ($observabilityConfig['retention_hours'] ?? 168);
if ($tenantRetentionHours < 24 || $tenantRetentionHours > 720) {
    $tenantRetentionHours = 168;
}
$isPlatformOwner = is_platform_owner_effective($user);
$serverExternalId = trim((string) ($server['external_id'] ?? ''));
$tenantScopeOrgId = observability_scope_org_id($companyId, $projectId);
$logsRange = (string) ($_GET['range'] ?? '15m');
$logsQ = trim((string) ($_GET['q'] ?? ''));
$chatRange = (string) ($_GET['chat_range'] ?? '15m');
$chatQ = trim((string) ($_GET['chat_q'] ?? ''));
$logsExport = strtolower(trim((string) ($_GET['export'] ?? '')));
$logsRetentionHours = $tenantRetentionHours;
$logsRetentionDays = max(1, (int) floor($logsRetentionHours / 24));
$logsData = ['rows' => [], 'error' => null];
$chatLogsData = ['rows' => [], 'error' => null];
$aiAnalysis = null;
$aiAnalysisError = null;
$aiAnalysisMeta = [];
$aiDiagnosticTitlePrefill = '';
ensure_server_ai_tables();
if (!in_array($chatRange, ['15m', '1h', '6h', '24h', '7d'], true)) {
    $chatRange = '15m';
}
if ($tab === 'logs' && $tenantLokiReady && $serverExternalId !== '') {
    $logsData = fetch_server_loki_logs(
        $tenantLokiPushUrl,
        $tenantLokiUsername,
        $tenantLokiPassword,
        $tenantScopeOrgId,
        $companyId,
        $projectId,
        $serverExternalId,
        $logsRange,
        $logsQ,
        ($logsExport === 'log' || $logsExport === 'json') ? 2000 : 250
    );
}

if ($tab === 'ai_chat' && $tenantLokiReady && $serverExternalId !== '') {
    $chatLogsData = fetch_server_loki_logs(
        $tenantLokiPushUrl,
        $tenantLokiUsername,
        $tenantLokiPassword,
        $tenantScopeOrgId,
        $companyId,
        $projectId,
        $serverExternalId,
        $chatRange,
        $chatQ,
        120
    );
}

if ($tab === 'logs' && ($logsExport === 'log' || $logsExport === 'json')) {
    if (($logsData['error'] ?? null) !== null) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Falha ao exportar logs: ' . (string) $logsData['error'];
        exit;
    }

    $rows = is_array($logsData['rows'] ?? null) ? $logsData['rows'] : [];
    $safeServerName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($server['name'] ?? 'server')) ?? 'server';
    $safeServerName = trim($safeServerName, '-');
    if ($safeServerName === '') {
        $safeServerName = 'server';
    }
    $stamp = gmdate('Ymd-His');

    if ($logsExport === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="omnilogs-' . $safeServerName . '-' . $stamp . '.json"');
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $content = '';
    foreach ($rows as $row) {
        $ts = (string) ($row['ts'] ?? '');
        $line = (string) ($row['line'] ?? '');
        $content .= '[' . $ts . '] ' . $line . "\n";
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="omnilogs-' . $safeServerName . '-' . $stamp . '.log"');
    echo $content;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        if ($isAjaxRequest) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'CSRF invalido.'], JSON_UNESCAPED_SLASHES);
            exit;
        }
        flash_set('danger', 'CSRF invalido.');
        redirect('/server_details.php?id=' . $serverId . '&tab=' . urlencode($tab));
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'delete_server_from_platform') {
        if (!$canManage) {
            flash_set('danger', 'Seu usuario esta em modo leitura para esta empresa.');
            redirect('/server_details.php?id=' . $serverId . '&tab=overview');
        }

        $providerAccountId = (int) ($server['provider_account_id'] ?? 0);
        if ($providerAccountId <= 0) {
            flash_set('danger', 'Conta do fornecedor nao encontrada para este servidor.');
            redirect('/server_details.php?id=' . $serverId . '&tab=overview');
        }

        $deleteResult = delete_hetzner_server_from_platform(
            $companyId,
            $projectId,
            $providerAccountId,
            $serverId,
            $userId
        );
        if (($deleteResult['ok'] ?? false) === true) {
            flash_set('success', (string) ($deleteResult['message'] ?? 'Servidor removido da plataforma.'));
            redirect('/hetzner_account_details.php?id=' . $providerAccountId . '&tab=servers');
        }

        flash_set('danger', (string) ($deleteResult['message'] ?? 'Falha ao remover servidor da plataforma.'));
        redirect('/server_details.php?id=' . $serverId . '&tab=overview');
    }

    if ($action === 'install_omnilogs_agent') {
        if (!$canManage) {
            flash_set('danger', 'Seu usuario esta em modo leitura para esta empresa.');
            redirect('/server_details.php?id=' . $serverId . '&tab=services');
        }

        $omniLogsInput = omnilogs_normalize_install_input($_POST);
        $omniLogsForm = [
            'host' => $omniLogsInput['host'],
            'port' => (string) $omniLogsInput['port'],
            'username' => $omniLogsInput['username'],
        ];

        if ($omniLogsInput['errors'] !== []) {
            flash_set('danger', implode(' ', $omniLogsInput['errors']));
            redirect('/server_details.php?id=' . $serverId . '&tab=services');
        }
        if (!$tenantLokiReady) {
            $message = $isPlatformOwner
                ? 'Observabilidade do projeto nao configurada. Defina Loki em /observability.php antes de instalar o agente.'
                : 'Observabilidade deste tenant ainda nao esta ativa. Acione o administrador global para concluir a configuracao.';
            flash_set('danger', $message);
            redirect('/server_details.php?id=' . $serverId . '&tab=services');
        }

        try {
            $install = omnilogs_install_agent(
                $companyId,
                $projectId,
                $userId,
                $serverId,
                trim((string) ($server['external_id'] ?? '')),
                $omniLogsInput['host'],
                (int) $omniLogsInput['port'],
                $omniLogsInput['username'],
                $omniLogsInput['password'],
                $tenantLokiPushUrl,
                $tenantLokiUsername,
                $tenantLokiPassword
            );

            if ($install['ok']) {
                flash_set('success', $install['message']);
            } else {
                flash_set('danger', $install['message'] . (trim((string) $install['output']) !== '' ? ' Detalhes: ' . $install['output'] : ''));
            }
        } catch (Throwable $exception) {
            flash_set('danger', 'Falha ao instalar OmniLogs: ' . $exception->getMessage());
        }

        redirect('/server_details.php?id=' . $serverId . '&tab=services');
    }

    if ($action === 'save_snapshot_policy') {
        if (!$canManage) {
            flash_set('danger', 'Seu usuario esta em modo leitura para esta empresa.');
            redirect('/server_details.php?id=' . $serverId . '&tab=snapshots');
        }

        try {
            save_server_snapshot_policy($companyId, $projectId, $serverId, $userId, $_POST);
            flash_set('success', 'Politica de snapshot salva com sucesso.');
        } catch (Throwable $exception) {
            flash_set('danger', 'Falha ao salvar politica: ' . $exception->getMessage());
        }

        redirect('/server_details.php?id=' . $serverId . '&tab=snapshots');
    }

    if ($action === 'create_snapshot_now') {
        if (!$canManage) {
            flash_set('danger', 'Seu usuario esta em modo leitura para esta empresa.');
            redirect('/server_details.php?id=' . $serverId . '&tab=snapshots');
        }

        try {
            $run = snapshot_create_now_for_server($companyId, $projectId, $serverId, $userId, 'manual');
            if (($run['ok'] ?? false) === true) {
                flash_set('success', (string) ($run['message'] ?? 'Snapshot solicitado com sucesso.'));
            } else {
                flash_set('danger', (string) ($run['message'] ?? 'Falha ao solicitar snapshot.'));
            }
        } catch (Throwable $exception) {
            flash_set('danger', 'Falha ao solicitar snapshot: ' . $exception->getMessage());
        }

        redirect('/server_details.php?id=' . $serverId . '&tab=snapshots');
    }

    if ($action === 'analyze_logs_ai') {
        $tab = 'logs';
        $postedRange = (string) ($_POST['range'] ?? $logsRange);
        $postedQ = trim((string) ($_POST['q'] ?? $logsQ));
        $logsRange = $postedRange;
        $logsQ = $postedQ;

        if (!$tenantLokiReady) {
            $aiAnalysisError = 'Observabilidade do tenant nao esta ativa para analise de logs.';
        } elseif ($serverExternalId === '') {
            $aiAnalysisError = 'Servidor sem external_id para correlacao de logs.';
        } else {
            $analysisLogs = fetch_server_loki_logs(
                $tenantLokiPushUrl,
                $tenantLokiUsername,
                $tenantLokiPassword,
                $tenantScopeOrgId,
                $companyId,
                $projectId,
                $serverExternalId,
                $logsRange,
                $logsQ,
                600
            );
            $logsData = $analysisLogs;
            if (($analysisLogs['error'] ?? null) !== null) {
                $aiAnalysisError = (string) $analysisLogs['error'];
            } else {
                $rowsForAi = is_array($analysisLogs['rows'] ?? null) ? $analysisLogs['rows'] : [];
                $aiResult = analyze_server_logs_with_ai(
                    $userId,
                    $companyId,
                    $server,
                    $rowsForAi,
                    $logsRange,
                    $logsQ
                );
                if ($aiResult['ok']) {
                    $aiAnalysis = (string) ($aiResult['content'] ?? '');
                    $aiAnalysisMeta = is_array($aiResult['meta'] ?? null) ? $aiResult['meta'] : [];
                    if (trim($aiAnalysis) !== '') {
                        $aiDiagnosticTitlePrefill = suggest_ai_diagnostic_title($aiAnalysis, $server, $logsRange, $logsQ);
                    }
                } else {
                    $aiAnalysisError = (string) ($aiResult['error'] ?? 'Falha na analise IA.');
                }
            }
        }
    }

    if ($action === 'save_ai_diagnostic') {
        $saveRange = (string) ($_POST['range'] ?? $logsRange);
        $saveQ = trim((string) ($_POST['q'] ?? $logsQ));
        $saveContent = trim((string) ($_POST['analysis_content'] ?? ''));
        $saveTitle = trim((string) ($_POST['diagnostic_title'] ?? 'Diagnostico IA'));

        if ($saveContent === '') {
            flash_set('warning', 'Nada para salvar. Execute a analise IA antes de salvar o diagnostico.');
            redirect('/server_details.php?id=' . $serverId . '&tab=logs&range=' . urlencode($saveRange) . '&q=' . urlencode($saveQ));
        }

        $snapshotRows = [];
        if ($tenantLokiReady && $serverExternalId !== '') {
            $saveLogs = fetch_server_loki_logs(
                $tenantLokiPushUrl,
                $tenantLokiUsername,
                $tenantLokiPassword,
                $tenantScopeOrgId,
                $companyId,
                $projectId,
                $serverExternalId,
                $saveRange,
                $saveQ,
                120
            );
            if (($saveLogs['error'] ?? null) === null && is_array($saveLogs['rows'] ?? null)) {
                $snapshotRows = array_slice($saveLogs['rows'], 0, 120);
            }
        }

        $meta = [
            'provider' => (string) ($_POST['analysis_provider'] ?? ''),
            'model' => (string) ($_POST['analysis_model'] ?? ''),
            'source' => (string) ($_POST['analysis_source'] ?? ''),
            'trace_id' => (string) ($_POST['analysis_trace_id'] ?? ''),
        ];

        try {
            save_server_ai_diagnostic(
                $companyId,
                $projectId,
                $serverId,
                $userId,
                $saveTitle,
                $saveRange,
                $saveQ,
                $saveContent,
                $meta,
                $snapshotRows
            );
            flash_set('success', 'Diagnostico IA salvo com sucesso.');
        } catch (Throwable $exception) {
            flash_set('danger', 'Falha ao salvar diagnostico IA: ' . $exception->getMessage());
        }

        redirect('/server_details.php?id=' . $serverId . '&tab=logs&range=' . urlencode($saveRange) . '&q=' . urlencode($saveQ));
    }

    if ($action === 'ai_chat_mark_resolved') {
        $messageId = (int) ($_POST['message_id'] ?? 0);
        $resolved = strtolower(trim((string) ($_POST['resolved'] ?? '1'))) !== '0';
        if ($messageId <= 0) {
            if ($isAjaxRequest) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Mensagem invalida.'], JSON_UNESCAPED_SLASHES);
                exit;
            }
            flash_set('warning', 'Mensagem invalida para marcar resolucao.');
            redirect('/server_details.php?id=' . $serverId . '&tab=ai_chat&chat_range=' . urlencode($chatRange) . '&chat_q=' . urlencode($chatQ));
        }
        $updated = mark_server_ai_chat_message_resolved($companyId, $projectId, $serverId, $messageId, $userId, $resolved);
        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $updated, 'resolved' => $resolved], JSON_UNESCAPED_SLASHES);
            exit;
        }
        flash_set($updated ? 'success' : 'warning', $updated ? 'Mensagem atualizada.' : 'Mensagem nao encontrada no escopo.');
        redirect('/server_details.php?id=' . $serverId . '&tab=ai_chat&chat_range=' . urlencode($chatRange) . '&chat_q=' . urlencode($chatQ));
    }

    if ($action === 'ai_chat_stream') {
        $postedChatRange = (string) ($_POST['chat_range'] ?? $chatRange);
        $postedChatQ = trim((string) ($_POST['chat_q'] ?? $chatQ));
        $chatMessage = trim((string) ($_POST['chat_message'] ?? ''));

        if (!in_array($postedChatRange, ['15m', '1h', '6h', '24h', '7d'], true)) {
            $postedChatRange = '15m';
        }

        if (!$isSseRequest && !$isAjaxRequest) {
            flash_set('warning', 'Fluxo de chat invalido.');
            redirect('/server_details.php?id=' . $serverId . '&tab=ai_chat&chat_range=' . urlencode($postedChatRange) . '&chat_q=' . urlencode($postedChatQ));
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'off');
        @ini_set('implicit_flush', '1');
        ignore_user_abort(true);
        set_time_limit(0);
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(1);

        if ($chatMessage === '') {
            sse_emit_event('error', ['error' => 'Digite uma pergunta para a IA.']);
            sse_emit_event('done', ['ok' => false]);
            exit;
        }
        if (!$tenantLokiReady) {
            sse_emit_event('error', ['error' => 'Observabilidade do tenant nao esta ativa para chat IA.']);
            sse_emit_event('done', ['ok' => false]);
            exit;
        }

        sse_emit_event('meta', [
            'started_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            'range' => $postedChatRange,
            'q' => $postedChatQ,
        ]);

        $assistantContent = '';
        $assistantMeta = [];
        $streamedAny = false;
        try {
            save_server_ai_chat_message(
                $companyId,
                $projectId,
                $serverId,
                $userId,
                'user',
                $chatMessage,
                ['range' => $postedChatRange, 'q' => $postedChatQ]
            );

            $chatLogs = fetch_server_loki_logs(
                $tenantLokiPushUrl,
                $tenantLokiUsername,
                $tenantLokiPassword,
                $tenantScopeOrgId,
                $companyId,
                $projectId,
                $serverExternalId,
                $postedChatRange,
                $postedChatQ,
                500
            );
            if (($chatLogs['error'] ?? null) !== null) {
                throw new RuntimeException((string) $chatLogs['error']);
            }
            $chatRows = is_array($chatLogs['rows'] ?? null) ? $chatLogs['rows'] : [];
            $history = list_server_ai_chat_messages($companyId, $projectId, $serverId, 24);

            $chatResult = chat_with_server_ai_stream(
                $userId,
                $companyId,
                $server,
                $chatRows,
                $history,
                $postedChatRange,
                $postedChatQ,
                $chatMessage,
                static function (string $delta) use (&$assistantContent, &$streamedAny): void {
                    $assistantContent .= $delta;
                    $streamedAny = true;
                    sse_emit_event('delta', ['delta' => $delta]);
                }
            );

            if (!($chatResult['ok'] ?? false)) {
                $errorText = (string) ($chatResult['error'] ?? 'Falha no chat IA.');
                $assistantContent = $assistantContent !== '' ? $assistantContent : ('Falha ao responder: ' . $errorText);
                $assistantMeta = ['error' => $errorText];
                if (!$streamedAny) {
                    sse_emit_event('delta', ['delta' => $assistantContent]);
                }
                sse_emit_event('error', ['error' => $errorText]);
            } else {
                if (!$streamedAny) {
                    $assistantContent = (string) ($chatResult['content'] ?? '');
                    if ($assistantContent !== '') {
                        sse_emit_event('delta', ['delta' => $assistantContent]);
                    }
                }
                $assistantMeta = is_array($chatResult['meta'] ?? null) ? $chatResult['meta'] : [];
            }

            $assistantId = save_server_ai_chat_message(
                $companyId,
                $projectId,
                $serverId,
                $userId,
                'assistant',
                $assistantContent,
                $assistantMeta
            );

            sse_emit_event('done', [
                'ok' => true,
                'assistant' => [
                    'id' => $assistantId,
                    'content' => $assistantContent,
                    'created_at' => gmdate('Y-m-d H:i:s') . ' UTC',
                ],
                'meta' => $assistantMeta,
            ]);
            exit;
        } catch (Throwable $exception) {
            $errorText = 'Falha no chat IA: ' . $exception->getMessage();
            if (!$streamedAny) {
                sse_emit_event('delta', ['delta' => $errorText]);
            }
            sse_emit_event('error', ['error' => $errorText]);
            sse_emit_event('done', ['ok' => false]);
            exit;
        }
    }

    if ($action === 'ai_chat_send') {
        $postedChatRange = (string) ($_POST['chat_range'] ?? $chatRange);
        $postedChatQ = trim((string) ($_POST['chat_q'] ?? $chatQ));
        $chatMessage = trim((string) ($_POST['chat_message'] ?? ''));

        if (!in_array($postedChatRange, ['15m', '1h', '6h', '24h', '7d'], true)) {
            $postedChatRange = '15m';
        }
        if ($chatMessage === '') {
            if ($isAjaxRequest) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Digite uma pergunta para a IA.'], JSON_UNESCAPED_SLASHES);
                exit;
            }
            flash_set('warning', 'Digite uma pergunta para a IA.');
            redirect('/server_details.php?id=' . $serverId . '&tab=ai_chat&chat_range=' . urlencode($postedChatRange) . '&chat_q=' . urlencode($postedChatQ));
        }

        if (!$tenantLokiReady) {
            if ($isAjaxRequest) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Observabilidade do tenant nao esta ativa para chat IA.'], JSON_UNESCAPED_SLASHES);
                exit;
            }
            flash_set('danger', 'Observabilidade do tenant nao esta ativa para chat IA.');
            redirect('/server_details.php?id=' . $serverId . '&tab=ai_chat&chat_range=' . urlencode($postedChatRange) . '&chat_q=' . urlencode($postedChatQ));
        }

        try {
            save_server_ai_chat_message(
                $companyId,
                $projectId,
                $serverId,
                $userId,
                'user',
                $chatMessage,
                ['range' => $postedChatRange, 'q' => $postedChatQ]
            );

            $chatLogs = fetch_server_loki_logs(
                $tenantLokiPushUrl,
                $tenantLokiUsername,
                $tenantLokiPassword,
                $tenantScopeOrgId,
                $companyId,
                $projectId,
                $serverExternalId,
                $postedChatRange,
                $postedChatQ,
                500
            );
            if (($chatLogs['error'] ?? null) !== null) {
                throw new RuntimeException((string) $chatLogs['error']);
            }
            $chatRows = is_array($chatLogs['rows'] ?? null) ? $chatLogs['rows'] : [];
            $history = list_server_ai_chat_messages($companyId, $projectId, $serverId, 24);
            $chatResult = chat_with_server_ai(
                $userId,
                $companyId,
                $server,
                $chatRows,
                $history,
                $postedChatRange,
                $postedChatQ,
                $chatMessage
            );
            $assistantContent = '';
            $assistantMeta = [];
            if (!($chatResult['ok'] ?? false)) {
                $errorText = (string) ($chatResult['error'] ?? 'Falha no chat IA.');
                $assistantContent = 'Falha ao responder: ' . $errorText;
                $assistantMeta = ['error' => $errorText];
                save_server_ai_chat_message(
                    $companyId,
                    $projectId,
                    $serverId,
                    $userId,
                    'assistant',
                    $assistantContent,
                    $assistantMeta
                );
                if (!$isAjaxRequest) {
                    flash_set('danger', 'Falha no chat IA: ' . $errorText);
                }
            } else {
                $assistantContent = (string) ($chatResult['content'] ?? '');
                $assistantMeta = is_array($chatResult['meta'] ?? null) ? $chatResult['meta'] : [];
                save_server_ai_chat_message(
                    $companyId,
                    $projectId,
                    $serverId,
                    $userId,
                    'assistant',
                    $assistantContent,
                    $assistantMeta
                );
                if (!$isAjaxRequest) {
                    flash_set('success', 'Resposta da IA atualizada no chat.');
                }
            }

            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'assistant' => [
                        'content' => $assistantContent,
                        'created_at' => gmdate('Y-m-d H:i:s') . ' UTC',
                    ],
                    'meta' => $assistantMeta,
                ], JSON_UNESCAPED_SLASHES);
                exit;
            }
        } catch (Throwable $exception) {
            if ($isAjaxRequest) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Falha no chat IA: ' . $exception->getMessage()], JSON_UNESCAPED_SLASHES);
                exit;
            }
            flash_set('danger', 'Falha no chat IA: ' . $exception->getMessage());
        }

        redirect('/server_details.php?id=' . $serverId . '&tab=ai_chat&chat_range=' . urlencode($postedChatRange) . '&chat_q=' . urlencode($postedChatQ));
    }
}

$flash = flash_pull();
$flashType = strtolower(trim((string) ($flash['type'] ?? '')));
$flashMessage = strtolower((string) ($flash['message'] ?? ''));
$omniLogsFlash = str_contains($flashMessage, 'omnilogs');
$omniLogsInstallError = $tab === 'services' && $omniLogsFlash && $flashType === 'danger';
$omniLogsInstallSuccess = $tab === 'services' && $omniLogsFlash && $flashType === 'success';

$raw = [];
if (is_string($server['raw_json'] ?? null)) {
    $decoded = json_decode((string) $server['raw_json'], true);
    if (is_array($decoded)) {
        $raw = $decoded;
    }
}

$labels = [];
if (is_string($server['labels_json'] ?? null)) {
    $decodedLabels = json_decode((string) $server['labels_json'], true);
    if (is_array($decodedLabels)) {
        $labels = $decodedLabels;
    }
}
$metrics = hetzner_server_metrics($server);
$snapshotAssets = list_server_snapshot_assets(
    $companyId,
    $projectId,
    (int) ($server['provider_account_id'] ?? 0),
    $serverExternalId
);
$snapshotPolicy = get_server_snapshot_policy($companyId, $projectId, $serverId);
$snapshotRuns = list_server_snapshot_runs($companyId, $projectId, $serverId, 20);
$savedDiagnostics = list_server_ai_diagnostics($companyId, $projectId, $serverId, 20);
$chatMessages = list_server_ai_chat_messages($companyId, $projectId, $serverId, 80);
$chatRowsPreview = is_array($chatLogsData['rows'] ?? null) ? $chatLogsData['rows'] : [];
$chatContextRows = [];
$chatContextIndex = 1;
foreach (array_slice($chatRowsPreview, 0, 120) as $row) {
    $line = trim((string) ($row['line'] ?? ''));
    if ($line === '') {
        continue;
    }
    $chatContextRows[] = [
        'idx' => $chatContextIndex,
        'ts' => (string) ($row['ts'] ?? ''),
        'labels' => (string) ($row['labels'] ?? ''),
        'line' => $line,
    ];
    $chatContextIndex++;
}
$snapshotIntervalHours = '';
$snapshotIntervalMinutesRaw = $snapshotPolicy['interval_minutes'] ?? null;
if (is_numeric($snapshotIntervalMinutesRaw)) {
    $snapshotIntervalMinutes = (int) $snapshotIntervalMinutesRaw;
    if ($snapshotIntervalMinutes > 0) {
        $snapshotIntervalHoursFloat = $snapshotIntervalMinutes / 60;
        if (abs($snapshotIntervalHoursFloat - round($snapshotIntervalHoursFloat)) < 0.0001) {
            $snapshotIntervalHours = (string) (int) round($snapshotIntervalHoursFloat);
        } else {
            $snapshotIntervalHours = rtrim(rtrim(number_format($snapshotIntervalHoursFloat, 2, '.', ''), '0'), '.');
        }
    }
}
$snapshotScheduleMode = (string) ($snapshotPolicy['schedule_mode'] ?? 'manual');
$snapshotPolicyEnabled = (bool) ($snapshotPolicy['enabled'] ?? false);
$snapshotLastStatusRaw = trim((string) ($snapshotPolicy['last_status'] ?? ''));
$snapshotLastRunRaw = trim((string) ($snapshotPolicy['last_run_at'] ?? ''));
$snapshotNextRunRaw = trim((string) ($snapshotPolicy['next_run_at'] ?? ''));

$snapshotStatusLabel = 'Aguardando primeira execucao';
if ($snapshotLastStatusRaw !== '' && $snapshotLastStatusRaw !== 'n/a') {
    $snapshotStatusLabel = $snapshotLastStatusRaw;
} elseif (!$snapshotPolicyEnabled) {
    $snapshotStatusLabel = 'Politica desativada';
} elseif ($snapshotScheduleMode === 'manual') {
    $snapshotStatusLabel = 'Manual (sem agendamento)';
}

$snapshotLastRunLabel = ($snapshotLastRunRaw !== '' && $snapshotLastRunRaw !== 'n/a')
    ? $snapshotLastRunRaw
    : 'Ainda nao executado';
$snapshotAutoScheduleEnabled = $snapshotPolicyEnabled && $snapshotScheduleMode === 'interval';
$snapshotNextRunLabel = 'Nao se aplica';
if ($snapshotAutoScheduleEnabled) {
    $snapshotNextRunLabel = ($snapshotNextRunRaw !== '' && $snapshotNextRunRaw !== 'n/a')
        ? $snapshotNextRunRaw
        : 'Aguardando scheduler';
}

$snapshotIntervalLabel = 'n/a';
if ($snapshotScheduleMode === 'manual') {
    $snapshotIntervalLabel = 'Manual (sem intervalo)';
} elseif ($snapshotIntervalHours !== '') {
    $snapshotIntervalLabel = $snapshotIntervalHours . 'h';
} elseif ($snapshotPolicyEnabled) {
    $snapshotIntervalLabel = 'Pendente de configuracao';
}

$relatedAssets = list_server_related_assets(
    $companyId,
    $projectId,
    (int) ($server['provider_account_id'] ?? 0),
    $serverExternalId,
    $server
);
$relatedCounts = is_array($relatedAssets['counts'] ?? null) ? $relatedAssets['counts'] : [];
$relatedRows = is_array($relatedAssets['rows'] ?? null) ? $relatedAssets['rows'] : [];
$costEstimate = hetzner_server_cost_estimate($server);
$fxRates = fetch_brl_exchange_rates();

ui_page_start('OmniNOC | Servidor');
ui_navigation('servers', $user, $context, $flash);
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h3 class="mb-1">Servidor: <?= htmlspecialchars((string) $server['name'], ENT_QUOTES, 'UTF-8') ?></h3>
    <small class="text-body-secondary">Detalhe operacional isolado por fornecedor.</small>
  </div>
  <div class="d-flex gap-2">
    <a href="/server_details.php?id=<?= $serverId ?>&tab=snapshots" class="btn btn-primary">Snapshot agora</a>
    <a href="/server_details.php?id=<?= $serverId ?>&tab=logs" class="btn btn-outline-secondary">Abrir logs</a>
    <div class="dropdown">
      <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">More</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li>
          <?php if ($canManage): ?>
            <form method="post" action="/server_details.php?id=<?= $serverId ?>&tab=overview" onsubmit="return confirm('Remover este servidor apenas da plataforma? Esta acao NAO deleta o servidor na Hetzner.');">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="delete_server_from_platform">
              <button type="submit" class="dropdown-item text-danger">Remover da plataforma</button>
            </form>
          <?php else: ?>
            <button class="dropdown-item text-danger" type="button" disabled>Remover da plataforma (somente leitura)</button>
          <?php endif; ?>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" type="button" disabled>Reiniciar servico (em breve)</button></li>
        <li><button class="dropdown-item" type="button" disabled>Modo manutencao (em breve)</button></li>
      </ul>
    </div>
  </div>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'overview' ? 'active' : '' ?>" href="/server_details.php?id=<?= $serverId ?>&tab=overview">Overview</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'services' ? 'active' : '' ?>" href="/server_details.php?id=<?= $serverId ?>&tab=services">Services</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'logs' ? 'active' : '' ?>" href="/server_details.php?id=<?= $serverId ?>&tab=logs">Logs</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'ai_chat' ? 'active' : '' ?>" href="/server_details.php?id=<?= $serverId ?>&tab=ai_chat">IA Chat</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'costs' ? 'active' : '' ?>" href="/server_details.php?id=<?= $serverId ?>&tab=costs">Costs</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'snapshots' ? 'active' : '' ?>" href="/server_details.php?id=<?= $serverId ?>&tab=snapshots">Snapshots</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'config' ? 'active' : '' ?>" href="/server_details.php?id=<?= $serverId ?>&tab=config">Config</a></li>
</ul>

<?php if ($tab === 'overview'): ?>
  <div class="row">
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">CPU</small><h4 class="mb-0"><?= is_int($metrics['cpu_cores']) ? number_format($metrics['cpu_cores'], 0, ',', '.') . ' cores' : 'N/D' ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">RAM</small><h4 class="mb-0"><?= is_float($metrics['memory_gb']) ? number_format($metrics['memory_gb'], 1, ',', '.') . ' GB' : 'N/D' ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Disco</small><h4 class="mb-0"><?= is_float($metrics['disk_gb']) ? number_format($metrics['disk_gb'], 0, ',', '.') . ' GB' : 'N/D' ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Rede</small><h4 class="mb-0"><?= htmlspecialchars((string) ($server['ipv4'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></h4></div></div>
    </div>
  </div>

  <div class="row">
    <div class="col-lg-7 mb-3">
      <div class="card">
        <div class="card-header"><strong>Resumo detectado</strong></div>
        <div class="card-body">
          <dl class="mb-0">
            <dt>Status</dt>
            <dd><?= htmlspecialchars((string) ($server['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>Tipo</dt>
            <dd><?= htmlspecialchars((string) ($metrics['server_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>Sistema operacional</dt>
            <dd><?= htmlspecialchars((string) ($metrics['os_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>Regiao</dt>
            <dd><?= htmlspecialchars((string) ($server['datacenter'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>IPv4</dt>
            <dd><?= htmlspecialchars((string) ($server['ipv4'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>IPv6</dt>
            <dd><?= htmlspecialchars((string) ($metrics['ipv6'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>Conta Hetzner</dt>
            <dd><?= htmlspecialchars((string) ($server['account_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
          </dl>
        </div>
      </div>
    </div>

    <div class="col-lg-5 mb-3">
      <div class="card">
        <div class="card-header"><strong>Alertas ativos</strong></div>
        <div class="card-body">
          <p class="text-body-secondary mb-0">Sem pipeline de alertas configurado neste ambiente local.</p>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'services'): ?>
  <div class="card mb-3">
    <div class="card-header"><strong>OmniLogs Agent</strong></div>
    <div class="card-body">
      <p class="mb-3">Instalacao remota do agente de logs (Promtail em container Docker) neste servidor.</p>
      <div class="alert <?= $tenantLokiReady ? 'alert-success' : 'alert-warning' ?> mb-3">
        <?php if ($tenantLokiReady): ?>
          Coletor central do tenant ativo.
        <?php else: ?>
          <?php if ($isPlatformOwner): ?>
            Observabilidade central do tenant nao configurada. Acesse <a href="/observability.php">Observability</a> para definir Loki/VictoriaMetrics.
          <?php else: ?>
            Observabilidade central do tenant nao configurada. Acione o administrador global.
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <div id="omnilogs-install-progress" class="alert alert-info d-none mb-3">
        Instalacao do OmniLogs em andamento. Isso pode levar alguns segundos.
      </div>
      <form id="omnilogs-install-form" method="post" action="/server_details.php?id=<?= $serverId ?>&tab=services">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="install_omnilogs_agent">
        <div class="row g-2">
          <div class="col-lg-3">
            <label class="form-label mb-1">Host/IP</label>
            <input type="text" class="form-control" name="host" value="<?= htmlspecialchars((string) ($omniLogsForm['host'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
          <div class="col-lg-2">
            <label class="form-label mb-1">Porta SSH</label>
            <input type="number" min="1" max="65535" class="form-control" name="port" value="<?= htmlspecialchars((string) ($omniLogsForm['port'] ?? '22'), ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
          <div class="col-lg-2">
            <label class="form-label mb-1">Usuario</label>
            <input type="text" class="form-control" name="username" value="<?= htmlspecialchars((string) ($omniLogsForm['username'] ?? 'root'), ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-lg-5">
            <label class="form-label mb-1">Senha SSH</label>
            <input type="password" class="form-control" name="password" autocomplete="new-password" required>
            <small class="text-body-secondary">A senha e usada apenas durante a instalacao e nao fica persistida.</small>
          </div>
          <div class="col-lg-7 d-flex align-items-end justify-content-end gap-2">
            <?php if ($canManage && $tenantLokiReady): ?>
              <button id="omnilogs-install-btn" type="submit" class="btn btn-primary">Instalar OmniLogs</button>
            <?php else: ?>
              <button type="button" class="btn btn-primary" disabled>Instalar OmniLogs</button>
            <?php endif; ?>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div id="omnilogs-lower-guard"
       data-server-id="<?= (int) $serverId ?>"
       data-flash-error="<?= $omniLogsInstallError ? '1' : '0' ?>"
       data-flash-success="<?= $omniLogsInstallSuccess ? '1' : '0' ?>"
       style="position: relative;">
    <div id="omnilogs-lower-overlay" class="alert alert-warning d-none" style="position: absolute; inset: 0; z-index: 20; display: flex; align-items: center; justify-content: center; text-align: center;">
      <div>
        <div class="mb-2"><strong>Area bloqueada para evitar interacoes durante a instalacao do OmniLogs.</strong></div>
        <button id="omnilogs-unlock-btn" type="button" class="btn btn-sm btn-outline-dark d-none">Desbloquear</button>
      </div>
    </div>

  <div id="omnilogs-lower-content">
  <div class="row mb-3">
    <?php foreach ($relatedCounts as $type => $count): ?>
      <div class="col-xl-2 col-lg-3 col-sm-4 col-6 mb-3">
        <div class="card">
          <div class="card-body py-2">
            <small class="text-body-secondary d-block"><?= htmlspecialchars((string) $type, ENT_QUOTES, 'UTF-8') ?></small>
            <h5 class="mb-0"><?= number_format((int) $count, 0, ',', '.') ?></h5>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Services</strong>
        <a href="/hetzner_account_details.php?id=<?= (int) ($server['provider_account_id'] ?? 0) ?>&tab=assets" class="btn btn-sm btn-outline-secondary">Inventario completo</a>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Tipo</th>
              <th>ID</th>
              <th>Nome</th>
              <th>Status</th>
              <th>Regiao</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($relatedRows === []): ?>
              <tr>
                <td colspan="5" class="text-center text-body-secondary py-4">
                  Nenhum recurso vinculado encontrado para este servidor no inventario atual.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($relatedRows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars((string) ($row['asset_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><code><?= htmlspecialchars((string) ($row['external_id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></code></td>
                  <td><?= htmlspecialchars((string) ($row['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) ($row['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) ($row['datacenter'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'logs'): ?>
  <div class="card">
    <div class="card-header"><strong>Logs</strong></div>
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <p class="mb-0">Logs isolados por servidor (source: OmniLogs/Loki).</p>
        <span class="badge text-bg-secondary">
          Retencao: <?= htmlspecialchars((string) $logsRetentionHours, ENT_QUOTES, 'UTF-8') ?>h (<?= htmlspecialchars((string) $logsRetentionDays, ENT_QUOTES, 'UTF-8') ?> dias)
        </span>
      </div>

      <form method="get" action="/server_details.php" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="id" value="<?= $serverId ?>">
        <input type="hidden" name="tab" value="logs">
        <div class="col-12 col-lg-2 col-md-3">
          <label class="form-label mb-1">Janela</label>
          <select name="range" class="form-select">
            <?php foreach (['15m' => '15 min', '1h' => '1 hora', '6h' => '6 horas', '24h' => '24 horas', '7d' => '7 dias'] as $v => $label): ?>
              <option value="<?= $v ?>" <?= $logsRange === $v ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-lg-8 col-md-6">
          <label class="form-label mb-1">Filtro</label>
          <input type="text" name="q" class="form-control" placeholder="Filtrar texto (ex: error, nginx, auth)" value="<?= htmlspecialchars($logsQ, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-12 col-lg-2 col-md-3 d-grid">
          <button type="submit" class="btn btn-primary">Atualizar logs</button>
        </div>
      </form>

      <div class="row g-2 mb-3">
        <div class="col-12 col-lg-8">
          <div class="d-flex flex-wrap gap-2">
            <form id="ai-devops-form" method="post" action="/server_details.php?id=<?= $serverId ?>&tab=logs" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="analyze_logs_ai">
              <input type="hidden" name="range" value="<?= htmlspecialchars($logsRange, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="q" value="<?= htmlspecialchars($logsQ, ENT_QUOTES, 'UTF-8') ?>">
              <button id="ai-devops-submit" type="submit" class="btn btn-warning">IA DevOps: analisar problemas</button>
            </form>
            <a href="/server_details.php?id=<?= $serverId ?>&tab=ai_chat&chat_range=<?= urlencode($logsRange) ?>&chat_q=<?= urlencode($logsQ) ?>" class="btn btn-outline-info">Abrir IA Chat</a>
          </div>
        </div>
        <div class="col-12 col-lg-4">
          <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
            <button id="copy-logs-btn" type="button" class="btn btn-outline-secondary">Copiar logs</button>
            <a class="btn btn-outline-secondary" href="/server_details.php?id=<?= $serverId ?>&tab=logs&range=<?= urlencode($logsRange) ?>&q=<?= urlencode($logsQ) ?>&export=log">Download .log</a>
            <a class="btn btn-outline-secondary" href="/server_details.php?id=<?= $serverId ?>&tab=logs&range=<?= urlencode($logsRange) ?>&q=<?= urlencode($logsQ) ?>&export=json">Download .json</a>
          </div>
        </div>
      </div>
      <div id="ai-devops-wait" class="alert alert-warning py-2 mb-3 d-none" role="status" aria-live="polite">
        <strong id="ai-devops-wait-text">A forca esta analisando seu servidor...</strong>
      </div>
      <script>
        (function () {
          var form = document.getElementById('ai-devops-form');
          var button = document.getElementById('ai-devops-submit');
          var waitBox = document.getElementById('ai-devops-wait');
          var waitText = document.getElementById('ai-devops-wait-text');
          if (!form || !button || !waitBox || !waitText) {
            return;
          }

          var phrases = [
            'A forca esta analisando seu servidor...',
            'Jarvis esta correlacionando os logs por timestamp...',
            'HAL 9000 esta checando CPU, RAM e disco...',
            'Neo entrou na Matrix dos containers...',
            'Engatando hyperdrive para rastrear a causa raiz...',
            'Scanners taticos ativados para sinais de ataque...',
            'Detectando anomalias no Swarm e no proxy reverso...',
            'Montando plano de mitigacao digno de capitao de frota...'
          ];
          var phraseIndex = 0;
          var timer = null;

          form.addEventListener('submit', function () {
            button.disabled = true;
            button.textContent = 'Analisando...';
            waitBox.classList.remove('d-none');
            waitText.textContent = phrases[phraseIndex];
            timer = window.setInterval(function () {
              phraseIndex = (phraseIndex + 1) % phrases.length;
              waitText.textContent = phrases[phraseIndex];
            }, 1800);
          });

          window.addEventListener('pagehide', function () {
            if (timer !== null) {
              window.clearInterval(timer);
            }
          });
        })();
      </script>

      <?php if (is_string($aiAnalysisError) && $aiAnalysisError !== ''): ?>
        <div class="alert alert-danger mb-3">Falha na analise IA: <?= htmlspecialchars($aiAnalysisError, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if (is_string($aiAnalysis) && trim($aiAnalysis) !== ''): ?>
        <div class="card border-warning mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Analise IA DevOps</strong>
            <?php if ($aiAnalysisMeta !== []): ?>
              <small class="text-body-secondary">
                <?= htmlspecialchars((string) ($aiAnalysisMeta['provider'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                /
                <?= htmlspecialchars((string) ($aiAnalysisMeta['model'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
              </small>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <pre class="mb-3" style="white-space: pre-wrap; max-height: 380px; overflow: auto;"><?= htmlspecialchars($aiAnalysis, ENT_QUOTES, 'UTF-8') ?></pre>
            <form method="post" action="/server_details.php?id=<?= $serverId ?>&tab=logs&range=<?= urlencode($logsRange) ?>&q=<?= urlencode($logsQ) ?>" class="row g-2">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="save_ai_diagnostic">
              <input type="hidden" name="range" value="<?= htmlspecialchars($logsRange, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="q" value="<?= htmlspecialchars($logsQ, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="analysis_content" value="<?= htmlspecialchars($aiAnalysis, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="analysis_provider" value="<?= htmlspecialchars((string) ($aiAnalysisMeta['provider'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="analysis_model" value="<?= htmlspecialchars((string) ($aiAnalysisMeta['model'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="analysis_source" value="<?= htmlspecialchars((string) ($aiAnalysisMeta['source'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="analysis_trace_id" value="<?= htmlspecialchars((string) ($aiAnalysisMeta['trace_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
              <div class="col-lg-9">
                <input type="text" name="diagnostic_title" class="form-control form-control-sm" placeholder="Titulo do diagnostico (ex: SSH brute force - mitigacao 24/02)" value="<?= htmlspecialchars($aiDiagnosticTitlePrefill, ENT_QUOTES, 'UTF-8') ?>">
              </div>
              <div class="col-lg-3 d-grid">
                <button type="submit" class="btn btn-success btn-sm">Salvar diagnostico</button>
              </div>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Diagnosticos salvos</strong>
          <a href="/server_details.php?id=<?= $serverId ?>&tab=ai_chat" class="btn btn-sm btn-outline-secondary">Abrir IA Chat</a>
        </div>
        <div class="card-body p-0">
          <table class="table table-sm table-hover mb-0">
            <thead>
              <tr>
                <th>Quando</th>
                <th>Titulo</th>
                <th>Range</th>
                <th>Modelo</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($savedDiagnostics === []): ?>
                <tr><td colspan="4" class="text-center text-body-secondary py-3">Nenhum diagnostico salvo para este servidor.</td></tr>
              <?php else: ?>
                <?php foreach (array_slice($savedDiagnostics, 0, 10) as $diag): ?>
                  <?php
                    $diagMeta = [];
                    if (is_string($diag['analysis_meta_json'] ?? null) && trim((string) $diag['analysis_meta_json']) !== '') {
                        $decodedDiagMeta = json_decode((string) $diag['analysis_meta_json'], true);
                        if (is_array($decodedDiagMeta)) {
                            $diagMeta = $decodedDiagMeta;
                        }
                    }
                  ?>
                  <tr>
                    <td><?= htmlspecialchars((string) ($diag['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($diag['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($diag['range_window'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) (($diagMeta['model'] ?? '-') ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if (!$tenantLokiReady): ?>
        <div class="alert alert-warning mb-0">Observabilidade do tenant ainda nao esta ativa para leitura de logs.</div>
      <?php elseif (($logsData['error'] ?? null) !== null): ?>
        <div class="alert alert-danger mb-0"><?= htmlspecialchars((string) $logsData['error'], ENT_QUOTES, 'UTF-8') ?></div>
      <?php else: ?>
        <?php $rows = is_array($logsData['rows'] ?? null) ? $logsData['rows'] : []; ?>
        <?php if ($rows === []): ?>
          <div class="alert alert-info mb-0">Sem logs para este servidor no periodo selecionado.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table id="server-logs-table" class="table table-sm table-hover align-middle">
              <thead>
                <tr>
                  <th style="min-width: 170px;">Timestamp</th>
                  <th style="min-width: 220px;">Labels</th>
                  <th>Linha</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td><code><?= htmlspecialchars((string) ($row['ts'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><small class="text-body-secondary"><?= htmlspecialchars((string) ($row['labels'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></td>
                    <td><pre class="mb-0" style="white-space: pre-wrap;"><?= htmlspecialchars((string) ($row['line'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <script>
            (function () {
              var copyBtn = document.getElementById('copy-logs-btn');
              var table = document.getElementById('server-logs-table');
              if (!copyBtn || !table) {
                return;
              }

              var collectLogsText = function () {
                var lines = [];
                var rows = table.querySelectorAll('tbody tr');
                rows.forEach(function (row) {
                  var tds = row.querySelectorAll('td');
                  if (tds.length < 3) {
                    return;
                  }
                  var ts = (tds[0].innerText || '').trim();
                  var labels = (tds[1].innerText || '').trim();
                  var line = (tds[2].innerText || '').trim();
                  var prefix = '[' + ts + ']';
                  if (labels !== '') {
                    prefix += ' {' + labels + '}';
                  }
                  lines.push(prefix + ' ' + line);
                });
                return lines.join('\n');
              };

              copyBtn.addEventListener('click', async function () {
                var payload = collectLogsText();
                if (!payload) {
                  copyBtn.textContent = 'Sem logs';
                  window.setTimeout(function () { copyBtn.textContent = 'Copiar logs'; }, 1200);
                  return;
                }
                try {
                  await navigator.clipboard.writeText(payload);
                  copyBtn.textContent = 'Logs copiados';
                  copyBtn.classList.remove('btn-outline-secondary');
                  copyBtn.classList.add('btn-success');
                } catch (error) {
                  copyBtn.textContent = 'Falha ao copiar';
                  copyBtn.classList.remove('btn-outline-secondary');
                  copyBtn.classList.add('btn-danger');
                }
                window.setTimeout(function () {
                  copyBtn.textContent = 'Copiar logs';
                  copyBtn.classList.remove('btn-success', 'btn-danger');
                  copyBtn.classList.add('btn-outline-secondary');
                }, 1400);
              });
            })();
          </script>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'ai_chat'): ?>
  <?php $chatRowsPreview = is_array($chatLogsData['rows'] ?? null) ? $chatLogsData['rows'] : []; ?>
  <style>
    #ia-chat-shell { height: 74vh; min-height: 600px; display: flex; flex-direction: column; position: relative; }
    #ia-chat-feed { flex: 1; overflow: auto; padding: 18px; background: radial-gradient(100% 140% at 0% 0%, rgba(13,110,253,0.12), transparent 55%), linear-gradient(180deg, rgba(255,255,255,0.01), rgba(255,255,255,0)); }
    .ia-msg { max-width: 82%; border: 1px solid rgba(255,255,255,0.12); border-radius: 14px; padding: 10px 12px; margin-bottom: 10px; box-shadow: 0 10px 24px rgba(0,0,0,0.08); }
    .ia-msg-user { margin-left: auto; border-color: rgba(13,110,253,0.5); background: rgba(13,110,253,0.14); }
    .ia-msg-assistant { margin-right: auto; border-color: rgba(255,255,255,0.24); background: rgba(255,255,255,0.04); }
    .ia-msg-resolved { border-color: rgba(25,135,84,0.6); box-shadow: 0 0 0 1px rgba(25,135,84,0.22) inset; }
    .ia-meta { font-size: .78rem; opacity: .78; margin-bottom: 6px; display: flex; justify-content: space-between; gap: 8px; }
    .ia-text { white-space: pre-wrap; margin: 0; font-size: .98rem; line-height: 1.52; }
    .ia-text p { margin: 0 0 .7rem; }
    .ia-text p:last-child { margin-bottom: 0; }
    .ia-text pre { margin: .6rem 0; padding: .65rem; border-radius: 10px; border: 1px solid rgba(255,255,255,0.16); background: rgba(0,0,0,0.25); color: #d4e7ff; }
    .ia-text code { border-radius: 6px; background: rgba(255,255,255,0.08); color: #ffd88a; padding: .08rem .35rem; }
    .ia-log-cite { border: 1px solid rgba(13,202,240,0.55); background: rgba(13,202,240,0.12); color: #9eeaf9; border-radius: 999px; padding: .06rem .5rem; font-size: .78rem; }
    .ia-context-panel[open] { border: 1px solid rgba(255,255,255,0.16); border-radius: 10px; padding: .4rem; background: rgba(8,13,20,0.45); }
    .ia-context-focus { outline: 2px solid rgba(13,202,240,0.75); background: rgba(13,202,240,0.12); }
    #ia-chat-composer { border-top: 1px solid rgba(255,255,255,0.12); padding: 12px; background: rgba(0,0,0,0.18); }
    #ia-chat-message { min-height: 56px; max-height: 220px; resize: none; }
  </style>

  <div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <strong>IA Chat Operacional</strong>
      <div class="d-flex flex-wrap gap-2">
        <span class="badge text-bg-dark"><?= number_format(count($chatContextRows), 0, ',', '.') ?> linhas no contexto</span>
        <span class="badge text-bg-secondary">Somente logs</span>
        <button id="ia-open-context" type="button" class="btn btn-sm btn-outline-info">Contexto</button>
      </div>
    </div>
    <div id="ia-chat-shell">
      <div class="px-3 pt-3 pb-2 border-bottom">
        <form method="get" action="/server_details.php" class="row g-2 align-items-end">
          <input type="hidden" name="id" value="<?= $serverId ?>">
          <input type="hidden" name="tab" value="ai_chat">
          <div class="col-lg-2 col-md-3">
            <label class="form-label mb-1">Janela</label>
            <select name="chat_range" class="form-select form-select-sm">
              <?php foreach (['15m' => '15 min', '1h' => '1 hora', '6h' => '6 horas', '24h' => '24 horas', '7d' => '7 dias'] as $v => $label): ?>
                <option value="<?= $v ?>" <?= $chatRange === $v ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-7 col-md-6">
            <label class="form-label mb-1">Filtro de logs</label>
            <input type="text" name="chat_q" class="form-control form-control-sm" placeholder="Ex: sshd, timeout, nginx, auth" value="<?= htmlspecialchars($chatQ, ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="col-lg-3 col-md-3 d-grid">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Atualizar contexto</button>
          </div>
        </form>
      </div>

      <div id="ia-chat-feed">
        <?php if ($chatMessages === []): ?>
          <div class="text-body-secondary">Ainda nao temos conversa aqui. Escreva sua pergunta para comecar.</div>
        <?php else: ?>
          <?php foreach ($chatMessages as $msg): ?>
            <?php
              $role = strtolower((string) ($msg['role'] ?? 'user'));
              $isAssistant = $role === 'assistant';
              $msgContent = (string) ($msg['content'] ?? '');
              $msgMeta = [];
              if (is_string($msg['meta_json'] ?? null) && trim((string) $msg['meta_json']) !== '') {
                  $decodedMsgMeta = json_decode((string) $msg['meta_json'], true);
                  if (is_array($decodedMsgMeta)) {
                      $msgMeta = $decodedMsgMeta;
                  }
              }
              $msgResolved = (bool) ($msgMeta['resolved'] ?? false);
            ?>
            <div
              class="ia-msg <?= $isAssistant ? 'ia-msg-assistant' : 'ia-msg-user' ?> <?= $msgResolved ? 'ia-msg-resolved' : '' ?>"
              data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"
              data-message-id="<?= (int) ($msg['id'] ?? 0) ?>"
              data-raw="<?= htmlspecialchars($msgContent, ENT_QUOTES, 'UTF-8') ?>"
            >
              <div class="ia-meta">
                <span>
                  <strong><?= $isAssistant ? 'IA' : 'Voce' ?></strong>
                  <?php if ($msgResolved): ?><span class="badge text-bg-success ms-1">Resolvido</span><?php endif; ?>
                </span>
                <span><?= htmlspecialchars((string) ($msg['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
              </div>
              <pre class="ia-text" data-render-markdown="<?= $isAssistant ? '1' : '0' ?>"><?= htmlspecialchars($msgContent, ENT_QUOTES, 'UTF-8') ?></pre>
              <?php if ($isAssistant): ?>
                <div class="ia-actions mt-2 d-flex flex-wrap gap-2">
                  <button type="button" class="btn btn-sm btn-outline-secondary" data-action="copy">Copiar</button>
                  <button type="button" class="btn btn-sm btn-outline-secondary" data-action="regenerate">Regenerar</button>
                  <button
                    type="button"
                    class="btn btn-sm <?= $msgResolved ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                    data-action="resolve"
                    data-resolved="<?= $msgResolved ? '1' : '0' ?>"
                  >
                    <?= $msgResolved ? 'Reabrir' : 'Marcar resolvido' ?>
                  </button>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div id="ia-chat-composer">
        <form id="ai-chat-form" method="post" action="/server_details.php?id=<?= $serverId ?>&tab=ai_chat&chat_range=<?= urlencode($chatRange) ?>&chat_q=<?= urlencode($chatQ) ?>">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="ai_chat_stream">
          <div class="d-flex gap-2">
            <textarea id="ai-chat-message" name="chat_message" class="form-control" placeholder="Pergunte sobre os logs deste servidor..." autocomplete="off"></textarea>
            <button id="ai-chat-submit" type="submit" class="btn btn-primary px-4">Enviar</button>
            <button id="ai-chat-cancel" type="button" class="btn btn-outline-secondary px-3 d-none">Cancelar</button>
          </div>
          <div class="d-flex flex-wrap justify-content-between mt-2">
            <small class="text-body-secondary">Enter envia, Shift+Enter quebra linha.</small>
            <a class="small text-body-secondary" href="/server_details.php?id=<?= $serverId ?>&tab=logs&range=<?= urlencode($chatRange) ?>&q=<?= urlencode($chatQ) ?>">Abrir logs completos</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <details id="ia-context-panel" class="mt-3 ia-context-panel">
    <summary class="text-body-secondary" style="cursor: pointer;">Contexto de logs usado no chat</summary>
    <div class="card mt-2">
      <div class="card-body p-0">
        <?php if (!$tenantLokiReady): ?>
          <div class="alert alert-warning m-3 mb-0">Observabilidade do tenant nao esta ativa para leitura de logs.</div>
        <?php elseif (($chatLogsData['error'] ?? null) !== null): ?>
          <div class="alert alert-danger m-3 mb-0"><?= htmlspecialchars((string) $chatLogsData['error'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($chatContextRows === []): ?>
          <div class="alert alert-info m-3 mb-0">Sem logs no periodo/filtro atual para usar como contexto.</div>
        <?php else: ?>
          <div class="table-responsive" style="max-height: 280px;">
            <table class="table table-sm table-hover mb-0 align-middle">
              <thead>
                <tr>
                  <th style="min-width: 85px;">Ref</th>
                  <th style="min-width: 170px;">Timestamp</th>
                  <th style="min-width: 220px;">Labels</th>
                  <th>Linha</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_slice($chatContextRows, 0, 80) as $row): ?>
                  <tr id="log-ref-<?= (int) ($row['idx'] ?? 0) ?>" data-log-index="<?= (int) ($row['idx'] ?? 0) ?>">
                    <td><code>LOG#<?= (int) ($row['idx'] ?? 0) ?></code></td>
                    <td><code><?= htmlspecialchars((string) ($row['ts'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><small class="text-body-secondary"><?= htmlspecialchars((string) ($row['labels'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></td>
                    <td><pre class="mb-0" style="white-space: pre-wrap;"><?= htmlspecialchars((string) ($row['line'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </details>

  <script>
    (function () {
      var form = document.getElementById('ai-chat-form');
      var textarea = document.getElementById('ai-chat-message');
      var submit = document.getElementById('ai-chat-submit');
      var cancel = document.getElementById('ai-chat-cancel');
      var feed = document.getElementById('ia-chat-feed');
      var contextPanel = document.getElementById('ia-context-panel');
      var openContextBtn = document.getElementById('ia-open-context');
      if (!form || !textarea || !submit || !feed) {
        return;
      }

      var abortController = null;
      var isNearBottom = true;
      var thinkingTimer = null;
      var phrases = [
        'A forca esta analisando seu servidor...',
        'Correlacionando eventos por timestamp...',
        'Buscando root cause na matriz de logs...',
        'Alinhando evidencias de infraestrutura...',
        'Rastreando anomalias no cluster de servicos...'
      ];

      var resize = function () {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 220) + 'px';
      };

      var escapeHtml = function (value) {
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      };

      var renderMarkdown = function (raw) {
        var safe = escapeHtml(raw || '').replace(/\r\n/g, '\n');
        var blocks = [];
        safe = safe.replace(/```([\s\S]*?)```/g, function (_m, code) {
          var key = '__CODE_' + blocks.length + '__';
          blocks.push('<pre><code>' + code + '</code></pre>');
          return key;
        });
        safe = safe.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        safe = safe.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        safe = safe.replace(/\[LOG#(\d+)\]/gi, function (_m, ref) {
          return '<button type="button" class="ia-log-cite" data-log-ref="' + ref + '">LOG#' + ref + '</button>';
        });
        var html = safe.split(/\n{2,}/).map(function (part) {
          return '<p>' + part.replace(/\n/g, '<br>') + '</p>';
        }).join('');
        blocks.forEach(function (block, idx) {
          html = html.replace('__CODE_' + idx + '__', block);
        });
        return html;
      };

      var shouldStickBottom = function () {
        return (feed.scrollHeight - feed.scrollTop - feed.clientHeight) < 120;
      };

      var scrollBottom = function (force) {
        if (force || isNearBottom) {
          feed.scrollTop = feed.scrollHeight;
        }
      };

      var setSendingState = function (sending) {
        submit.disabled = sending;
        submit.textContent = sending ? 'Gerando...' : 'Enviar';
        if (cancel) {
          cancel.classList.toggle('d-none', !sending);
          cancel.disabled = !sending;
        }
      };

      var appendMessage = function (role, content, createdAt, messageId) {
        var wrap = document.createElement('div');
        wrap.className = 'ia-msg ' + (role === 'assistant' ? 'ia-msg-assistant' : 'ia-msg-user');
        wrap.setAttribute('data-role', role);
        wrap.setAttribute('data-raw', content || '');
        if (messageId) {
          wrap.setAttribute('data-message-id', String(messageId));
        }

        var meta = document.createElement('div');
        meta.className = 'ia-meta';
        var left = document.createElement('span');
        left.innerHTML = '<strong>' + (role === 'assistant' ? 'IA' : 'Voce') + '</strong>';
        var right = document.createElement('span');
        right.textContent = createdAt || '';
        meta.appendChild(left);
        meta.appendChild(right);

        var body = document.createElement('pre');
        body.className = 'ia-text';
        body.setAttribute('data-render-markdown', role === 'assistant' ? '1' : '0');
        if (role === 'assistant') {
          body.innerHTML = renderMarkdown(content || '');
        } else {
          body.textContent = content || '';
        }

        wrap.appendChild(meta);
        wrap.appendChild(body);

        if (role === 'assistant') {
          var actions = document.createElement('div');
          actions.className = 'ia-actions mt-2 d-flex flex-wrap gap-2';
          actions.innerHTML = ''
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="copy">Copiar</button>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="regenerate">Regenerar</button>'
            + '<button type="button" class="btn btn-sm btn-outline-success" data-action="resolve" data-resolved="0">Marcar resolvido</button>';
          wrap.appendChild(actions);
        }

        feed.appendChild(wrap);
        scrollBottom(false);
        return wrap;
      };

      var setThinking = function (node) {
        var body = node ? node.querySelector('.ia-text') : null;
        if (!body) {
          return;
        }
        var index = 0;
        body.innerHTML = '<p>' + phrases[index] + '</p>';
        thinkingTimer = window.setInterval(function () {
          index = (index + 1) % phrases.length;
          body.innerHTML = '<p>' + phrases[index] + '</p>';
        }, 1700);
      };

      var stopThinking = function () {
        if (thinkingTimer !== null) {
          window.clearInterval(thinkingTimer);
          thinkingTimer = null;
        }
      };

      var parseEventBlock = function (block) {
        var eventType = 'message';
        var dataLines = [];
        block.split('\n').forEach(function (line) {
          if (line.startsWith('event:')) {
            eventType = line.slice(6).trim() || 'message';
            return;
          }
          if (line.startsWith('data:')) {
            dataLines.push(line.slice(5).trimStart());
          }
        });
        if (dataLines.length === 0) {
          return null;
        }
        var payloadText = dataLines.join('\n');
        var payload = {};
        try { payload = JSON.parse(payloadText); } catch (error) { payload = { raw: payloadText }; }
        return { type: eventType, payload: payload };
      };

      resize();
      textarea.addEventListener('input', resize);
      feed.addEventListener('scroll', function () {
        isNearBottom = shouldStickBottom();
      });
      isNearBottom = true;
      scrollBottom(true);

      feed.querySelectorAll('[data-render-markdown="1"]').forEach(function (node) {
        node.innerHTML = renderMarkdown(node.textContent || '');
      });

      textarea.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.shiftKey) {
          return;
        }
        event.preventDefault();
        if (textarea.value.trim() === '') {
          return;
        }
        form.requestSubmit();
      });

      if (openContextBtn && contextPanel) {
        openContextBtn.addEventListener('click', function () {
          contextPanel.open = true;
          contextPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
      }
      if (cancel) {
        cancel.addEventListener('click', function () {
          if (abortController) {
            abortController.abort();
          }
        });
      }

      feed.addEventListener('click', async function (event) {
        var target = event.target;
        if (!(target instanceof HTMLElement)) {
          return;
        }
        if (target.classList.contains('ia-log-cite')) {
          var ref = target.getAttribute('data-log-ref');
          if (!ref) {
            return;
          }
          if (contextPanel) {
            contextPanel.open = true;
          }
          var row = document.getElementById('log-ref-' + ref);
          if (row) {
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            row.classList.add('ia-context-focus');
            window.setTimeout(function () { row.classList.remove('ia-context-focus'); }, 1400);
          }
          return;
        }

        var action = target.getAttribute('data-action');
        if (!action) {
          return;
        }
        var msg = target.closest('.ia-msg');
        if (!msg) {
          return;
        }
        if (action === 'copy') {
          var raw = msg.getAttribute('data-raw') || '';
          try { await navigator.clipboard.writeText(raw); } catch (error) {}
          return;
        }
        if (action === 'regenerate') {
          var prev = msg.previousElementSibling;
          while (prev && prev.getAttribute('data-role') !== 'user') {
            prev = prev.previousElementSibling;
          }
          if (!prev) {
            return;
          }
          textarea.value = prev.getAttribute('data-raw') || '';
          resize();
          form.requestSubmit();
          return;
        }
        if (action === 'resolve') {
          var messageId = parseInt(msg.getAttribute('data-message-id') || '0', 10);
          if (!messageId) {
            return;
          }
          var current = target.getAttribute('data-resolved') === '1';
          var next = !current;
          var fd = new FormData();
          fd.append('_csrf', '<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>');
          fd.append('action', 'ai_chat_mark_resolved');
          fd.append('message_id', String(messageId));
          fd.append('resolved', next ? '1' : '0');
          var response = await fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
          var data = await response.json();
          if (response.ok && data && data.ok === true) {
            msg.classList.toggle('ia-msg-resolved', next);
            target.setAttribute('data-resolved', next ? '1' : '0');
            target.textContent = next ? 'Reabrir' : 'Marcar resolvido';
            target.classList.toggle('btn-outline-success', !next);
            target.classList.toggle('btn-outline-warning', next);
          }
        }
      });

      form.addEventListener('submit', async function (event) {
        if (textarea.value.trim() === '') {
          event.preventDefault();
          return;
        }

        event.preventDefault();
        if (abortController) {
          return;
        }
        isNearBottom = shouldStickBottom();
        var message = textarea.value.trim();
        appendMessage('user', message, new Date().toISOString().replace('T', ' ').replace('Z', ' UTC'));
        textarea.value = '';
        resize();
        setSendingState(true);

        var assistantNode = appendMessage('assistant', '', '');
        var assistantBody = assistantNode.querySelector('.ia-text');
        var assistantRaw = '';
        var serverStreamError = '';
        setThinking(assistantNode);
        abortController = new AbortController();

        try {
          var formData = new FormData(form);

          var tryLegacyFallback = async function () {
            var legacyData = new FormData(form);
            legacyData.set('action', 'ai_chat_send');
            var legacyResp = await fetch(form.action, {
              method: 'POST',
              body: legacyData,
              headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
              },
              signal: abortController.signal
            });
            var legacyJson = null;
            try {
              legacyJson = await legacyResp.json();
            } catch (error) {
              legacyJson = null;
            }
            if (!legacyResp.ok || !legacyJson || legacyJson.ok !== true) {
              var legacyReason = (legacyJson && legacyJson.error) ? String(legacyJson.error) : ('HTTP ' + legacyResp.status);
              throw new Error('Falha no fallback do chat: ' + legacyReason);
            }
            return legacyJson;
          };

          var response = await fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'text/event-stream'
            },
            signal: abortController.signal
          });
          if (!response.ok || !response.body) {
            var reason = 'Falha ao iniciar streaming da IA.';
            try {
              var rawErr = await response.text();
              if (rawErr) {
                try {
                  var parsedErr = JSON.parse(rawErr);
                  if (parsedErr && parsedErr.error) {
                    reason = 'Falha no chat IA: ' + String(parsedErr.error);
                  } else {
                    reason = 'Falha no chat IA: HTTP ' + response.status;
                  }
                } catch (error) {
                  var flat = String(rawErr).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                  if (flat !== '') {
                    reason = 'Falha no chat IA: ' + flat.slice(0, 240);
                  } else {
                    reason = 'Falha no chat IA: HTTP ' + response.status;
                  }
                }
              } else {
                reason = 'Falha no chat IA: HTTP ' + response.status;
              }
            } catch (error) {
              reason = 'Falha no chat IA: HTTP ' + response.status;
            }

            try {
              var legacy = await tryLegacyFallback();
              stopThinking();
              assistantRaw = (legacy.assistant && legacy.assistant.content) ? String(legacy.assistant.content) : '';
              if (assistantRaw === '') {
                assistantRaw = reason;
              }
              assistantNode.setAttribute('data-raw', assistantRaw);
              if (assistantBody) {
                assistantBody.innerHTML = renderMarkdown(assistantRaw);
              }
              if (legacy.assistant && legacy.assistant.id) {
                assistantNode.setAttribute('data-message-id', String(legacy.assistant.id));
              }
              var fallbackMetaDate = assistantNode.querySelector('.ia-meta span:last-child');
              if (fallbackMetaDate && legacy.assistant && legacy.assistant.created_at) {
                fallbackMetaDate.textContent = String(legacy.assistant.created_at);
              }
              scrollBottom(false);
              return;
            } catch (fallbackError) {
              throw new Error(reason);
            }
          }

          var reader = response.body.getReader();
          var decoder = new TextDecoder();
          var buffer = '';
          while (true) {
            var chunk = await reader.read();
            if (chunk.done) {
              break;
            }
            buffer += decoder.decode(chunk.value, { stream: true });
            var sep = buffer.indexOf('\n\n');
            while (sep !== -1) {
              var parsed = parseEventBlock(buffer.slice(0, sep));
              buffer = buffer.slice(sep + 2);
              if (parsed && parsed.type === 'delta') {
                stopThinking();
                var delta = (parsed.payload && parsed.payload.delta) ? String(parsed.payload.delta) : '';
                if (delta !== '') {
                  assistantRaw += delta;
                  assistantNode.setAttribute('data-raw', assistantRaw);
                  if (assistantBody) {
                    assistantBody.innerHTML = renderMarkdown(assistantRaw);
                  }
                  scrollBottom(false);
                }
              }
              if (parsed && parsed.type === 'error') {
                serverStreamError = (parsed.payload && parsed.payload.error) ? String(parsed.payload.error) : 'Falha no stream da IA.';
              }
              if (parsed && parsed.type === 'done' && parsed.payload && parsed.payload.assistant) {
                if (parsed.payload.assistant.id) {
                  assistantNode.setAttribute('data-message-id', String(parsed.payload.assistant.id));
                }
                var metaDate = assistantNode.querySelector('.ia-meta span:last-child');
                if (metaDate && parsed.payload.assistant.created_at) {
                  metaDate.textContent = String(parsed.payload.assistant.created_at);
                }
              }
              sep = buffer.indexOf('\n\n');
            }
          }
          stopThinking();
          if (assistantRaw === '' && serverStreamError !== '') {
            assistantRaw = serverStreamError;
            assistantNode.setAttribute('data-raw', assistantRaw);
            if (assistantBody) {
              assistantBody.innerHTML = renderMarkdown(assistantRaw);
            }
          }
        } catch (error) {
          stopThinking();
          var failedText = abortController && abortController.signal.aborted
            ? 'Geracao interrompida pelo operador.'
            : 'Falha no chat IA: ' + (error && error.message ? error.message : 'erro inesperado');
          assistantRaw = assistantRaw || failedText;
          assistantNode.setAttribute('data-raw', assistantRaw);
          if (assistantBody) {
            assistantBody.innerHTML = renderMarkdown(assistantRaw);
          }
        } finally {
          stopThinking();
          abortController = null;
          setSendingState(false);
          textarea.focus();
          scrollBottom(false);
        }
      });
    })();
  </script>
<?php endif; ?>

<?php if ($tab === 'costs'): ?>
  <?php
    $fmtMoney = static function (?float $value, string $currency): string {
        if ($value === null) {
            return 'N/D';
        }
        return $currency . ' ' . number_format($value, 2, ',', '.');
    };
    $fmtMoneyBrl = static function (?float $value): string {
        if ($value === null) {
            return 'N/D';
        }
        return 'R$ ' . number_format($value, 2, ',', '.');
    };
    $toBrlOrOriginal = static function (?float $value, string $currency) use ($fxRates): ?float {
        $converted = convert_amount_to_brl($value, $currency, $fxRates);
        if ($converted !== null) {
            return $converted;
        }
        if ($currency === 'BRL') {
            return $value;
        }
        return null;
    };
    $costCurrency = (string) ($costEstimate['currency'] ?? 'EUR');
    $hourlyBrl = $toBrlOrOriginal($costEstimate['hourly_gross'] ?? null, $costCurrency);
    $monthlyBrl = $toBrlOrOriginal($costEstimate['monthly_gross'] ?? null, $costCurrency);
    $mtdBrl = $toBrlOrOriginal($costEstimate['mtd_gross'] ?? null, $costCurrency);
    $forecastBrl = $toBrlOrOriginal($costEstimate['forecast_month_gross'] ?? null, $costCurrency);
    $monthlyNetBrl = $toBrlOrOriginal($costEstimate['monthly_net'] ?? null, $costCurrency);
    $dailyGrossBrl = $toBrlOrOriginal($costEstimate['daily_gross'] ?? null, $costCurrency);
  ?>
  <div class="row">
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Hora (gross)</small><h5 class="mb-1"><?= htmlspecialchars($fmtMoneyBrl($hourlyBrl), ENT_QUOTES, 'UTF-8') ?></h5><small class="text-body-secondary"><?= htmlspecialchars($fmtMoney($costEstimate['hourly_gross'] ?? null, $costCurrency), ENT_QUOTES, 'UTF-8') ?></small></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Mes (gross)</small><h5 class="mb-1"><?= htmlspecialchars($fmtMoneyBrl($monthlyBrl), ENT_QUOTES, 'UTF-8') ?></h5><small class="text-body-secondary"><?= htmlspecialchars($fmtMoney($costEstimate['monthly_gross'] ?? null, $costCurrency), ENT_QUOTES, 'UTF-8') ?></small></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">MTD estimado</small><h5 class="mb-1"><?= htmlspecialchars($fmtMoneyBrl($mtdBrl), ENT_QUOTES, 'UTF-8') ?></h5><small class="text-body-secondary"><?= htmlspecialchars($fmtMoney($costEstimate['mtd_gross'] ?? null, $costCurrency), ENT_QUOTES, 'UTF-8') ?></small></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Forecast mes</small><h5 class="mb-1"><?= htmlspecialchars($fmtMoneyBrl($forecastBrl), ENT_QUOTES, 'UTF-8') ?></h5><small class="text-body-secondary"><?= htmlspecialchars($fmtMoney($costEstimate['forecast_month_gross'] ?? null, $costCurrency), ENT_QUOTES, 'UTF-8') ?></small></div></div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><strong>Costs</strong></div>
    <div class="card-body">
      <p class="mb-2">Estimativa por servidor baseada em <code>server_type.prices</code> da API Hetzner (nao e fatura oficial).</p>
      <dl class="mb-0">
        <dt>Moeda exibida</dt>
        <dd>BRL (Real)</dd>
        <dt>Moeda de origem</dt>
        <dd><?= htmlspecialchars((string) ($costEstimate['currency'] ?? 'EUR'), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>Regiao precificada</dt>
        <dd><?= htmlspecialchars((string) ($costEstimate['location'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>Fonte</dt>
        <dd><?= htmlspecialchars((string) ($costEstimate['source'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>Mensal (net)</dt>
        <dd><?= htmlspecialchars($fmtMoneyBrl($monthlyNetBrl), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>Diario (gross)</dt>
        <dd><?= htmlspecialchars($fmtMoneyBrl($dailyGrossBrl), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>Referencia original</dt>
        <dd><?= htmlspecialchars($fmtMoney($costEstimate['monthly_net'] ?? null, (string) ($costEstimate['currency'] ?? 'EUR')), ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars($fmtMoney($costEstimate['daily_gross'] ?? null, (string) ($costEstimate['currency'] ?? 'EUR')), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>Cotacao USD/BRL</dt>
        <dd><?= htmlspecialchars(isset(($fxRates['rates'] ?? [])['USD']) ? number_format((float) $fxRates['rates']['USD'], 4, ',', '.') : 'N/D', ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>Cotacao EUR/BRL</dt>
        <dd><?= htmlspecialchars(isset(($fxRates['rates'] ?? [])['EUR']) ? number_format((float) $fxRates['rates']['EUR'], 4, ',', '.') : 'N/D', ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>Fonte cambio</dt>
        <dd><code><?= htmlspecialchars((string) ($fxRates['source'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></code></dd>
        <dt>Atualizado em</dt>
        <dd><?= htmlspecialchars((string) ($fxRates['as_of'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
      </dl>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'snapshots'): ?>
  <div class="card">
    <div class="card-header"><strong>Snapshots</strong></div>
    <div class="card-body">
      <div class="alert alert-secondary">
        Agendamento e retencao sao aplicados por servidor dentro do tenant atual (empresa + projeto).
      </div>
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <p class="mb-1">Historico real de snapshots/backups coletados para este servidor.</p>
          <small class="text-body-secondary">External ID: <code><?= htmlspecialchars($serverExternalId, ENT_QUOTES, 'UTF-8') ?></code></small>
        </div>
        <div class="d-flex gap-2">
          <?php if ($canManage): ?>
            <form method="post" action="/server_details.php?id=<?= $serverId ?>&tab=snapshots" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="create_snapshot_now">
              <button type="submit" class="btn btn-primary">Snapshot agora</button>
            </form>
          <?php else: ?>
            <button type="button" class="btn btn-primary" disabled>Snapshot agora</button>
          <?php endif; ?>
          <a href="/hetzner_account_details.php?id=<?= (int) ($server['provider_account_id'] ?? 0) ?>&tab=assets&asset_type=snapshots" class="btn btn-outline-secondary">Abrir inventario</a>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><strong>Politica de snapshots</strong></div>
        <div class="card-body">
          <form method="post" action="/server_details.php?id=<?= $serverId ?>&tab=snapshots">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_snapshot_policy">
            <div class="row g-2">
              <div class="col-lg-3">
                <label class="form-label mb-1">Agendamento</label>
                <select class="form-select" name="schedule_mode" <?= $canManage ? '' : 'disabled' ?>>
                  <option value="manual" <?= $snapshotScheduleMode === 'manual' ? 'selected' : '' ?>>Manual</option>
                  <option value="interval" <?= $snapshotScheduleMode === 'interval' ? 'selected' : '' ?>>Intervalo</option>
                </select>
              </div>
              <div class="col-lg-3">
                <label class="form-label mb-1">Intervalo (horas)</label>
                <input type="number" min="1" max="168" step="1" class="form-control" name="interval_hours" value="<?= htmlspecialchars($snapshotIntervalHours, ENT_QUOTES, 'UTF-8') ?>" <?= $canManage ? '' : 'disabled' ?>>
              </div>
              <div class="col-lg-3">
                <label class="form-label mb-1">Retencao por dias</label>
                <input type="number" min="1" max="3650" class="form-control" name="retention_days" value="<?= htmlspecialchars((string) ($snapshotPolicy['retention_days'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= $canManage ? '' : 'disabled' ?>>
              </div>
              <div class="col-lg-3">
                <label class="form-label mb-1">Retencao por quantidade</label>
                <input type="number" min="1" max="500" class="form-control" name="retention_count" value="<?= htmlspecialchars((string) ($snapshotPolicy['retention_count'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= $canManage ? '' : 'disabled' ?>>
              </div>
            </div>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" name="enabled" value="1" id="snapshot_policy_enabled" <?= ((bool) ($snapshotPolicy['enabled'] ?? false)) ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
              <label class="form-check-label" for="snapshot_policy_enabled">Politica ativa para este servidor</label>
            </div>
            <div class="mt-2">
              <small class="text-body-secondary d-block">
                Ultimo status: <strong><?= htmlspecialchars($snapshotStatusLabel, ENT_QUOTES, 'UTF-8') ?></strong> |
                Ultima execucao: <strong><?= htmlspecialchars($snapshotLastRunLabel, ENT_QUOTES, 'UTF-8') ?></strong> |
                Proxima execucao: <strong><?= htmlspecialchars($snapshotNextRunLabel, ENT_QUOTES, 'UTF-8') ?></strong>
              </small>
              <small class="text-body-secondary d-block">
                Intervalo atual: <strong><?= htmlspecialchars($snapshotIntervalLabel, ENT_QUOTES, 'UTF-8') ?></strong>
              </small>
              <?php if (trim((string) ($snapshotPolicy['last_error'] ?? '')) !== ''): ?>
                <small class="text-danger d-block">Ultimo erro: <?= htmlspecialchars((string) ($snapshotPolicy['last_error'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
              <?php endif; ?>
              <?php if ($snapshotAutoScheduleEnabled): ?>
                <?php if ($isPlatformOwner): ?>
                  <small class="text-body-secondary d-block mt-1">
                    Scheduler (admin global): configure cron para rodar periodicamente:
                    <code>php /var/www/html/cli/run_snapshot_scheduler.php 50</code>
                  </small>
                <?php else: ?>
                  <small class="text-body-secondary d-block mt-1">
                    Agendamento automatico ativo. A execucao depende do scheduler global da plataforma.
                  </small>
                <?php endif; ?>
              <?php else: ?>
                <small class="text-body-secondary d-block mt-1">
                  Para snapshots automaticos, ative a politica e selecione "Intervalo".
                </small>
              <?php endif; ?>
            </div>
            <div class="mt-3 d-flex gap-2">
              <?php if ($canManage): ?>
                <button type="submit" class="btn btn-primary">Salvar politica</button>
              <?php else: ?>
                <button type="button" class="btn btn-primary" disabled>Salvar politica</button>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><strong>Ultimas execucoes</strong></div>
        <div class="card-body p-0">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Inicio</th>
                <th>Tipo</th>
                <th>Status</th>
                <th>Snapshot ID</th>
                <th>Mensagem</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($snapshotRuns === []): ?>
                <tr>
                  <td colspan="5" class="text-center text-body-secondary py-3">Sem execucoes registradas.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($snapshotRuns as $run): ?>
                  <tr>
                    <td><?= htmlspecialchars((string) ($run['started_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($run['run_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($run['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><code><?= htmlspecialchars((string) ($run['snapshot_external_id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><?= htmlspecialchars((string) ($run['message'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>ID</th>
            <th>Tipo</th>
            <th>Nome</th>
            <th>Status</th>
            <th>Tamanho</th>
            <th>Criado em</th>
            <th>Descricao</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($snapshotAssets === []): ?>
            <tr>
              <td colspan="7" class="text-center text-body-secondary py-4">
                Nenhum snapshot/backup encontrado para este servidor.
                <div class="mt-2">Use <strong>Coletar inventario</strong> na conta Hetzner para atualizar.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($snapshotAssets as $snapshot): ?>
              <tr>
                <td><code><?= htmlspecialchars((string) ($snapshot['external_id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars((string) ($snapshot['kind'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($snapshot['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($snapshot['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($snapshot['size_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($snapshot['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($snapshot['description'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'config'): ?>
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Gestao via API Hetzner</strong>
      <div class="d-flex gap-2">
        <?php if (is_platform_owner($user)): ?>
          <a href="/hetzner_operations.php?server_external_id=<?= urlencode($serverExternalId) ?>" class="btn btn-sm btn-primary">Abrir API Explorer</a>
          <a href="/hetzner_operations.php?operation_id=servers.get&server_external_id=<?= urlencode($serverExternalId) ?>" class="btn btn-sm btn-outline-secondary">GET /servers/{id}</a>
          <a href="/hetzner_operations.php?operation_id=server_actions.reboot&server_external_id=<?= urlencode($serverExternalId) ?>" class="btn btn-sm btn-outline-secondary">Reboot</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body">
      <p class="mb-0 text-body-secondary">
        External ID do servidor no provedor: <code><?= htmlspecialchars($serverExternalId, ENT_QUOTES, 'UTF-8') ?></code>.
        Use os atalhos acima para abrir as acoes da API com o <code>id</code> ja preenchido.
      </p>
    </div>
  </div>

  <div class="row">
    <div class="col-lg-6 mb-3">
      <div class="card h-100">
        <div class="card-header"><strong>Metadados brutos</strong></div>
        <div class="card-body">
          <pre class="mb-0" style="white-space: pre-wrap;"><?= htmlspecialchars(json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
        </div>
      </div>
    </div>

    <div class="col-lg-6 mb-3">
      <div class="card h-100">
        <div class="card-header"><strong>Labels</strong></div>
        <div class="card-body p-0">
          <table class="table mb-0">
            <thead><tr><th>Chave</th><th>Valor</th></tr></thead>
            <tbody>
              <?php if ($labels === []): ?>
                <tr><td colspan="2" class="text-center text-body-secondary py-4">Sem labels neste servidor.</td></tr>
              <?php else: ?>
                <?php foreach ($labels as $key => $value): ?>
                  <tr>
                    <td><?= htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php if ($tab === 'services' && $canManage && $tenantLokiReady): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('omnilogs-install-form');
  var button = document.getElementById('omnilogs-install-btn');
  var progress = document.getElementById('omnilogs-install-progress');
  var guard = document.getElementById('omnilogs-lower-guard');
  var content = document.getElementById('omnilogs-lower-content');
  var overlay = document.getElementById('omnilogs-lower-overlay');
  var unlockBtn = document.getElementById('omnilogs-unlock-btn');
  if (!form || !button || !progress || !guard || !content || !overlay || !unlockBtn) {
    return;
  }
  var serverId = guard.getAttribute('data-server-id') || '0';
  var flashError = guard.getAttribute('data-flash-error') === '1';
  var flashSuccess = guard.getAttribute('data-flash-success') === '1';
  var lockKey = 'omnilogs_install_lock_' + serverId;

  var setLocked = function (locked, allowUnlock) {
    if (locked) {
      content.style.pointerEvents = 'none';
      content.style.opacity = '0.45';
      overlay.classList.remove('d-none');
      unlockBtn.classList.toggle('d-none', !allowUnlock);
    } else {
      content.style.pointerEvents = '';
      content.style.opacity = '';
      overlay.classList.add('d-none');
      unlockBtn.classList.add('d-none');
    }
  };

  if (flashSuccess) {
    try { localStorage.removeItem(lockKey); } catch (error) {}
    setLocked(false, false);
  } else if (flashError) {
    try { localStorage.setItem(lockKey, 'error'); } catch (error) {}
  }

  var lockState = '';
  try { lockState = localStorage.getItem(lockKey) || ''; } catch (error) {}
  if (lockState === 'pending') {
    setLocked(true, false);
  } else if (lockState === 'error') {
    setLocked(true, true);
  } else {
    setLocked(false, false);
  }

  unlockBtn.addEventListener('click', function () {
    try { localStorage.removeItem(lockKey); } catch (error) {}
    setLocked(false, false);
  });

  form.addEventListener('submit', function () {
    button.disabled = true;
    button.textContent = 'Instalando...';
    progress.classList.remove('d-none');
    try { localStorage.setItem(lockKey, 'pending'); } catch (error) {}
    setLocked(true, false);
  });
});
</script>
<?php endif; ?>
<?php
ui_page_end();
