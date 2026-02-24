<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/hetzner.php';
require_once __DIR__ . '/../app/tenancy.php';
require_once __DIR__ . '/../app/ui.php';

require_auth();
$user = current_user();
if ($user === null) {
    redirect('/login.php');
}
$userId = (int) $user['id'];

$projectId = (int) ($_GET['id'] ?? 0);
if ($projectId <= 0) {
    flash_set('warning', 'Fornecedor invalido.');
    redirect('/projects.php');
}

$project = get_project_for_user($userId, $projectId);
if ($project === null) {
    flash_set('danger', 'Fornecedor nao encontrado ou sem acesso.');
    redirect('/projects.php');
}

set_user_context($userId, (int) $project['company_id'], (int) $project['id']);

$context = load_user_context($userId);
$flash = flash_pull();
$companyId = (int) $project['company_id'];
$projectId = (int) $project['id'];
$providerType = infer_provider_type_from_project($project);
$providerLabel = $providerType !== null ? ucfirst($providerType) : 'Fornecedor';

$providerLanding = '/projects.php';
$providerDashboard = '/';
$integrationCreateLink = '/projects.php';
$integrationDetailsPrefix = null;

if ($providerType === 'hetzner') {
    $providerLanding = '/hetzner.php';
    $providerDashboard = '/hetzner_dashboard.php';
    $integrationCreateLink = '/hetzner_account_create.php';
    $integrationDetailsPrefix = '/hetzner_account_details.php?id=';
}

if ($providerType === 'proxmox') {
    $providerLanding = '/proxmox.php';
    $providerDashboard = '/proxmox.php';
    $integrationCreateLink = '/proxmox.php';
}

$servers = [];
$accounts = [];
if ($providerType === 'hetzner') {
    $servers = list_project_servers($companyId, $projectId);
    $accounts = list_hetzner_accounts($companyId, $projectId);
}

$runningServers = 0;
$degradedServers = 0;
foreach ($servers as $server) {
    $status = strtolower((string) ($server['status'] ?? 'unknown'));
    if ($status === 'running') {
        $runningServers++;
        continue;
    }

    if ($status !== 'running' && $status !== '') {
        $degradedServers++;
    }
}

$jobsLast24hStmt = db()->prepare(
    "SELECT COUNT(*)
     FROM job_runs
     WHERE company_id = :company_id
       AND project_id = :project_id
       AND started_at >= NOW() - INTERVAL '24 hours'"
);
$jobsLast24hStmt->execute([
    'company_id' => $companyId,
    'project_id' => $projectId,
]);
$jobsLast24h = (int) $jobsLast24hStmt->fetchColumn();

$recentEventsStmt = db()->prepare(
    'SELECT action, target_type, target_id, created_at
     FROM audit_events
     WHERE company_id = :company_id
       AND project_id = :project_id
     ORDER BY created_at DESC
     LIMIT 8'
);
$recentEventsStmt->execute([
    'company_id' => $companyId,
    'project_id' => $projectId,
]);
$recentEvents = $recentEventsStmt->fetchAll();

$capabilities = [];
$rawCapabilities = $project['capabilities'] ?? [];
if (is_string($rawCapabilities) && trim($rawCapabilities) !== '') {
    $decoded = json_decode($rawCapabilities, true);
    if (is_array($decoded)) {
        $capabilities = $decoded;
    }
} elseif (is_array($rawCapabilities)) {
    $capabilities = $rawCapabilities;
}

$tab = (string) ($_GET['tab'] ?? 'overview');
$allowedTabs = ['overview', 'servers', 'integrations', 'audit', 'settings'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'overview';
}

