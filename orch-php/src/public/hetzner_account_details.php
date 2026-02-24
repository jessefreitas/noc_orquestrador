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

if (!is_int($companyId) || !is_int($projectId) || $providerType !== 'hetzner') {
    flash_set('warning', 'Selecione um fornecedor antes de abrir detalhes do projeto Hetzner.');
    redirect('/projects.php');
}

$accountId = (int) ($_GET['id'] ?? $_POST['account_id'] ?? 0);
if ($accountId <= 0) {
    flash_set('warning', 'Projeto Hetzner invalido.');
    redirect('/hetzner.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    $allowedRedirectTabs = ['overview', 'servers', 'assets', 'jobs'];
    $redirectTab = strtolower(trim((string) ($_POST['redirect_tab'] ?? 'overview')));
    if (!in_array($redirectTab, $allowedRedirectTabs, true)) {
        $redirectTab = 'overview';
    }
    $redirectUrl = '/hetzner_account_details.php?id=' . $accountId . '&tab=' . $redirectTab;
    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        flash_set('danger', 'CSRF invalido.');
        redirect($redirectUrl);
    }

    $action = (string) ($_POST['action'] ?? '');
    if (!$canManage) {
        flash_set('danger', 'Seu usuario esta em modo leitura para esta empresa.');
        redirect($redirectUrl);
    }
    if ($action === 'test_account') {
        $result = test_hetzner_account($companyId, $projectId, $accountId, $userId);
        flash_set($result['ok'] ? 'success' : 'danger', (string) $result['message']);
    }

    if ($action === 'sync_servers') {
        $result = sync_hetzner_servers($companyId, $projectId, $accountId, $userId);
        flash_set($result['ok'] ? 'success' : 'danger', (string) $result['message']);
    }

    if ($action === 'sync_inventory') {
        $result = sync_hetzner_inventory($companyId, $projectId, $accountId, $userId);
        $byType = $result['by_type'] ?? [];
        if (is_array($byType) && $byType !== []) {
            $parts = [];
            foreach ($byType as $type => $count) {
                $parts[] = $type . ':' . (int) $count;
            }
            $message = (string) $result['message'] . ' (' . implode(', ', $parts) . ')';
        } else {
            $message = (string) $result['message'];
        }
        flash_set($result['ok'] ? 'success' : 'danger', $message);
    }

    if ($action === 'update_account_profile') {
        $label = (string) ($_POST['label'] ?? '');
        $token = (string) ($_POST['token'] ?? '');
        update_hetzner_account($companyId, $projectId, $accountId, $userId, $label, $token);
        $tokenInfo = trim($token) !== '' ? ' Token atualizado.' : ' Token mantido.';
        flash_set('success', 'Projeto Hetzner atualizado com sucesso.' . $tokenInfo);
    }

    if ($action === 'migrate_servers') {
        $targetAccountId = (int) ($_POST['target_account_id'] ?? 0);
        $scope = strtolower(trim((string) ($_POST['migrate_scope'] ?? 'selected')));
        $serverIds = [];
        if ($scope !== 'all') {
            $postedIds = $_POST['server_ids'] ?? [];
            if (is_array($postedIds)) {
                foreach ($postedIds as $rawId) {
                    $id = (int) $rawId;
                    if ($id > 0) {
                        $serverIds[$id] = $id;
                    }
                }
            }
        }

        if ($targetAccountId <= 0) {
            flash_set('warning', 'Selecione uma conta/projeto de destino para migracao.');
        } elseif ($scope !== 'all' && $serverIds === []) {
            flash_set('warning', 'Selecione ao menos um servidor para migrar ou escolha o escopo "Todos".');
        } else {
            $result = migrate_hetzner_servers_between_accounts(
                $companyId,
                $projectId,
                $accountId,
                $targetAccountId,
                $userId,
                array_values($serverIds)
            );
            if (($result['ok'] ?? false) === true) {
                flash_set(
                    'success',
                    'Migracao concluida. Movidos: ' . (int) ($result['moved'] ?? 0) . ' | Ignorados: ' . (int) ($result['skipped'] ?? 0)
                );
            } else {
                flash_set('danger', (string) ($result['message'] ?? 'Falha ao migrar servidores.'));
            }
        }
    }

    if ($action === 'delete_server') {
        $serverId = (int) ($_POST['server_id'] ?? 0);
        $result = delete_hetzner_server_from_platform($companyId, $projectId, $accountId, $serverId, $userId);
        flash_set(($result['ok'] ?? false) ? 'success' : 'danger', (string) ($result['message'] ?? 'Falha ao remover servidor.'));
    }

    if ($action === 'delete_account') {
        $confirmText = trim((string) ($_POST['confirm_text'] ?? ''));
        if ($confirmText !== 'DELETAR') {
            flash_set('warning', 'Confirmacao invalida. Digite DELETAR para remover o projeto.');
        } else {
            $result = delete_hetzner_account($companyId, $projectId, $accountId, $userId);
            if (($result['ok'] ?? false) === true) {
                flash_set(
                    'success',
                    'Projeto removido da plataforma. Servidores removidos: ' . (int) ($result['deleted_servers'] ?? 0) . ' | Assets removidos: ' . (int) ($result['deleted_assets'] ?? 0)
                );
                redirect('/hetzner.php');
            }
            flash_set('danger', (string) ($result['message'] ?? 'Falha ao remover projeto.'));
        }
    }

    redirect($redirectUrl);
}

