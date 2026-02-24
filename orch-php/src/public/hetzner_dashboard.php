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

$context = load_user_context($userId);
$companyId = $context['company_id'];
$projectId = $context['project_id'];
$providerType = context_provider_type($context);
$canManage = is_int($companyId) ? user_can_manage_company_context($userId, $companyId, $user) : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        flash_set('danger', 'CSRF invalido.');
        redirect('/hetzner_dashboard.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'sync_all_accounts') {
        if (!$canManage) {
            flash_set('danger', 'Seu usuario esta em modo leitura para esta empresa.');
            redirect('/hetzner_dashboard.php');
        }
        if (!is_int($companyId) || !is_int($projectId) || $providerType !== 'hetzner') {
            flash_set('warning', 'Selecione um fornecedor Hetzner para sincronizar.');
            redirect('/projects.php');
        }

        $accountsToSync = list_hetzner_accounts($companyId, $projectId);
        if ($accountsToSync === []) {
            flash_set('warning', 'Nenhuma conta Hetzner cadastrada para sincronizar.');
            redirect('/hetzner.php');
        }

        $okCount = 0;
        $errorCount = 0;
        $serverCount = 0;
        foreach ($accountsToSync as $accountToSync) {
            $result = sync_hetzner_servers($companyId, $projectId, (int) $accountToSync['id'], $userId);
            $serverCount += (int) ($result['count'] ?? 0);
            if ((bool) ($result['ok'] ?? false)) {
                $okCount++;
            } else {
                $errorCount++;
            }
        }

        if ($errorCount > 0) {
            flash_set(
                'warning',
                'Sync finalizado com alertas. Contas OK: ' . $okCount . ' | Contas com erro: ' . $errorCount . ' | Servidores processados: ' . $serverCount
            );
        } else {
            flash_set(
                'success',
                'Sync concluido. Contas sincronizadas: ' . $okCount . ' | Servidores processados: ' . $serverCount
            );
        }
        redirect('/hetzner_dashboard.php');
    }

    if ($action === 'sync_inventory_all') {
        if (!$canManage) {
            flash_set('danger', 'Seu usuario esta em modo leitura para esta empresa.');
            redirect('/hetzner_dashboard.php');
        }
        if (!is_int($companyId) || !is_int($projectId) || $providerType !== 'hetzner') {
            flash_set('warning', 'Selecione um fornecedor Hetzner para coletar inventario.');
            redirect('/projects.php');
        }

        $accountsToSync = list_hetzner_accounts($companyId, $projectId);
        if ($accountsToSync === []) {
            flash_set('warning', 'Nenhuma conta Hetzner cadastrada para coleta.');
            redirect('/hetzner.php');
        }

        $okCount = 0;
        $errorCount = 0;
        $totalAssets = 0;
        $byType = [];

        foreach ($accountsToSync as $accountToSync) {
            $result = sync_hetzner_inventory($companyId, $projectId, (int) $accountToSync['id'], $userId);
            $totalAssets += (int) ($result['total'] ?? 0);
            if ((bool) ($result['ok'] ?? false)) {
                $okCount++;
            } else {
                $errorCount++;
            }

            $localByType = $result['by_type'] ?? [];
            if (is_array($localByType)) {
                foreach ($localByType as $type => $count) {
                    if (!array_key_exists($type, $byType)) {
                        $byType[$type] = 0;
                    }
                    $byType[$type] += (int) $count;
                }
            }
        }

        $parts = [];
        foreach ($byType as $type => $count) {
            $parts[] = $type . ':' . $count;
        }
        $suffix = $parts === [] ? '' : ' | Tipos: ' . implode(', ', $parts);

        if ($errorCount > 0) {
            flash_set(
                'warning',
                'Inventario finalizado com alertas. Contas OK: ' . $okCount . ' | Contas com erro: ' . $errorCount . ' | Recursos coletados: ' . $totalAssets . $suffix
            );
        } else {
            flash_set(
                'success',
                'Inventario concluido. Contas processadas: ' . $okCount . ' | Recursos coletados: ' . $totalAssets . $suffix
            );
        }
        redirect('/hetzner_dashboard.php');
    }
}

$flash = flash_pull();

