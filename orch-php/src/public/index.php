<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/tenancy.php';
require_once __DIR__ . '/../app/ui.php';

require_auth();
$user = current_user();
if ($user === null) {
    redirect('/login.php');
}

$context = load_user_context((int) $user['id']);
$flash = flash_pull();
$userId = (int) $user['id'];

function exec_dashboard_row(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return is_array($row) ? $row : [];
}

function exec_dashboard_rows(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

$summary = exec_dashboard_row(
    "SELECT
        (SELECT COUNT(*)
         FROM companies c
         INNER JOIN company_users cu ON cu.company_id = c.id
         WHERE cu.user_id = :user_id
           AND c.status = 'active') AS companies_active,
        (SELECT COUNT(*)
         FROM projects p
         INNER JOIN company_users cu ON cu.company_id = p.company_id
         WHERE cu.user_id = :user_id
           AND p.status = 'active') AS services_total,
        (SELECT COUNT(*)
         FROM provider_accounts pa
         INNER JOIN company_users cu ON cu.company_id = pa.company_id
         WHERE cu.user_id = :user_id) AS accounts_total,
        (SELECT COUNT(*)
         FROM provider_accounts pa
         INNER JOIN company_users cu ON cu.company_id = pa.company_id
         WHERE cu.user_id = :user_id
           AND pa.status = 'active') AS accounts_active,
        (SELECT COUNT(*)
         FROM provider_accounts pa
         INNER JOIN company_users cu ON cu.company_id = pa.company_id
         WHERE cu.user_id = :user_id
           AND pa.status IN ('error', 'invalid')) AS accounts_critical,
        (SELECT COUNT(*)
         FROM hetzner_servers hs
         INNER JOIN company_users cu ON cu.company_id = hs.company_id
         WHERE cu.user_id = :user_id) AS servers_total,
        (SELECT COUNT(*)
         FROM hetzner_servers hs
         INNER JOIN company_users cu ON cu.company_id = hs.company_id
         WHERE cu.user_id = :user_id
           AND LOWER(hs.status) NOT IN ('running', 'ok', 'active', 'healthy')) AS servers_critical,
        (SELECT COUNT(*)
         FROM job_runs jr
         INNER JOIN company_users cu ON cu.company_id = jr.company_id
         WHERE cu.user_id = :user_id
           AND jr.status = 'error'
           AND jr.started_at >= NOW() - INTERVAL '24 hours') AS failed_jobs_24h,
        (SELECT COUNT(*)
         FROM job_runs jr
         INNER JOIN company_users cu ON cu.company_id = jr.company_id
         WHERE cu.user_id = :user_id
           AND jr.status = 'success'
           AND jr.started_at >= NOW() - INTERVAL '24 hours') AS success_jobs_24h",
    ['user_id' => $userId]
);

$llmTableAvailable = (bool) db()->query("SELECT to_regclass('public.company_llm_keys') IS NOT NULL")->fetchColumn();
$llmKeysTotal = 0;
$llmKeysActive = 0;
if ($llmTableAvailable) {
    $llmRow = exec_dashboard_row(
        "SELECT
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE ck.status = 'active') AS active
         FROM company_llm_keys ck
         INNER JOIN company_users cu ON cu.company_id = ck.company_id
         WHERE cu.user_id = :user_id",
        ['user_id' => $userId]
    );
    $llmKeysTotal = (int) ($llmRow['total'] ?? 0);
    $llmKeysActive = (int) ($llmRow['active'] ?? 0);
}