$account = get_hetzner_account($companyId, $projectId, $accountId);
if ($account === null) {
    flash_set('danger', 'Projeto Hetzner nao encontrado no fornecedor atual.');
    redirect('/hetzner.php');
}

$migrationTargets = list_hetzner_accounts_for_company($companyId, $accountId);

$serverStmt = db()->prepare(
    'SELECT id, name, status, datacenter, ipv4, last_seen_at
     FROM hetzner_servers
     WHERE company_id = :company_id
       AND project_id = :project_id
       AND provider_account_id = :account_id
     ORDER BY name'
);
$serverStmt->execute([
    'company_id' => $companyId,
    'project_id' => $projectId,
    'account_id' => $accountId,
]);
$servers = $serverStmt->fetchAll();

$jobsStmt = db()->prepare(
    "SELECT id, job_type, status, message, started_at, finished_at
     FROM job_runs
     WHERE company_id = :company_id
       AND project_id = :project_id
       AND job_type IN ('hetzner.sync_servers', 'hetzner.sync_inventory')
       AND (meta_json->>'account_id')::bigint = :account_id
     ORDER BY started_at DESC
     LIMIT 300"
);
$jobsStmt->execute([
    'company_id' => $companyId,
    'project_id' => $projectId,
    'account_id' => $accountId,
]);
$jobs = $jobsStmt->fetchAll();
if (!is_array($jobs)) {
    $jobs = [];
}

$flash = flash_pull();
$tab = (string) ($_GET['tab'] ?? 'overview');
$allowedTabs = ['overview', 'servers', 'assets', 'jobs'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'overview';
}
$nowTs = time();

$jobStatusFilter = strtolower(trim((string) ($_GET['job_status'] ?? 'all')));
if (!in_array($jobStatusFilter, ['all', 'success', 'error', 'running'], true)) {
    $jobStatusFilter = 'all';
}
$jobRangeFilter = strtolower(trim((string) ($_GET['job_range'] ?? '7d')));
if (!in_array($jobRangeFilter, ['24h', '7d', '30d', 'all'], true)) {
    $jobRangeFilter = '7d';
}
$jobQueryFilter = trim((string) ($_GET['job_q'] ?? ''));

$jobRangeSeconds = null;
if ($jobRangeFilter === '24h') {
    $jobRangeSeconds = 86400;
} elseif ($jobRangeFilter === '7d') {
    $jobRangeSeconds = 7 * 86400;
} elseif ($jobRangeFilter === '30d') {
    $jobRangeSeconds = 30 * 86400;
}