$accounts = [];
$servers = [];
$assets = [];
$jobs = [];
if (is_int($companyId) && is_int($projectId) && $providerType === 'hetzner') {
    $accounts = list_hetzner_accounts($companyId, $projectId);
    $servers = list_project_servers($companyId, $projectId);
    $assets = list_project_assets($companyId, $projectId);

    $jobsStmt = db()->prepare(
        "SELECT jr.id,
                jr.status,
                jr.message,
                jr.started_at,
                jr.finished_at,
                jr.meta_json,
                pa.id AS account_id,
                pa.label AS account_label
         FROM job_runs jr
         LEFT JOIN provider_accounts pa
            ON pa.id = NULLIF(jr.meta_json->>'account_id', '')::bigint
           AND pa.company_id = jr.company_id
           AND pa.project_id = jr.project_id
         WHERE jr.company_id = :company_id
           AND jr.project_id = :project_id
           AND jr.job_type IN ('hetzner.sync_servers', 'hetzner.sync_inventory')
         ORDER BY jr.started_at DESC
         LIMIT 25"
    );
    $jobsStmt->execute([
        'company_id' => $companyId,
        'project_id' => $projectId,
    ]);
    $jobs = $jobsStmt->fetchAll();
}

$accountsTotal = count($accounts);
$accountsActive = 0;
$accountsCritical = 0;
foreach ($accounts as $account) {
    $status = strtolower((string) ($account['status'] ?? ''));
    if ($status === 'active') {
        $accountsActive++;
    }
    if (in_array($status, ['error', 'invalid'], true)) {
        $accountsCritical++;
    }
}

$serversTotal = count($servers);
$serversHealthy = 0;
$serversCritical = 0;
foreach ($servers as $server) {
    $status = strtolower((string) ($server['status'] ?? ''));
    if (in_array($status, ['running', 'ok', 'active', 'healthy'], true)) {
        $serversHealthy++;
    } else {
        $serversCritical++;
    }
}

$jobs24hSuccess = 0;
$jobs24hError = 0;
$accountsStaleSync = 0;
$now = time();
foreach ($jobs as $job) {
    $startedAt = strtotime((string) ($job['started_at'] ?? ''));
    if ($startedAt === false) {
        continue;
    }
    if (($now - $startedAt) > 86400) {
        continue;
    }
    $status = strtolower((string) ($job['status'] ?? ''));
    if ($status === 'success') {
        $jobs24hSuccess++;
    }
    if ($status === 'error') {
        $jobs24hError++;
    }
}
foreach ($accounts as $account) {
    $lastSync = strtotime((string) ($account['last_synced_at'] ?? ''));
    if ($lastSync === false || ($now - $lastSync) > 86400) {
        $accountsStaleSync++;
    }
}

$capacity = summarize_hetzner_capacity($servers);
$assetSummary = summarize_project_assets($assets);
$assetsTotal = count($assets);

$issues = [];
foreach ($accounts as $account) {
    $status = strtolower((string) ($account['status'] ?? ''));
    if (!in_array($status, ['error', 'invalid'], true)) {
        continue;
    }
    $issues[] = [
        'type' => 'conta',
        'resource' => (string) $account['label'],
        'severity' => 'alta',
        'last_event' => (string) ($account['last_tested_at'] ?? '-'),
        'action_url' => '/hetzner_account_details.php?id=' . (int) $account['id'],
        'action_label' => 'Abrir conta',
    ];
}
foreach ($servers as $server) {
    $status = strtolower((string) ($server['status'] ?? ''));
    if (in_array($status, ['running', 'ok', 'active', 'healthy'], true)) {
        continue;
    }
    $issues[] = [
        'type' => 'servidor',
        'resource' => (string) $server['name'],
        'severity' => 'media',
        'last_event' => (string) ($server['last_seen_at'] ?? '-'),
        'action_url' => '/server_details.php?id=' . (int) $server['id'],
        'action_label' => 'Abrir servidor',
    ];
}
foreach ($jobs as $job) {
    $status = strtolower((string) ($job['status'] ?? ''));
    if ($status !== 'error') {
        continue;
    }
    $accountId = (int) ($job['account_id'] ?? 0);
    $issues[] = [
        'type' => 'job',
        'resource' => 'Sync #' . (int) $job['id'] . (($job['account_label'] ?? null) ? ' (' . (string) $job['account_label'] . ')' : ''),
        'severity' => 'media',
        'last_event' => (string) ($job['started_at'] ?? '-'),
        'action_url' => $accountId > 0
            ? '/hetzner_account_details.php?id=' . $accountId . '&tab=jobs'
            : '/hetzner.php',
        'action_label' => 'Abrir jobs',
    ];
}
$issues = array_slice($issues, 0, 12);