$criticalRows = exec_dashboard_rows(
    "SELECT
        p.id AS project_id,
        c.name AS company_name,
        p.name AS service_name,
        COALESCE(
            p.capabilities->>'provider',
            p.capabilities->>'provider_type',
            'indefinido'
        ) AS provider_type,
        COALESCE(acc.accounts_critical, 0) AS accounts_critical,
        COALESCE(srv.servers_critical, 0) AS servers_critical,
        COALESCE(job.failed_jobs_24h, 0) AS failed_jobs_24h
     FROM projects p
     INNER JOIN companies c ON c.id = p.company_id
     INNER JOIN company_users cu ON cu.company_id = p.company_id
     LEFT JOIN (
         SELECT
             company_id,
             project_id,
             COUNT(*) FILTER (WHERE status IN ('error', 'invalid')) AS accounts_critical
         FROM provider_accounts
         GROUP BY company_id, project_id
     ) acc ON acc.company_id = p.company_id AND acc.project_id = p.id
     LEFT JOIN (
         SELECT
             company_id,
             project_id,
             COUNT(*) FILTER (WHERE LOWER(status) NOT IN ('running', 'ok', 'active', 'healthy')) AS servers_critical
         FROM hetzner_servers
         GROUP BY company_id, project_id
     ) srv ON srv.company_id = p.company_id AND srv.project_id = p.id
     LEFT JOIN (
         SELECT
             company_id,
             project_id,
             COUNT(*) FILTER (
                 WHERE status = 'error'
                   AND started_at >= NOW() - INTERVAL '24 hours'
             ) AS failed_jobs_24h
         FROM job_runs
         GROUP BY company_id, project_id
     ) job ON job.company_id = p.company_id AND job.project_id = p.id
     WHERE cu.user_id = :user_id
       AND (
         COALESCE(acc.accounts_critical, 0) > 0
         OR COALESCE(srv.servers_critical, 0) > 0
         OR COALESCE(job.failed_jobs_24h, 0) > 0
       )
     ORDER BY
       (COALESCE(acc.accounts_critical, 0) * 3
        + COALESCE(srv.servers_critical, 0) * 2
        + COALESCE(job.failed_jobs_24h, 0)) DESC,
       c.name,
       p.name
     LIMIT 12",
    ['user_id' => $userId]
);

$accessibleProjects = list_accessible_projects($userId);
$providerDistribution = [];
foreach ($accessibleProjects as $project) {
    $provider = infer_provider_type_from_project($project) ?? 'indefinido';
    if (!array_key_exists($provider, $providerDistribution)) {
        $providerDistribution[$provider] = 0;
    }
    $providerDistribution[$provider]++;
}
ksort($providerDistribution);

$jobsDailyRows = exec_dashboard_rows(
    "SELECT
        DATE_TRUNC('day', jr.started_at) AS day,
        COUNT(*) FILTER (WHERE jr.status = 'success') AS success_count,
        COUNT(*) FILTER (WHERE jr.status = 'error') AS error_count
     FROM job_runs jr
     INNER JOIN company_users cu ON cu.company_id = jr.company_id
     WHERE cu.user_id = :user_id
       AND jr.started_at >= NOW() - INTERVAL '6 days'
     GROUP BY 1
     ORDER BY 1",
    ['user_id' => $userId]
);

$jobsSeries = [];
for ($i = 6; $i >= 0; $i--) {
    $day = (new DateTimeImmutable('today'))->sub(new DateInterval('P' . $i . 'D'));
    $key = $day->format('Y-m-d');
    $jobsSeries[$key] = [
        'label' => $day->format('d/m'),
        'success' => 0,
        'error' => 0,
    ];
}
foreach ($jobsDailyRows as $jobsRow) {
    $dayRaw = (string) ($jobsRow['day'] ?? '');
    $dayKey = substr($dayRaw, 0, 10);
    if (!array_key_exists($dayKey, $jobsSeries)) {
        continue;
    }
    $jobsSeries[$dayKey]['success'] = (int) ($jobsRow['success_count'] ?? 0);
    $jobsSeries[$dayKey]['error'] = (int) ($jobsRow['error_count'] ?? 0);
}

$auditRows = exec_dashboard_rows(
    "SELECT
        ae.created_at,
        ae.action,
        COALESCE(c.name, '-') AS company_name,
        COALESCE(p.name, '-') AS service_name
     FROM audit_events ae
     LEFT JOIN companies c ON c.id = ae.company_id
     LEFT JOIN projects p ON p.id = ae.project_id
     INNER JOIN company_users cu ON cu.company_id = c.id
     WHERE cu.user_id = :user_id
     ORDER BY ae.created_at DESC
     LIMIT 10",
    ['user_id' => $userId]
);