$jobsFiltered = [];
foreach ($jobs as $jobRow) {
    $status = strtolower(trim((string) ($jobRow['status'] ?? '')));
    if ($jobStatusFilter !== 'all' && $status !== $jobStatusFilter) {
        continue;
    }

    $startedAtRaw = (string) ($jobRow['started_at'] ?? '');
    $startedTs = strtotime($startedAtRaw);
    if ($jobRangeSeconds !== null && ($startedTs === false || ($nowTs - $startedTs) > $jobRangeSeconds)) {
        continue;
    }

    if ($jobQueryFilter !== '') {
        $needle = strtolower($jobQueryFilter);
        $haystack = strtolower(
            (string) ($jobRow['id'] ?? '')
            . ' '
            . (string) ($jobRow['status'] ?? '')
            . ' '
            . (string) ($jobRow['job_type'] ?? '')
            . ' '
            . (string) ($jobRow['message'] ?? '')
        );
        if (strpos($haystack, $needle) === false) {
            continue;
        }
    }

    $jobsFiltered[] = $jobRow;
}

$jobsFilteredTotal = count($jobsFiltered);
$jobsFilteredSuccess = 0;
$jobsFilteredError = 0;
$jobsFilteredRunning = 0;
foreach ($jobsFiltered as $jobRow) {
    $status = strtolower(trim((string) ($jobRow['status'] ?? '')));
    if ($status === 'success') {
        $jobsFilteredSuccess++;
    } elseif ($status === 'error') {
        $jobsFilteredError++;
    } elseif ($status === 'running') {
        $jobsFilteredRunning++;
    }
}

if ($tab === 'jobs') {
    $export = strtolower(trim((string) ($_GET['export'] ?? '')));
    if ($export === 'json') {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="hetzner-jobs-' . $accountId . '.json"');
        }
        echo json_encode($jobsFiltered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($export === 'csv') {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="hetzner-jobs-' . $accountId . '.csv"');
        }
        $out = fopen('php://output', 'w');
        if (is_resource($out)) {
            fputcsv($out, ['id', 'job_type', 'status', 'message', 'started_at', 'finished_at']);
            foreach ($jobsFiltered as $jobRow) {
                fputcsv($out, [
                    (string) ($jobRow['id'] ?? ''),
                    (string) ($jobRow['job_type'] ?? ''),
                    (string) ($jobRow['status'] ?? ''),
                    (string) ($jobRow['message'] ?? ''),
                    (string) ($jobRow['started_at'] ?? ''),
                    (string) ($jobRow['finished_at'] ?? ''),
                ]);
            }
            fclose($out);
        }
        exit;
    }
}

$assetTypeFilter = strtolower(trim((string) ($_GET['asset_type'] ?? 'all')));
$assets = list_project_assets($companyId, $projectId, $accountId, $assetTypeFilter === 'all' ? null : $assetTypeFilter);
$assetSummary = summarize_project_assets($assets);
$assetTypeCatalog = array_keys(hetzner_inventory_catalog());
$inventoryTotal = count($assets);

$serversHealthy = 0;
$serversUnhealthy = 0;
foreach ($servers as $serverRow) {
    $status = strtolower(trim((string) ($serverRow['status'] ?? '')));
    if (in_array($status, ['running', 'ok', 'active', 'healthy'], true)) {
        $serversHealthy++;
    } else {
        $serversUnhealthy++;
    }
}

$jobs24hSuccess = 0;
$jobs24hError = 0;
foreach ($jobs as $jobRow) {
    $startedTs = strtotime((string) ($jobRow['started_at'] ?? ''));
    if ($startedTs === false || ($nowTs - $startedTs) > 86400) {
        continue;
    }
    $status = strtolower(trim((string) ($jobRow['status'] ?? '')));
    if ($status === 'success') {
        $jobs24hSuccess++;
    }
    if ($status === 'error') {
        $jobs24hError++;
    }
}