ui_page_start('OmniNOC | Fornecedor');
ui_navigation('projects', $user, $context, $flash);
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h3 class="mb-1">Servico: <?= htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') ?></h3>
    <small class="text-body-secondary">
      Tenant <?= htmlspecialchars((string) $project['company_name'], ENT_QUOTES, 'UTF-8') ?>
      | vinculo <?= htmlspecialchars((string) $project['slug'], ENT_QUOTES, 'UTF-8') ?>
    </small>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= htmlspecialchars($providerDashboard, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">Dashboard</a>
    <a href="/servers.php" class="btn btn-outline-secondary">Servidores</a>
    <?php if ($providerType === 'hetzner'): ?>
      <?php if (is_platform_owner($user)): ?>
        <a href="/hetzner_operations.php" class="btn btn-outline-secondary">API Explorer</a>
      <?php endif; ?>
    <?php endif; ?>
    <a href="<?= htmlspecialchars($providerLanding, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">Contas API</a>
  </div>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'overview' ? 'active' : '' ?>" href="/project_details.php?id=<?= $projectId ?>&tab=overview">Overview</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'servers' ? 'active' : '' ?>" href="/project_details.php?id=<?= $projectId ?>&tab=servers">Servidores</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'integrations' ? 'active' : '' ?>" href="/project_details.php?id=<?= $projectId ?>&tab=integrations">Contas API</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'audit' ? 'active' : '' ?>" href="/project_details.php?id=<?= $projectId ?>&tab=audit">Auditoria</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'settings' ? 'active' : '' ?>" href="/project_details.php?id=<?= $projectId ?>&tab=settings">Config</a></li>
</ul>

<?php if ($tab === 'overview'): ?>
  <div class="row">
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Servidores online</small><h4 class="mb-0"><?= $runningServers ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Servidores degradados</small><h4 class="mb-0"><?= $degradedServers ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Contas <?= htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') ?></small><h4 class="mb-0"><?= count($accounts) ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Jobs (24h)</small><h4 class="mb-0"><?= $jobsLast24h ?></h4></div></div>
    </div>
  </div>

  <div class="row">
    <div class="col-lg-7 mb-3">
      <div class="card">
        <div class="card-header"><strong>Top issues do fornecedor</strong></div>
        <div class="card-body p-0">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Tipo</th>
                <th>Recurso</th>
                <th>Status</th>
                <th>Acao</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $issues = array_filter(
                  $servers,
                  static fn (array $server): bool => strtolower((string) ($server['status'] ?? '')) !== 'running'
              );
              ?>
              <?php if ($providerType !== 'hetzner'): ?>
                <tr>
                  <td colspan="4" class="text-center text-body-secondary py-4">Top issues sera ativado neste fornecedor quando a integracao de inventario estiver pronta.</td>
                </tr>
              <?php elseif ($issues === []): ?>
                <tr>
                  <td colspan="4" class="text-center text-body-secondary py-4">Nenhum issue ativo no momento.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($issues as $issue): ?>
                  <tr>
                    <td>server</td>
                    <td><?= htmlspecialchars((string) $issue['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $issue['status'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><a href="/server_details.php?id=<?= (int) $issue['id'] ?>" class="btn btn-sm btn-outline-primary">Abrir</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-lg-5 mb-3">
      <div class="card">
        <div class="card-header"><strong>Timeline recente</strong></div>
        <div class="card-body">
          <?php if ($recentEvents === []): ?>
            <p class="text-body-secondary mb-0">Sem eventos de auditoria para este fornecedor.</p>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recentEvents as $event): ?>
                <li class="list-group-item px-0">
                  <div class="d-flex justify-content-between">
                    <strong><?= htmlspecialchars((string) $event['action'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <small class="text-body-secondary"><?= htmlspecialchars((string) $event['created_at'], ENT_QUOTES, 'UTF-8') ?></small>
                  </div>
                  <small class="text-body-secondary">
                    <?= htmlspecialchars((string) $event['target_type'], ENT_QUOTES, 'UTF-8') ?>#<?= htmlspecialchars((string) $event['target_id'], ENT_QUOTES, 'UTF-8') ?>
                  </small>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'servers'): ?>
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Servidores do fornecedor</strong>
      <a href="<?= htmlspecialchars($providerLanding, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary">Abrir modulo do fornecedor</a>
    </div>
    <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Status</th>
            <th>Regiao</th>
            <th>IPv4</th>
            <th>Acoes</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($providerType !== 'hetzner'): ?>
            <tr><td colspan="5" class="text-center text-body-secondary py-4">Inventario de servidores para <?= htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') ?> em implementacao.</td></tr>
          <?php elseif ($servers === []): ?>
            <tr><td colspan="5" class="text-center text-body-secondary py-4">Nenhum servidor sincronizado.</td></tr>
          <?php else: ?>
            <?php foreach ($servers as $server): ?>
              <tr>
                <td><?= htmlspecialchars((string) $server['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) $server['status'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($server['datacenter'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($server['ipv4'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><a href="/server_details.php?id=<?= (int) $server['id'] ?>" class="btn btn-sm btn-outline-primary">Abrir detalhes</a></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'integrations'): ?>
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Contas API do servico</strong>
      <a href="<?= htmlspecialchars($integrationCreateLink, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary">Conectar conta API</a>
    </div>
    <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Provider</th>
            <th>Label</th>
            <th>Status</th>
            <th>Ultimo sync</th>
            <th>Acoes</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($providerType !== 'hetzner'): ?>
            <tr><td colspan="5" class="text-center text-body-secondary py-4">Integracoes de conta para <?= htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') ?> em implementacao.</td></tr>
          <?php elseif ($accounts === []): ?>
            <tr><td colspan="5" class="text-center text-body-secondary py-4">Nenhuma integracao cadastrada.</td></tr>
          <?php else: ?>
            <?php foreach ($accounts as $account): ?>
              <tr>
                <td><?= htmlspecialchars((string) ($providerType ?? 'provider'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) $account['label'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) $account['status'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($account['last_synced_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <?php if ($integrationDetailsPrefix !== null): ?>
                    <a href="<?= htmlspecialchars($integrationDetailsPrefix, ENT_QUOTES, 'UTF-8') ?><?= (int) $account['id'] ?>" class="btn btn-sm btn-outline-primary">Abrir detalhes</a>
                  <?php else: ?>
                    <span class="text-body-secondary">Em breve</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'audit'): ?>
  <div class="card">
    <div class="card-header"><strong>Auditoria do fornecedor</strong></div>
    <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Acao</th>
            <th>Target</th>
            <th>Quando</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($recentEvents === []): ?>
            <tr><td colspan="3" class="text-center text-body-secondary py-4">Sem eventos registrados.</td></tr>
          <?php else: ?>
            <?php foreach ($recentEvents as $event): ?>
              <tr>
                <td><?= htmlspecialchars((string) $event['action'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) $event['target_type'], ENT_QUOTES, 'UTF-8') ?>#<?= htmlspecialchars((string) $event['target_id'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) $event['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'settings'): ?>
  <div class="row">
    <div class="col-lg-6 mb-3">
      <div class="card h-100">
        <div class="card-header"><strong>Contexto de gestao</strong></div>
        <div class="card-body">
          <dl class="mb-0">
            <dt>Management API</dt>
            <dd><?= htmlspecialchars((string) ($project['management_api_base_url'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>Status</dt>
            <dd><?= htmlspecialchars((string) ($project['status'] ?? 'active'), ENT_QUOTES, 'UTF-8') ?></dd>
          </dl>
        </div>
      </div>
    </div>

    <div class="col-lg-6 mb-3">
      <div class="card h-100">
        <div class="card-header"><strong>Capabilities</strong></div>
        <div class="card-body p-0">
          <table class="table mb-0">
            <thead><tr><th>Capability</th><th>Ativo</th></tr></thead>
            <tbody>
              <?php if ($capabilities === []): ?>
                <tr><td colspan="2" class="text-center text-body-secondary py-4">Sem capabilities configuradas.</td></tr>
              <?php else: ?>
                <?php foreach ($capabilities as $key => $value): ?>
                  <tr>
                    <td><?= htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $value ? 'sim' : 'nao' ?></td>
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
<?php
ui_page_end();