$companiesActive = (int) ($summary['companies_active'] ?? 0);
$servicesTotal = (int) ($summary['services_total'] ?? 0);
$accountsActive = (int) ($summary['accounts_active'] ?? 0);
$accountsTotal = (int) ($summary['accounts_total'] ?? 0);
$accountsCritical = (int) ($summary['accounts_critical'] ?? 0);
$serversTotal = (int) ($summary['servers_total'] ?? 0);
$serversCritical = (int) ($summary['servers_critical'] ?? 0);
$failedJobs24h = (int) ($summary['failed_jobs_24h'] ?? 0);
$successJobs24h = (int) ($summary['success_jobs_24h'] ?? 0);
$criticalEnvironments = count($criticalRows);

$cards = [
    [
        'title' => 'Empresas ativas',
        'value' => number_format($companiesActive, 0, ',', '.'),
        'desc' => 'Tenants com acesso do usuario',
        'icon' => 'bi-buildings',
        'color' => 'text-bg-primary',
    ],
    [
        'title' => 'Fornecedores totais',
        'value' => number_format($servicesTotal, 0, ',', '.'),
        'desc' => 'Servicos cadastrados',
        'icon' => 'bi-diagram-3',
        'color' => 'text-bg-success',
    ],
    [
        'title' => 'Ambientes criticos',
        'value' => number_format($criticalEnvironments, 0, ',', '.'),
        'desc' => 'Com falha ativa ou degradacao',
        'icon' => 'bi-exclamation-triangle',
        'color' => $criticalEnvironments > 0 ? 'text-bg-danger' : 'text-bg-success',
    ],
    [
        'title' => 'Falhas em 24h',
        'value' => number_format($failedJobs24h, 0, ',', '.'),
        'desc' => 'Jobs com status error',
        'icon' => 'bi-bug',
        'color' => $failedJobs24h > 0 ? 'text-bg-warning' : 'text-bg-info',
    ],
];

$healthLabels = ['Contas ativas', 'Contas criticas', 'Servidores saudaveis', 'Servidores criticos', 'Jobs ok (24h)', 'Jobs falhos (24h)'];
$healthValues = [
    max($accountsActive, 0),
    max($accountsCritical, 0),
    max($serversTotal - $serversCritical, 0),
    max($serversCritical, 0),
    max($successJobs24h, 0),
    max($failedJobs24h, 0),
];

$providerLabels = array_map(static fn ($label) => strtoupper((string) $label), array_keys($providerDistribution));
$providerValues = array_values($providerDistribution);

$jobsChartLabels = [];
$jobsChartSuccess = [];
$jobsChartErrors = [];
foreach ($jobsSeries as $point) {
    $jobsChartLabels[] = $point['label'];
    $jobsChartSuccess[] = $point['success'];
    $jobsChartErrors[] = $point['error'];
}

$companyId = $context['company_id'] ?? null;
$projectId = $context['project_id'] ?? null;
$providerType = context_provider_type($context);

$summaryRows = [
    ['label' => 'Contas conectadas', 'value' => number_format($accountsTotal, 0, ',', '.'), 'class' => ''],
    ['label' => 'Contas criticas', 'value' => number_format($accountsCritical, 0, ',', '.'), 'class' => 'is-danger'],
    ['label' => 'Servidores totais', 'value' => number_format($serversTotal, 0, ',', '.'), 'class' => ''],
    ['label' => 'Servidores criticos', 'value' => number_format($serversCritical, 0, ',', '.'), 'class' => 'is-danger'],
    ['label' => 'Jobs ok (24h)', 'value' => number_format($successJobs24h, 0, ',', '.'), 'class' => 'is-success'],
    ['label' => 'Jobs falhos (24h)', 'value' => number_format($failedJobs24h, 0, ',', '.'), 'class' => 'is-warn'],
    ['label' => 'Chaves LLM ativas', 'value' => number_format($llmKeysActive, 0, ',', '.'), 'class' => ''],
    ['label' => 'Total chaves LLM', 'value' => number_format($llmKeysTotal, 0, ',', '.'), 'class' => ''],
];