$latestEvents = [];
foreach (array_slice($jobs, 0, 8) as $jobRow) {
    $latestEvents[] = [
        'at' => (string) ($jobRow['started_at'] ?? '-'),
        'type' => (string) ($jobRow['status'] ?? 'unknown'),
        'message' => trim((string) ($jobRow['message'] ?? 'Job de sincronizacao executado.')),
    ];
}
$accountStatusNormalized = strtolower(trim((string) ($account['status'] ?? '')));
if (in_array($accountStatusNormalized, ['invalid', 'error'], true)) {
    array_unshift($latestEvents, [
        'at' => (string) ($account['last_tested_at'] ?? '-'),
        'type' => 'error',
        'message' => 'Projeto com status de credencial invalida/erro. Recomendado testar token.',
    ]);
}
$latestEvents = array_slice($latestEvents, 0, 10);

ui_page_start('OmniNOC | Projeto Hetzner');
ui_navigation('hetzner', $user, $context, $flash);
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h3 class="mb-1">Projeto Hetzner: <?= htmlspecialchars((string) $account['label'], ENT_QUOTES, 'UTF-8') ?></h3>
    <small class="text-body-secondary">Fornecedor <?= htmlspecialchars((string) ($context['project']['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?> | isolamento por tenant/fornecedor</small>
  </div>
  <div class="d-flex gap-2">
    <?php if ($canManage): ?>
      <form method="post" action="/hetzner_account_details.php?id=<?= $accountId ?>" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="test_account">
        <input type="hidden" name="account_id" value="<?= $accountId ?>">
        <input type="hidden" name="redirect_tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-outline-secondary">Testar token</button>
      </form>

      <form method="post" action="/hetzner_account_details.php?id=<?= $accountId ?>" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="sync_servers">
        <input type="hidden" name="account_id" value="<?= $accountId ?>">
        <input type="hidden" name="redirect_tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-primary">Sincronizar servidores</button>
      </form>

      <form method="post" action="/hetzner_account_details.php?id=<?= $accountId ?>&tab=assets" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="sync_inventory">
        <input type="hidden" name="account_id" value="<?= $accountId ?>">
        <input type="hidden" name="redirect_tab" value="assets">
        <button type="submit" class="btn btn-outline-primary">Coletar inventario</button>
      </form>
    <?php else: ?>
      <button type="button" class="btn btn-outline-secondary" disabled>Testar token</button>
      <button type="button" class="btn btn-primary" disabled>Sincronizar servidores</button>
      <button type="button" class="btn btn-outline-primary" disabled>Coletar inventario</button>
    <?php endif; ?>
  </div>
</div>

<?php if (!$canManage): ?>
  <div class="alert alert-info">Acesso em modo leitura neste contexto. Acoes de escrita estao bloqueadas.</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'overview' ? 'active' : '' ?>" href="/hetzner_account_details.php?id=<?= $accountId ?>&tab=overview">Overview</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'servers' ? 'active' : '' ?>" href="/hetzner_account_details.php?id=<?= $accountId ?>&tab=servers">Servidores</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'assets' ? 'active' : '' ?>" href="/hetzner_account_details.php?id=<?= $accountId ?>&tab=assets">Inventario</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'jobs' ? 'active' : '' ?>" href="/hetzner_account_details.php?id=<?= $accountId ?>&tab=jobs">Jobs</a></li>
</ul>

<?php if ($tab === 'overview'): ?>
  <div class="row">
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Status do projeto</small><h4 class="mb-0"><?= htmlspecialchars((string) $account['status'], ENT_QUOTES, 'UTF-8') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Servidores vinculados</small><h4 class="mb-0"><?= count($servers) ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Servidores saudaveis</small><h4 class="mb-0"><?= number_format($serversHealthy, 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Inventario coletado</small><h4 class="mb-0"><?= number_format($inventoryTotal, 0, ',', '.') ?></h4></div></div>
    </div>
  </div>

  <div class="row">
    <div class="col-lg-8 mb-3">
      <div class="card h-100">
        <div class="card-header"><strong>Ultimos eventos</strong></div>
        <div class="card-body p-0">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Quando</th>
                <th>Status</th>
                <th>Detalhe</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($latestEvents === []): ?>
                <tr>
                  <td colspan="3" class="text-center text-body-secondary py-4">Sem eventos recentes para este projeto.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($latestEvents as $event): ?>
                  <?php
                    $eventType = strtolower(trim((string) ($event['type'] ?? 'unknown')));
                    $eventBadge = 'text-bg-secondary';
                    if ($eventType === 'success') {
                        $eventBadge = 'text-bg-success';
                    } elseif ($eventType === 'error') {
                        $eventBadge = 'text-bg-danger';
                    } elseif ($eventType === 'running') {
                        $eventBadge = 'text-bg-warning';
                    }
                  ?>
                  <tr>
                    <td><?= htmlspecialchars((string) ($event['at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="badge <?= $eventBadge ?>"><?= htmlspecialchars((string) ($event['type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars((string) ($event['message'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-4 mb-3">
      <div class="card h-100">
        <div class="card-header"><strong>Acoes rapidas</strong></div>
        <div class="card-body">
          <p class="mb-2 text-body-secondary">Operacoes sem sair do overview.</p>
          <div class="d-grid gap-2 mb-3">
            <?php if ($canManage): ?>
              <form method="post" action="/hetzner_account_details.php?id=<?= $accountId ?>">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="test_account">
                <input type="hidden" name="account_id" value="<?= $accountId ?>">
                <input type="hidden" name="redirect_tab" value="overview">
                <button type="submit" class="btn btn-outline-secondary w-100">Testar token</button>
              </form>
              <form method="post" action="/hetzner_account_details.php?id=<?= $accountId ?>">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="sync_servers">
                <input type="hidden" name="account_id" value="<?= $accountId ?>">
                <input type="hidden" name="redirect_tab" value="overview">
                <button type="submit" class="btn btn-primary w-100">Sincronizar servidores</button>
              </form>
              <form method="post" action="/hetzner_account_details.php?id=<?= $accountId ?>&tab=assets">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="sync_inventory">
                <input type="hidden" name="account_id" value="<?= $accountId ?>">
                <input type="hidden" name="redirect_tab" value="assets">
                <button type="submit" class="btn btn-outline-primary w-100">Coletar inventario</button>
              </form>
            <?php else: ?>
              <button type="button" class="btn btn-outline-secondary w-100" disabled>Testar token</button>
              <button type="button" class="btn btn-primary w-100" disabled>Sincronizar servidores</button>
              <button type="button" class="btn btn-outline-primary w-100" disabled>Coletar inventario</button>
            <?php endif; ?>
          </div>
          <div class="d-grid gap-2">
            <a href="/hetzner_account_details.php?id=<?= $accountId ?>&tab=servers" class="btn btn-sm btn-outline-secondary">Abrir servidores</a>
            <a href="/hetzner_account_details.php?id=<?= $accountId ?>&tab=assets" class="btn btn-sm btn-outline-secondary">Abrir inventario</a>
            <a href="/hetzner_account_details.php?id=<?= $accountId ?>&tab=jobs" class="btn btn-sm btn-outline-secondary">Abrir jobs</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Jobs success (24h)</small><h4 class="mb-0"><?= number_format($jobs24hSuccess, 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Jobs erro (24h)</small><h4 class="mb-0"><?= number_format($jobs24hError, 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Ultimo teste</small><h4 class="mb-0" style="font-size:1rem;"><?= htmlspecialchars((string) ($account['last_tested_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Ultimo sync</small><h4 class="mb-0" style="font-size:1rem;"><?= htmlspecialchars((string) ($account['last_synced_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></h4></div></div>
    </div>
  </div>

  <?php if ($canManage): ?>
    <div class="row">
      <div class="col-lg-7 mb-3">
        <div class="card h-100">
          <div class="card-header"><strong>Editar projeto Hetzner</strong></div>
          <div class="card-body">
            <form method="post" action="/hetzner_account_details.php?id=<?= $accountId ?>">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="update_account_profile">
              <input type="hidden" name="account_id" value="<?= $accountId ?>">
              <input type="hidden" name="redirect_tab" value="overview">
              <div class="mb-3">
                <label class="form-label">Nome do projeto (label)</label>
                <input
                  type="text"
                  class="form-control"
                  name="label"
                  required
                  value="<?= htmlspecialchars((string) ($account['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                >
              </div>
              <div class="mb-3">
                <label class="form-label">Novo API Token (opcional)</label>
                <input type="password" class="form-control" name="token" placeholder="Deixe vazio para manter o token atual">
                <small class="text-body-secondary">Se preencher, o token atual sera substituido e o status voltara para pendente ate novo teste.</small>
              </div>
              <button type="submit" class="btn btn-primary">Salvar alteracoes</button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-5 mb-3">
        <div class="card h-100 border border-danger-subtle">
          <div class="card-header"><strong class="text-danger">Zona de perigo</strong></div>
          <div class="card-body">
            <p class="text-body-secondary">
              Remover projeto apaga esta conta API e os recursos locais vinculados (servidores/inventario) da plataforma.
              Nao executa exclusao na Hetzner/origem.
            </p>
            <form method="post" action="/hetzner_account_details.php?id=<?= $accountId ?>">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="delete_account">
              <input type="hidden" name="account_id" value="<?= $accountId ?>">
              <input type="hidden" name="redirect_tab" value="overview">
              <label class="form-label">Digite <code>DELETAR</code> para confirmar</label>
              <input type="text" class="form-control mb-2" name="confirm_text" placeholder="DELETAR" required>
              <button
                type="submit"
                class="btn btn-outline-danger"
                onclick="return confirm('Confirma remover este projeto Hetzner da plataforma?');"
              >
                Deletar projeto da plataforma
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'servers'): ?>
  <div class="alert alert-warning">
    Exclusao aqui e <strong>apenas da plataforma</strong>. Nenhuma acao desta tela remove servidor na Hetzner/origem.
  </div>

  <?php if ($canManage && $migrationTargets !== [] && $servers !== []): ?>
    <div class="card mb-3">
      <div class="card-header"><strong>Migrar servidores para outro projeto/conta</strong></div>
      <div class="card-body">
        <p class="text-body-secondary mb-2">Migra apenas no cadastro interno da plataforma (escopo empresa).</p>
        <form method="post" action="/hetzner_account_details.php?id=<?= $accountId ?>" class="row g-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="migrate_servers">
          <input type="hidden" name="account_id" value="<?= $accountId ?>">
          <input type="hidden" name="redirect_tab" value="servers">
          <input type="hidden" name="migrate_scope" value="all">
          <div class="col-lg-5">
            <label class="form-label mb-1">Destino</label>
            <select name="target_account_id" class="form-select" required>
              <option value="">Selecione...</option>
              <?php foreach ($migrationTargets as $target): ?>
                <option value="<?= (int) ($target['id'] ?? 0) ?>">
                  <?= htmlspecialchars((string) ($target['project_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                  / <?= htmlspecialchars((string) ($target['label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-7 d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary">Migrar todos os servidores desta conta</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header"><strong>Servidores sincronizados deste projeto</strong></div>
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
          <?php if ($servers === []): ?>
            <tr>
              <td colspan="5" class="text-center text-body-secondary py-4">
                Nenhum servidor sincronizado para este projeto.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($servers as $server): ?>
              <tr>
                <td><?= htmlspecialchars((string) $server['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) $server['status'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($server['datacenter'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($server['ipv4'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td class="d-flex gap-2">
                  <a href="/server_details.php?id=<?= (int) $server['id'] ?>" class="btn btn-sm btn-outline-primary">Abrir servidor</a>
                  <?php if ($canManage): ?>
                    <form method="post" action="/hetzner_account_details.php?id=<?= $accountId ?>" class="d-inline">
                      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="delete_server">
                      <input type="hidden" name="account_id" value="<?= $accountId ?>">
                      <input type="hidden" name="server_id" value="<?= (int) $server['id'] ?>">
                      <input type="hidden" name="redirect_tab" value="servers">
                      <button
                        type="submit"
                        class="btn btn-sm btn-outline-danger"
                        onclick="return confirm('Remover servidor da plataforma? Isso NAO remove na Hetzner/origem.');"
                      >
                        Remover da plataforma
                      </button>
                    </form>
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

<?php if ($tab === 'jobs'): ?>
  <div class="row">
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Jobs filtrados</small><h4 class="mb-0"><?= number_format($jobsFilteredTotal, 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Success</small><h4 class="mb-0"><?= number_format($jobsFilteredSuccess, 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Error</small><h4 class="mb-0"><?= number_format($jobsFilteredError, 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Running</small><h4 class="mb-0"><?= number_format($jobsFilteredRunning, 0, ',', '.') ?></h4></div></div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><strong>Filtros e exportacao</strong></div>
    <div class="card-body">
      <form method="get" action="/hetzner_account_details.php" class="row g-2">
        <input type="hidden" name="id" value="<?= $accountId ?>">
        <input type="hidden" name="tab" value="jobs">
        <div class="col-lg-3">
          <label class="form-label mb-1">Status</label>
          <select class="form-select" name="job_status">
            <option value="all" <?= $jobStatusFilter === 'all' ? 'selected' : '' ?>>Todos</option>
            <option value="success" <?= $jobStatusFilter === 'success' ? 'selected' : '' ?>>Success</option>
            <option value="error" <?= $jobStatusFilter === 'error' ? 'selected' : '' ?>>Error</option>
            <option value="running" <?= $jobStatusFilter === 'running' ? 'selected' : '' ?>>Running</option>
          </select>
        </div>
        <div class="col-lg-3">
          <label class="form-label mb-1">Periodo</label>
          <select class="form-select" name="job_range">
            <option value="24h" <?= $jobRangeFilter === '24h' ? 'selected' : '' ?>>24h</option>
            <option value="7d" <?= $jobRangeFilter === '7d' ? 'selected' : '' ?>>7 dias</option>
            <option value="30d" <?= $jobRangeFilter === '30d' ? 'selected' : '' ?>>30 dias</option>
            <option value="all" <?= $jobRangeFilter === 'all' ? 'selected' : '' ?>>Tudo</option>
          </select>
        </div>
        <div class="col-lg-4">
          <label class="form-label mb-1">Buscar</label>
          <input type="text" class="form-control" name="job_q" value="<?= htmlspecialchars($jobQueryFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="id, erro, sync_inventory...">
        </div>
        <div class="col-lg-2 d-flex align-items-end">
          <button type="submit" class="btn btn-primary w-100">Aplicar</button>
        </div>
      </form>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <a href="/hetzner_account_details.php?id=<?= $accountId ?>&tab=jobs" class="btn btn-outline-secondary">Limpar</a>
        <a href="/hetzner_account_details.php?id=<?= $accountId ?>&tab=jobs&job_status=<?= urlencode($jobStatusFilter) ?>&job_range=<?= urlencode($jobRangeFilter) ?>&job_q=<?= urlencode($jobQueryFilter) ?>&export=csv" class="btn btn-outline-secondary">Download CSV</a>
        <a href="/hetzner_account_details.php?id=<?= $accountId ?>&tab=jobs&job_status=<?= urlencode($jobStatusFilter) ?>&job_range=<?= urlencode($jobRangeFilter) ?>&job_q=<?= urlencode($jobQueryFilter) ?>&export=json" class="btn btn-outline-secondary">Download JSON</a>
        <a href="/hetzner_jobs.php?account_id=<?= $accountId ?>" class="btn btn-outline-secondary">Abrir timeline global</a>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><strong>Historico de sincronizacoes</strong></div>
    <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>ID</th>
            <th>Tipo</th>
            <th>Status</th>
            <th>Mensagem</th>
            <th>Inicio</th>
            <th>Fim</th>
            <th>Duracao</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($jobsFiltered === []): ?>
            <tr>
              <td colspan="7" class="text-center text-body-secondary py-4">
                Nenhum job encontrado para os filtros atuais.
                <?php if ($canManage): ?>
                  <div class="mt-2">
                    <form method="post" action="/hetzner_account_details.php?id=<?= $accountId ?>" class="d-inline">
                      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="sync_servers">
                      <input type="hidden" name="account_id" value="<?= $accountId ?>">
                      <button type="submit" class="btn btn-sm btn-primary">Rodar sync agora</button>
                    </form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($jobsFiltered as $job): ?>
              <?php
                $startedTs = strtotime((string) ($job['started_at'] ?? ''));
                $finishedTs = strtotime((string) ($job['finished_at'] ?? ''));
                $durationLabel = '-';
                if ($startedTs !== false && $finishedTs !== false && $finishedTs >= $startedTs) {
                    $durationLabel = (string) ($finishedTs - $startedTs) . 's';
                } elseif (strtolower((string) ($job['status'] ?? '')) === 'running' && $startedTs !== false) {
                    $durationLabel = (string) max(0, ($nowTs - $startedTs)) . 's (running)';
                }
              ?>
              <tr>
                <td>#<?= (int) $job['id'] ?></td>
                <td><code><?= htmlspecialchars((string) ($job['job_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars((string) $job['status'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($job['message'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($job['started_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($job['finished_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($durationLabel, ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'assets'): ?>
  <div class="card mb-3">
    <div class="card-header"><strong>Resumo de inventario</strong></div>
    <div class="card-body">
      <?php if ($assetSummary === []): ?>
        <p class="mb-0 text-body-secondary">Nenhum recurso coletado ainda para esta conta.</p>
      <?php else: ?>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($assetSummary as $type => $count): ?>
            <span class="badge text-bg-info"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>: <?= number_format((int) $count, 0, ',', '.') ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><strong>Filtro</strong></div>
    <div class="card-body">
      <form method="get" action="/hetzner_account_details.php" class="row g-2">
        <input type="hidden" name="id" value="<?= $accountId ?>">
        <input type="hidden" name="tab" value="assets">
        <div class="col-lg-4">
          <label class="form-label mb-1">Tipo de recurso</label>
          <select class="form-select" name="asset_type">
            <option value="all" <?= $assetTypeFilter === 'all' ? 'selected' : '' ?>>Todos</option>
            <?php foreach ($assetTypeCatalog as $assetType): ?>
              <option value="<?= htmlspecialchars($assetType, ENT_QUOTES, 'UTF-8') ?>" <?= $assetTypeFilter === $assetType ? 'selected' : '' ?>>
                <?= htmlspecialchars($assetType, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-4 d-flex align-items-end gap-2">
          <button type="submit" class="btn btn-primary">Aplicar</button>
          <a href="/hetzner_account_details.php?id=<?= $accountId ?>&tab=assets" class="btn btn-outline-secondary">Limpar</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><strong>Recursos coletados</strong></div>
    <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Tipo</th>
            <th>Nome</th>
            <th>Status</th>
            <th>Regiao/DC</th>
            <th>IPv4</th>
            <th>Ultima coleta</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($assets === []): ?>
            <tr><td colspan="6" class="text-center text-body-secondary py-4">Nenhum recurso encontrado para este filtro.</td></tr>
          <?php else: ?>
            <?php foreach (array_slice($assets, 0, 250) as $asset): ?>
              <tr>
                <td><code><?= htmlspecialchars((string) ($asset['asset_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars((string) ($asset['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($asset['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($asset['datacenter'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($asset['ipv4'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($asset['last_seen_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
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