ui_page_start('OmniNOC | Dashboard Hetzner');
ui_navigation('dashboard', $user, $context, $flash);
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h3 class="mb-1">Dashboard Hetzner</h3>
    <small class="text-body-secondary">Visao consolidada de contas, servidores e sincronizacoes do fornecedor selecionado.</small>
  </div>
  <div class="d-flex gap-2">
    <?php if ($canManage): ?>
      <a href="/hetzner_account_create.php" class="btn btn-outline-secondary">Conectar conta</a>
    <?php else: ?>
      <button type="button" class="btn btn-outline-secondary" disabled title="Sem permissao de escrita para esta empresa">Conectar conta</button>
    <?php endif; ?>
    <?php if (is_platform_owner($user)): ?>
      <a href="/hetzner_operations.php" class="btn btn-outline-secondary">API Explorer</a>
    <?php endif; ?>
    <a href="/hetzner_jobs.php" class="btn btn-outline-secondary">Jobs</a>
    <a href="/servers.php" class="btn btn-outline-secondary">Abrir servidores</a>
    <?php if ($canManage): ?>
      <form method="post" action="/hetzner_dashboard.php" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="sync_all_accounts">
        <button type="submit" class="btn btn-primary">Sincronizar tudo</button>
      </form>
      <form method="post" action="/hetzner_dashboard.php" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="sync_inventory_all">
        <button type="submit" class="btn btn-outline-primary">Coletar inventario</button>
      </form>
    <?php else: ?>
      <button type="button" class="btn btn-primary" disabled title="Sem permissao de escrita para esta empresa">Sincronizar tudo</button>
      <button type="button" class="btn btn-outline-primary" disabled title="Sem permissao de escrita para esta empresa">Coletar inventario</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($companyId === null || $projectId === null): ?>
  <div class="alert alert-warning">Selecione empresa e fornecedor no topo para abrir o dashboard Hetzner.</div>
<?php elseif ($providerType !== 'hetzner'): ?>
  <div class="alert alert-warning">O fornecedor atual nao e do tipo Hetzner. Altere o contexto para continuar.</div>