$auditItems = [];
foreach ($auditRows as $audit) {
    $auditCreatedAt = (string) ($audit['created_at'] ?? '');
    $auditCreatedAtTs = strtotime($auditCreatedAt);
    $auditWhen = $auditCreatedAtTs !== false ? gmdate('d/m/Y H:i:s', $auditCreatedAtTs) . ' UTC' : $auditCreatedAt;
    $auditItems[] = [
        'action' => (string) ($audit['action'] ?? '-'),
        'scope' => trim((string) ($audit['company_name'] ?? '-')) . ' / ' . trim((string) ($audit['service_name'] ?? '-')),
        'when' => $auditWhen,
    ];
}

$criticalItems = [];
foreach ($criticalRows as $critical) {
    $severityScore =
        ((int) ($critical['accounts_critical'] ?? 0) * 3) +
        ((int) ($critical['servers_critical'] ?? 0) * 2) +
        (int) ($critical['failed_jobs_24h'] ?? 0);
    $severityClass = 'sev-low';
    $severityLabel = 'Baixa';
    if ($severityScore >= 6) {
        $severityClass = 'sev-high';
        $severityLabel = 'Alta';
    } elseif ($severityScore >= 3) {
        $severityClass = 'sev-mid';
        $severityLabel = 'Media';
    }
    $criticalItems[] = [
        'company_name' => (string) ($critical['company_name'] ?? '-'),
        'service_name' => (string) ($critical['service_name'] ?? '-'),
        'provider_type' => (string) ($critical['provider_type'] ?? 'indefinido'),
        'accounts_critical' => (int) ($critical['accounts_critical'] ?? 0),
        'servers_critical' => (int) ($critical['servers_critical'] ?? 0),
        'failed_jobs_24h' => (int) ($critical['failed_jobs_24h'] ?? 0),
        'severity_label' => $severityLabel,
        'severity_class' => $severityClass,
    ];
}

$dashboardPayload = [
    'header' => [
        'title' => 'Painel Executivo Global',
        'subtitle' => 'Visao consolidada de empresas, fornecedores e ambientes criticos.',
        'company' => (string) ($context['company']['name'] ?? 'Sem empresa'),
        'project' => (string) ($context['project']['name'] ?? 'Sem fornecedor'),
        'provider' => (string) ($providerType ?? 'indefinido'),
    ],
    'cards' => array_map(
        static fn (array $card): array => [
            'title' => (string) ($card['title'] ?? ''),
            'value' => (string) ($card['value'] ?? '0'),
            'desc' => (string) ($card['desc'] ?? ''),
            'color' => (string) ($card['color'] ?? 'text-bg-secondary'),
        ],
        $cards
    ),
    'summary_rows' => $summaryRows,
    'providers' => [
        'labels' => $providerLabels,
        'values' => $providerValues,
    ],
    'health' => [
        'labels' => $healthLabels,
        'values' => $healthValues,
    ],
    'jobs' => [
        'labels' => $jobsChartLabels,
        'success' => $jobsChartSuccess,
        'error' => $jobsChartErrors,
    ],
    'audit' => $auditItems,
    'critical' => $criticalItems,
];

