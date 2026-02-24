<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/hetzner.php';
require_once __DIR__ . '/../app/observability_config.php';
require_once __DIR__ . '/../app/omnilogs.php';
require_once __DIR__ . '/../app/tenancy.php';
require_once __DIR__ . '/../app/ui.php';

require_auth();
$user = current_user();
if ($user === null) {
    redirect('/login.php');
}
$userId = (int) $user['id'];

$context = load_user_context($userId);
$flash = flash_pull();
$companyId = $context['company_id'];
$projectId = $context['project_id'];
$providerType = context_provider_type($context);

$servers = [];
$observabilityConfig = null;
$omniLogsInstallStatus = [];
$omniLogsSummary = [
    'active' => 0,
    'installing' => 0,
    'error' => 0,
    'not_installed' => 0,
    'config_missing' => 0,
];
if (is_int($companyId) && is_int($projectId) && $providerType === 'hetzner') {
    $servers = list_project_servers($companyId, $projectId);
    $observabilityConfig = get_project_observability_config($companyId, $projectId);
    $omniLogsInstallStatus = omnilogs_latest_install_status_by_server($companyId, $projectId);
}

$search = strtolower(trim((string) ($_GET['q'] ?? '')));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$allowedStatus = ['all', 'running', 'off', 'initializing', 'unknown', 'error'];
if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = 'all';
}

$filteredServers = [];
foreach ($servers as $server) {
    $name = strtolower((string) ($server['name'] ?? ''));
    $ipv4 = strtolower((string) ($server['ipv4'] ?? ''));
    $status = strtolower((string) ($server['status'] ?? ''));

    if ($search !== '' && strpos($name . ' ' . $ipv4, $search) === false) {
        continue;
    }
    if ($statusFilter !== 'all' && $status !== $statusFilter) {
        continue;
    }

    $filteredServers[] = $server;
}

$capacity = summarize_hetzner_capacity($servers);

$lokiConfigReady = false;
if (is_array($observabilityConfig)) {
    $lokiConfigReady = strtolower((string) ($observabilityConfig['status'] ?? 'inactive')) === 'active'
        && trim((string) ($observabilityConfig['loki_push_url'] ?? '')) !== '';
}

foreach ($servers as $server) {
    $serverId = (int) ($server['id'] ?? 0);
    $latestInstall = $serverId > 0 ? ($omniLogsInstallStatus[$serverId] ?? null) : null;
    $installStatus = strtolower((string) ($latestInstall['status'] ?? ''));

    if (!$lokiConfigReady) {
        $omniLogsSummary['config_missing']++;
        continue;
    }
    if ($installStatus === 'success') {
        $omniLogsSummary['active']++;
        continue;
    }
    if ($installStatus === 'running') {
        $omniLogsSummary['installing']++;
        continue;
    }
    if ($installStatus === 'error') {
        $omniLogsSummary['error']++;
        continue;
    }
    $omniLogsSummary['not_installed']++;
}

ui_page_start('OmniNOC | Servidores');
ui_navigation('servers', $user, $context, $flash);
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h3 class="mb-1">Servidores</h3>
    <small class="text-body-secondary">Lista operacional do fornecedor selecionado. Abra o detalhe para logs, custos e snapshots por servidor.</small>
  </div>
  <div class="d-flex gap-2">
    <a href="/projects.php" class="btn btn-outline-secondary">Fornecedores</a>
    <a href="/hetzner.php" class="btn btn-primary">Sincronizar via Hetzner</a>
  </div>
</div>

<?php if ($companyId === null || $projectId === null): ?>
  <div class="alert alert-warning">
    Bloqueado: selecione um fornecedor no topo para acessar servidores.
    <div class="mt-2"><a href="/projects.php" class="btn btn-sm btn-outline-primary">Ir para Empresas e Fornecedores</a></div>
  </div>
<?php elseif ($providerType !== 'hetzner'): ?>
  <div class="alert alert-warning">
    O modulo de servidores atualmente esta ativo para fornecedores Hetzner. Selecione um fornecedor Hetzner para operar inventario.
  </div>