<?php else: ?>
  <?php if (!$canManage): ?>
    <div class="alert alert-info">Acesso em modo leitura para este contexto. Operacoes de sincronizacao e alteracoes foram bloqueadas.</div>
  <?php endif; ?>
  <div class="row">
    <div class="col-lg-3 col-12">
      <div class="small-box text-bg-primary">
        <div class="inner">
          <h3><?= number_format($accountsTotal, 0, ',', '.') ?></h3>
          <p>Contas Hetzner</p>
          <small><?= number_format($accountsActive, 0, ',', '.') ?> ativas</small>
        </div>
        <i class="small-box-icon bi bi-key"></i>
      </div>
    </div>
    <div class="col-lg-3 col-12">
      <div class="small-box <?= $accountsCritical > 0 ? 'text-bg-danger' : 'text-bg-success' ?>">
        <div class="inner">
          <h3><?= number_format($accountsCritical, 0, ',', '.') ?></h3>
          <p>Contas criticas</p>
          <small>invalid/error</small>
        </div>
        <i class="small-box-icon bi bi-exclamation-triangle"></i>
      </div>
    </div>
    <div class="col-lg-3 col-12">
      <div class="small-box <?= $serversCritical > 0 ? 'text-bg-warning' : 'text-bg-info' ?>">
        <div class="inner">
          <h3><?= number_format($serversTotal, 0, ',', '.') ?></h3>
          <p>Servidores</p>
          <small><?= number_format($serversHealthy, 0, ',', '.') ?> saudaveis / <?= number_format($serversCritical, 0, ',', '.') ?> criticos</small>
        </div>
        <i class="small-box-icon bi bi-server"></i>
      </div>
    </div>
    <div class="col-lg-3 col-12">
      <div class="small-box <?= $accountsStaleSync > 0 ? 'text-bg-warning' : 'text-bg-success' ?>">
        <div class="inner">
          <h3><?= number_format($accountsStaleSync, 0, ',', '.') ?></h3>
          <p>Sync desatualizado</p>
          <small>contas sem sync nas ultimas 24h</small>
        </div>
        <i class="small-box-icon bi bi-arrow-repeat"></i>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><strong>Capacidade coletada</strong></div>
    <div class="card-body">
      <div class="row">
        <div class="col-lg-3 col-sm-6 mb-2">
          <small class="text-body-secondary d-block">Servidores ativos</small>
          <strong><?= number_format((int) ($capacity['servers_running'] ?? 0), 0, ',', '.') ?> / <?= number_format((int) ($capacity['servers_total'] ?? 0), 0, ',', '.') ?></strong>
        </div>
        <div class="col-lg-3 col-sm-6 mb-2">
          <small class="text-body-secondary d-block">CPU total (cores)</small>
          <strong><?= number_format((int) ($capacity['cpu_total'] ?? 0), 0, ',', '.') ?></strong>
        </div>
        <div class="col-lg-3 col-sm-6 mb-2">
          <small class="text-body-secondary d-block">RAM total (GB)</small>
          <strong><?= number_format((float) ($capacity['memory_total_gb'] ?? 0), 1, ',', '.') ?></strong>
        </div>
        <div class="col-lg-3 col-sm-6 mb-2">
          <small class="text-body-secondary d-block">Disco total (GB)</small>
          <strong><?= number_format((float) ($capacity['disk_total_gb'] ?? 0), 1, ',', '.') ?></strong>
        </div>
      </div>
      <small class="text-body-secondary">Valores derivados da ultima coleta de inventario por servidor.</small>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><strong>Inventario por tipo</strong></div>
    <div class="card-body">
      <?php if ($assetSummary === []): ?>
        <p class="mb-0 text-body-secondary">Ainda sem inventario coletado. Use "Coletar inventario".</p>
      <?php else: ?>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <?php foreach ($assetSummary as $type => $count): ?>
            <span class="badge text-bg-info"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>: <?= number_format((int) $count, 0, ',', '.') ?></span>
          <?php endforeach; ?>
        </div>
        <small class="text-body-secondary">Total de recursos inventariados: <?= number_format($assetsTotal, 0, ',', '.') ?></small>
      <?php endif; ?>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><strong>Top Issues</strong></div>
    <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Tipo</th>
            <th>Recurso</th>
            <th>Severidade</th>
            <th>Ultimo evento</th>
            <th>Acoes</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($issues === []): ?>
            <tr>
              <td colspan="5" class="text-center text-success py-4">Sem incidentes criticos no fornecedor Hetzner.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($issues as $issue): ?>
              <?php
              $severity = (string) ($issue['severity'] ?? 'baixa');
              $severityClass = 'text-bg-success';
              if ($severity === 'alta') {
                  $severityClass = 'text-bg-danger';
              } elseif ($severity === 'media') {
                  $severityClass = 'text-bg-warning';
              }
              ?>
              <tr>
                <td><code><?= htmlspecialchars((string) ($issue['type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars((string) ($issue['resource'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="badge <?= $severityClass ?>"><?= htmlspecialchars($severity, ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars((string) ($issue['last_event'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><a href="<?= htmlspecialchars((string) ($issue['action_url'] ?? '/hetzner.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-primary"><?= htmlspecialchars((string) ($issue['action_label'] ?? 'Abrir'), ENT_QUOTES, 'UTF-8') ?></a></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="row">
    <div class="col-lg-12 mb-3">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Servidores do fornecedor</strong>
          <small class="text-body-secondary">Exibindo ate 15 servidores</small>
        </div>
        <div class="card-body p-0">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Nome</th>
                <th>Status</th>
                <th>Regiao</th>
                <th>IPv4</th>
                <th>CPU</th>
                <th>RAM</th>
                <th>Disco</th>
                <th>Conta</th>
                <th>Acoes</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($servers === []): ?>
                <tr>
                  <td colspan="9" class="text-center text-body-secondary py-4">Sem servidores sincronizados.</td>
                </tr>
              <?php else: ?>
                <?php foreach (array_slice($servers, 0, 15) as $server): ?>
                  <?php $metrics = hetzner_server_metrics($server); ?>
                  <tr>
                    <td><?= htmlspecialchars((string) $server['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $server['status'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($server['datacenter'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($server['ipv4'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= is_int($metrics['cpu_cores']) ? number_format($metrics['cpu_cores'], 0, ',', '.') . 'c' : '-' ?></td>
                    <td><?= is_float($metrics['memory_gb']) ? number_format($metrics['memory_gb'], 1, ',', '.') . ' GB' : '-' ?></td>
                    <td><?= is_float($metrics['disk_gb']) ? number_format($metrics['disk_gb'], 0, ',', '.') . ' GB' : '-' ?></td>
                    <td><?= htmlspecialchars((string) ($server['account_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><a href="/server_details.php?id=<?= (int) $server['id'] ?>" class="btn btn-sm btn-outline-primary">Abrir</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Resumo de jobs (24h)</strong>
      <a href="/hetzner_jobs.php" class="btn btn-sm btn-outline-secondary">Abrir timeline completa</a>
    </div>
    <div class="card-body">
      <div class="row">
        <div class="col-md-4 mb-2">
          <small class="text-body-secondary d-block">Success (24h)</small>
          <strong><?= number_format($jobs24hSuccess, 0, ',', '.') ?></strong>
        </div>
        <div class="col-md-4 mb-2">
          <small class="text-body-secondary d-block">Error (24h)</small>
          <strong><?= number_format($jobs24hError, 0, ',', '.') ?></strong>
        </div>
        <div class="col-md-4 mb-2">
          <small class="text-body-secondary d-block">Ultimo job</small>
          <strong><?= htmlspecialchars((string) (($jobs[0]['started_at'] ?? 'n/a')), ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php
ui_page_end();