ui_page_start('OmniNOC | Dashboard');
ui_navigation('dashboard', $user, $context, $flash);
?>
<style>
  .dash-root { display: grid; gap: 12px; }
  .dash-header h3 { margin: 0 0 4px; }
  .dash-header small { color: #a7b0bf; }
  .dash-cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
  .dash-card {
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.12);
    padding: 12px;
    display: grid;
    gap: 6px;
    min-height: 120px;
  }
  .dash-card-value { font-size: 30px; font-weight: 800; line-height: 1; }
  .dash-card-title { font-size: 14px; font-weight: 700; }
  .dash-card-desc { font-size: 12px; opacity: 0.86; }
  .text-bg-primary { background: linear-gradient(140deg, rgba(59,130,246,0.25), rgba(37,99,235,0.1)); }
  .text-bg-success { background: linear-gradient(140deg, rgba(16,185,129,0.26), rgba(5,150,105,0.08)); }
  .text-bg-danger { background: linear-gradient(140deg, rgba(239,68,68,0.22), rgba(185,28,28,0.08)); }
  .text-bg-warning { background: linear-gradient(140deg, rgba(245,158,11,0.22), rgba(180,83,9,0.08)); }
  .text-bg-info { background: linear-gradient(140deg, rgba(14,165,233,0.22), rgba(2,132,199,0.08)); }
  .dash-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
  .dash-grid-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 12px; }
  .dash-panel {
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.14);
    background: rgba(15,23,42,0.32);
    overflow: hidden;
  }
  .dash-panel-header {
    border-bottom: 1px solid rgba(255,255,255,0.1);
    padding: 12px 14px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
  }
  .dash-panel-title { margin: 0; font-size: 16px; font-weight: 700; }
  .dash-panel-sub { color: #9aa4b7; font-size: 12px; }
  .dash-panel-body { padding: 12px; }
  .dash-summary { margin: 0; padding: 0; list-style: none; }
  .dash-summary li {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    padding: 8px 0;
    font-size: 13px;
  }
  .dash-summary li:last-child { border-bottom: none; }
  .dash-summary-value { font-weight: 700; }
  .dash-summary-value.is-danger { color: #fca5a5; }
  .dash-summary-value.is-success { color: #86efac; }
  .dash-summary-value.is-warn { color: #fcd34d; }
  .dash-chart { height: 240px; }
  .dash-chart-lg { height: 320px; }
  .dash-events-wrap { max-height: 360px; overflow-y: auto; overflow-x: hidden; }
  .dash-events {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
  }
  .dash-events th, .dash-events td {
    padding: 10px 8px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    text-align: left;
    vertical-align: top;
  }
  .dash-events th { font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #9aa4b7; }
  .dash-events code {
    display: inline-block;
    max-width: 100%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .dash-events .scope { word-break: normal; overflow-wrap: anywhere; }
  .dash-events .when { white-space: nowrap; }
  .dash-table-wrap { overflow-x: auto; }
  .dash-table { width: 100%; border-collapse: collapse; min-width: 760px; }
  .dash-table th, .dash-table td {
    padding: 10px 8px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    text-align: left;
  }
  .dash-table th { font-size: 12px; color: #9aa4b7; text-transform: uppercase; }
  .sev-tag {
    display: inline-block;
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 700;
  }
  .sev-low { background: rgba(34,197,94,0.18); color: #86efac; }
  .sev-mid { background: rgba(245,158,11,0.2); color: #fcd34d; }
  .sev-high { background: rgba(239,68,68,0.22); color: #fecaca; }
  @media (max-width: 1200px) {
    .dash-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .dash-grid-3, .dash-grid-2 { grid-template-columns: 1fr; }
  }
  @media (max-width: 700px) {
    .dash-cards { grid-template-columns: 1fr; }
  }
</style>
<div id="dashboard-react-root"></div>
<script>window.__DASHBOARD_PAYLOAD__ = <?= json_encode($dashboardPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="https://unpkg.com/react@18/umd/react.production.min.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js" crossorigin="anonymous"></script>
<script type="text/babel">
  (() => {
    const data = window.__DASHBOARD_PAYLOAD__ || {};

    function explainEventAction(action) {
      const value = String(action || "").toLowerCase();
      if (!value) return "Evento de auditoria sem identificador de acao.";
      if (value.includes("omnilogs") && value.includes("install")) return "Instalacao ou reinstalacao do agente OmniLogs no servidor.";
      if (value.includes("login")) return "Autenticacao de usuario no sistema.";
      if (value.includes("logout")) return "Encerramento de sessao de usuario.";
      if (value.includes("create") || value.includes("created")) return "Criacao de recurso no ambiente.";
      if (value.includes("update") || value.includes("edit") || value.includes("patch")) return "Atualizacao de configuracao ou metadados.";
      if (value.includes("delete") || value.includes("remove")) return "Remocao de recurso existente.";
      if (value.includes("runbook")) return "Execucao de runbook operacional.";
      if (value.includes("snapshot")) return "Operacao de snapshot (politica ou execucao).";
      if (value.includes("backup")) return "Operacao de backup (politica ou execucao).";
      return "Evento registrado na trilha de auditoria para esta empresa/fornecedor.";
    }

    function severityScore(row) {
      return ((Number(row.accounts_critical) || 0) * 3) + ((Number(row.servers_critical) || 0) * 2) + (Number(row.failed_jobs_24h) || 0);
    }

    function DashboardApp() {
      const providersRef = React.useRef(null);
      const healthRef = React.useRef(null);
      const jobsRef = React.useRef(null);

      React.useEffect(() => {
        if (typeof echarts === "undefined") return;
        const chartText = document.documentElement.getAttribute("data-theme") === "light" ? "#1f2937" : "#e5e7eb";
        const gridColor = document.documentElement.getAttribute("data-theme") === "light" ? "rgba(31,41,55,0.12)" : "rgba(229,231,235,0.18)";
        const charts = [];

        if (providersRef.current) {
          const chart = echarts.init(providersRef.current);
          chart.setOption({
            textStyle: { color: chartText },
            color: ["#3b82f6", "#22c55e", "#eab308", "#06b6d4", "#ef4444", "#8b5cf6", "#f97316", "#14b8a6"],
            tooltip: { trigger: "item" },
            legend: { bottom: 0, textStyle: { color: chartText } },
            series: [{
              type: "pie",
              radius: ["45%", "72%"],
              avoidLabelOverlap: true,
              label: { color: chartText },
              data: (data.providers?.labels || []).map((name, idx) => ({ name, value: (data.providers?.values || [])[idx] || 0 }))
            }]
          });
          charts.push(chart);
        }

        if (healthRef.current) {
          const chart = echarts.init(healthRef.current);
          chart.setOption({
            color: ["#22c55e", "#ef4444", "#38bdf8", "#dc2626", "#16a34a", "#f59e0b"],
            tooltip: { trigger: "axis" },
            grid: { left: 40, right: 16, top: 20, bottom: 32, containLabel: true },
            xAxis: {
              type: "category",
              data: data.health?.labels || [],
              axisLabel: { color: chartText },
              axisLine: { lineStyle: { color: gridColor } }
            },
            yAxis: {
              type: "value",
              minInterval: 1,
              axisLabel: { color: chartText },
              splitLine: { lineStyle: { color: gridColor } }
            },
            series: [{
              name: "Quantidade",
              type: "bar",
              barMaxWidth: 36,
              data: data.health?.values || []
            }]
          });
          charts.push(chart);
        }

        if (jobsRef.current) {
          const chart = echarts.init(jobsRef.current);
          chart.setOption({
            color: ["#22c55e", "#ef4444"],
            tooltip: { trigger: "axis" },
            legend: { bottom: 0, textStyle: { color: chartText } },
            grid: { left: 44, right: 16, top: 24, bottom: 40, containLabel: true },
            xAxis: {
              type: "category",
              data: data.jobs?.labels || [],
              axisLabel: { color: chartText },
              axisLine: { lineStyle: { color: gridColor } }
            },
            yAxis: {
              type: "value",
              minInterval: 1,
              axisLabel: { color: chartText },
              splitLine: { lineStyle: { color: gridColor } }
            },
            series: [
              { name: "Sucesso", type: "line", smooth: true, areaStyle: { color: "rgba(34,197,94,0.12)" }, data: data.jobs?.success || [] },
              { name: "Erro", type: "line", smooth: true, areaStyle: { color: "rgba(239,68,68,0.12)" }, data: data.jobs?.error || [] }
            ]
          });
          charts.push(chart);
        }

        const resize = () => charts.forEach((chart) => chart.resize());
        window.addEventListener("resize", resize);
        return () => {
          window.removeEventListener("resize", resize);
          charts.forEach((chart) => chart.dispose());
        };
      }, []);

      return (
        <div className="dash-root">
          <div className="dash-header">
            <h3>{data.header?.title || "Painel Executivo Global"}</h3>
            <small>
              {data.header?.subtitle || ""}
              {" "}Contexto atual: <strong>{data.header?.company || "-"}</strong> /{" "}
              <strong>{data.header?.project || "-"}</strong> ({data.header?.provider || "indefinido"})
            </small>
          </div>

          <div className="dash-cards">
            {(data.cards || []).map((card, idx) => (
              <article key={idx} className={`dash-card ${card.color || "text-bg-info"}`}>
                <div className="dash-card-value">{card.value}</div>
                <div className="dash-card-title">{card.title}</div>
                <div className="dash-card-desc">{card.desc}</div>
              </article>
            ))}
          </div>

          <div className="dash-grid-3">
            <section className="dash-panel">
              <div className="dash-panel-header"><h4 className="dash-panel-title">Resumo operacional</h4></div>
              <div className="dash-panel-body">
                <ul className="dash-summary">
                  {(data.summary_rows || []).map((row, idx) => (
                    <li key={idx}>
                      <span>{row.label}</span>
                      <span className={`dash-summary-value ${row.class || ""}`}>{row.value}</span>
                    </li>
                  ))}
                </ul>
              </div>
            </section>
            <section className="dash-panel">
              <div className="dash-panel-header"><h4 className="dash-panel-title">Fornecedores por tipo</h4></div>
              <div className="dash-panel-body"><div ref={providersRef} className="dash-chart" /></div>
            </section>
            <section className="dash-panel">
              <div className="dash-panel-header"><h4 className="dash-panel-title">Saude operacional</h4></div>
              <div className="dash-panel-body"><div ref={healthRef} className="dash-chart" /></div>
            </section>
          </div>

          <section className="dash-panel">
            <div className="dash-panel-header">
              <h4 className="dash-panel-title">Tendencia de jobs (ultimos 7 dias)</h4>
              <span className="dash-panel-sub">Sucesso vs Erro</span>
            </div>
            <div className="dash-panel-body"><div ref={jobsRef} className="dash-chart-lg" /></div>
          </section>

          <section className="dash-panel">
            <div className="dash-panel-header">
              <h4 className="dash-panel-title">Ultimos eventos</h4>
              <span className="dash-panel-sub">{(data.audit || []).length} itens</span>
            </div>
            <div className="dash-events-wrap">
              <table className="dash-events">
                <thead>
                  <tr>
                    <th style={{width: "33%"}}>Acao</th>
                    <th style={{width: "42%"}}>Escopo</th>
                    <th style={{width: "25%"}}>Quando</th>
                  </tr>
                </thead>
                <tbody>
                  {(data.audit || []).length === 0 ? (
                    <tr><td colSpan={3} className="dash-panel-sub">Sem eventos recentes.</td></tr>
                  ) : (
                    (data.audit || []).map((event, idx) => (
                      <tr key={idx}>
                        <td>
                          <code title={`${event.action}\n\n${explainEventAction(event.action)}`}>{event.action}</code>
                        </td>
                        <td className="scope" title={event.scope}>{event.scope}</td>
                        <td className="when">{event.when}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </section>

          <section className="dash-panel">
            <div className="dash-panel-header"><h4 className="dash-panel-title">Ambientes criticos</h4></div>
            <div className="dash-table-wrap">
              <table className="dash-table">
                <thead>
                  <tr>
                    <th>Empresa</th>
                    <th>Fornecedor</th>
                    <th>Tipo</th>
                    <th>Contas criticas</th>
                    <th>Servidores criticos</th>
                    <th>Falhas 24h</th>
                    <th>Severidade</th>
                  </tr>
                </thead>
                <tbody>
                  {(data.critical || []).length === 0 ? (
                    <tr><td colSpan={7} className="dash-panel-sub">Nenhum ambiente critico no momento.</td></tr>
                  ) : (
                    (data.critical || []).map((row, idx) => (
                      <tr key={idx}>
                        <td>{row.company_name}</td>
                        <td>{row.service_name}</td>
                        <td><code>{row.provider_type}</code></td>
                        <td title="Peso 3 no calculo de severidade.">{row.accounts_critical}</td>
                        <td title="Peso 2 no calculo de severidade.">{row.servers_critical}</td>
                        <td title="Peso 1 no calculo de severidade (ultimas 24h).">{row.failed_jobs_24h}</td>
                        <td>
                          <span
                            className={`sev-tag ${row.severity_class}`}
                            title={`Score=${severityScore(row)} (contas*3 + servidores*2 + falhas24h). Regras: 0-2 Baixa, 3-5 Media, 6+ Alta.`}
                          >
                            {row.severity_label}
                          </span>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </section>
        </div>
      );
    }

    const root = document.getElementById("dashboard-react-root");
    if (root) {
      ReactDOM.createRoot(root).render(<DashboardApp />);
    }
  })();
</script>
<?php
ui_page_end();