<?php else: ?>
  <div class="row mb-3">
    <div class="col-lg-3 col-sm-6 mb-2">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Servidores</small><h4 class="mb-0"><?= number_format((int) ($capacity['servers_total'] ?? 0), 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-2">
      <div class="card"><div class="card-body"><small class="text-body-secondary">CPU total</small><h4 class="mb-0"><?= number_format((int) ($capacity['cpu_total'] ?? 0), 0, ',', '.') ?> cores</h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-2">
      <div class="card"><div class="card-body"><small class="text-body-secondary">RAM total</small><h4 class="mb-0"><?= number_format((float) ($capacity['memory_total_gb'] ?? 0), 1, ',', '.') ?> GB</h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-2">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Disco total</small><h4 class="mb-0"><?= number_format((float) ($capacity['disk_total_gb'] ?? 0), 0, ',', '.') ?> GB</h4></div></div>
    </div>
  </div>
  <div class="row mb-3">
    <div class="col-lg-2 col-sm-6 mb-2">
      <div class="card"><div class="card-body"><small class="text-body-secondary">OmniLogs ativos</small><h4 class="mb-0 text-success"><?= number_format((int) $omniLogsSummary['active'], 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-2 col-sm-6 mb-2">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Instalando</small><h4 class="mb-0 text-info"><?= number_format((int) $omniLogsSummary['installing'], 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-2 col-sm-6 mb-2">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Com erro</small><h4 class="mb-0 text-danger"><?= number_format((int) $omniLogsSummary['error'], 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-2">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Nao instalados</small><h4 class="mb-0 text-warning"><?= number_format((int) $omniLogsSummary['not_installed'], 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-2">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Config Loki ausente</small><h4 class="mb-0 text-body-secondary"><?= number_format((int) $omniLogsSummary['config_missing'], 0, ',', '.') ?></h4></div></div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><strong>Filtros</strong></div>
    <div class="card-body">
      <form method="get" action="/servers.php" class="row g-2">
        <div class="col-lg-5">
          <label class="form-label mb-1">Buscar nome/IP</label>
          <input type="text" class="form-control" name="q" value="<?= htmlspecialchars((string) ($_GET['q'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="ex: web-01 ou 1.2.3.4">
        </div>
        <div class="col-lg-3">
          <label class="form-label mb-1">Status</label>
          <select name="status" class="form-select">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Todos</option>
            <option value="running" <?= $statusFilter === 'running' ? 'selected' : '' ?>>Running</option>
            <option value="off" <?= $statusFilter === 'off' ? 'selected' : '' ?>>Off</option>
            <option value="initializing" <?= $statusFilter === 'initializing' ? 'selected' : '' ?>>Initializing</option>
            <option value="unknown" <?= $statusFilter === 'unknown' ? 'selected' : '' ?>>Unknown</option>
            <option value="error" <?= $statusFilter === 'error' ? 'selected' : '' ?>>Error</option>
          </select>
        </div>
        <div class="col-lg-4 d-flex align-items-end gap-2">
          <button type="submit" class="btn btn-primary">Aplicar</button>
          <a href="/servers.php" class="btn btn-outline-secondary">Limpar</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Lista de servidores do fornecedor</strong>
      <small class="text-body-secondary"><?= number_format(count($filteredServers), 0, ',', '.') ?> resultado(s)</small>
    </div>
    <div class="card-body p-0">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Status</th>
            <th>Tipo</th>
            <th>OS</th>
            <th>Regiao</th>
            <th>IPv4</th>
            <th>CPU</th>
            <th>RAM</th>
            <th>Disco</th>
            <th>Conta Hetzner</th>
            <th>OmniLogs</th>
            <th>Ultima coleta</th>
            <th>Acoes</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($filteredServers === []): ?>
            <tr>
              <td colspan="13" class="text-center text-body-secondary py-4">
                Nenhum servidor sincronizado para este fornecedor.
                <div class="mt-2"><a href="/hetzner.php" class="btn btn-sm btn-primary">Ir para Hetzner</a></div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($filteredServers as $server): ?>
              <?php $metrics = hetzner_server_metrics($server); ?>
              <?php
                $serverId = (int) ($server['id'] ?? 0);
                $latestInstall = $serverId > 0 ? ($omniLogsInstallStatus[$serverId] ?? null) : null;
                $installStatus = strtolower((string) ($latestInstall['status'] ?? ''));
                if (!$lokiConfigReady) {
                    $omniLogsLabel = 'Config ausente';
                    $omniLogsBadge = 'bg-secondary';
                    $omniLogsHint = 'Configure Loki no modulo Observabilidade para habilitar coleta.';
                } elseif ($installStatus === 'success') {
                    $omniLogsLabel = 'Ativo';
                    $omniLogsBadge = 'bg-success';
                    $omniLogsHint = 'Ultima instalacao concluida: ' . ((string) ($latestInstall['finished_at'] ?? '') !== '' ? (string) $latestInstall['finished_at'] : 'n/d');
                } elseif ($installStatus === 'running') {
                    $omniLogsLabel = 'Instalando';
                    $omniLogsBadge = 'bg-info text-dark';
                    $omniLogsHint = 'Instalacao em andamento.';
                } elseif ($installStatus === 'error') {
                    $omniLogsLabel = 'Erro';
                    $omniLogsBadge = 'bg-danger';
                    $omniLogsHint = (string) ($latestInstall['message'] ?? 'Falha na ultima instalacao.');
                } else {
                    $omniLogsLabel = 'Nao instalado';
                    $omniLogsBadge = 'bg-warning text-dark';
                    $omniLogsHint = 'Instale no detalhe do servidor (aba Services).';
                }
              ?>
              <tr>
                <td><?= htmlspecialchars((string) $server['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) $server['status'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($metrics['server_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($metrics['os_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($server['datacenter'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($server['ipv4'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= is_int($metrics['cpu_cores']) ? number_format($metrics['cpu_cores'], 0, ',', '.') . 'c' : '-' ?></td>
                <td><?= is_float($metrics['memory_gb']) ? number_format($metrics['memory_gb'], 1, ',', '.') . ' GB' : '-' ?></td>
                <td><?= is_float($metrics['disk_gb']) ? number_format($metrics['disk_gb'], 0, ',', '.') . ' GB' : '-' ?></td>
                <td><?= htmlspecialchars((string) $server['account_label'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <span class="badge <?= $omniLogsBadge ?>" title="<?= htmlspecialchars($omniLogsHint, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($omniLogsLabel, ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td><?= htmlspecialchars((string) $server['last_seen_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <a href="/server_details.php?id=<?= (int) $server['id'] ?>" class="btn btn-sm btn-outline-primary">Abrir detalhes</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php
ui_page_end();
