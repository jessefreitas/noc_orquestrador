<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/hetzner.php';
require_once __DIR__ . '/../app/snapshot_policy.php';
require_once __DIR__ . '/../app/tenancy.php';
require_once __DIR__ . '/../app/ui.php';

require_auth();
$user = current_user();
if ($user === null) {
    redirect('/login.php');
}

$context = load_user_context((int) $user['id']);
$companyId = $context['company_id'];
$projectId = $context['project_id'];
$providerType = context_provider_type($context);
$canManage = is_int($companyId) ? user_can_manage_company_context((int) $user['id'], $companyId, $user) : false;

if (!is_int($companyId) || !is_int($projectId) || $providerType !== 'hetzner') {
    flash_set('warning', 'Selecione empresa/projeto e fornecedor Hetzner para abrir snapshots.');
    redirect('/projects.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['_csrf'] ?? null)) {
        flash_set('danger', 'Token CSRF invalido.');
        redirect('/snapshots.php');
    }

    if (!$canManage) {
        flash_set('danger', 'Voce nao possui permissao para executar snapshots neste contexto.');
        redirect('/snapshots.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'create_snapshot_now') {
        $serverId = (int) ($_POST['server_id'] ?? 0);
        if ($serverId <= 0) {
            flash_set('warning', 'Selecione um servidor valido para executar o snapshot.');
            redirect('/snapshots.php');
        }

        try {
            $run = snapshot_create_now_for_server($companyId, $projectId, $serverId, (int) $user['id'], 'manual');
            if (($run['ok'] ?? false) === true) {
                flash_set('success', (string) ($run['message'] ?? 'Snapshot solicitado com sucesso.'));
            } else {
                flash_set('danger', (string) ($run['message'] ?? 'Falha ao solicitar snapshot.'));
            }
        } catch (Throwable $exception) {
            flash_set('danger', 'Falha ao solicitar snapshot: ' . $exception->getMessage());
        }
        redirect('/snapshots.php');
    }
}

$servers = list_project_servers($companyId, $projectId);
$policies = list_project_snapshot_policy_overview($companyId, $projectId);
$recentRuns = list_project_snapshot_runs($companyId, $projectId, 40);

$policyByServer = [];
foreach ($policies as $policyRow) {
    $serverKey = (int) ($policyRow['server_id'] ?? 0);
    if ($serverKey > 0) {
        $policyByServer[$serverKey] = $policyRow;
    }
}

$summary = [
    'servers_total' => count($servers),
    'policies_enabled' => 0,
    'next_due' => 0,
    'errors_last' => 0,
];
foreach ($policies as $policyRow) {
    if ((bool) ($policyRow['enabled'] ?? false)) {
        $summary['policies_enabled']++;
    }
    if (trim((string) ($policyRow['next_run_at'] ?? '')) !== '') {
        $summary['next_due']++;
    }
    if (strtolower(trim((string) ($policyRow['last_status'] ?? ''))) === 'error') {
        $summary['errors_last']++;
    }
}

$upcoming = array_values(array_filter($policies, static function (array $row): bool {
    return trim((string) ($row['next_run_at'] ?? '')) !== '';
}));
usort($upcoming, static function (array $a, array $b): int {
    return strcmp((string) ($a['next_run_at'] ?? ''), (string) ($b['next_run_at'] ?? ''));
});
$upcoming = array_slice($upcoming, 0, 12);

$formatSchedule = static function (array $row): string {
    $mode = (string) ($row['schedule_mode'] ?? 'manual');
    if ($mode !== 'interval') {
        return 'Manual';
    }
    $minutes = (int) ($row['interval_minutes'] ?? 0);
    if ($minutes <= 0) {
        return 'Intervalo';
    }
    $hours = $minutes / 60;
    $hoursText = abs($hours - round($hours)) < 0.001
        ? (string) (int) round($hours)
        : rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    return 'Intervalo (' . $hoursText . 'h)';
};

$flash = flash_pull();

ui_page_start('OmniNOC | Snapshots');
ui_navigation('snapshots', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Snapshots</h3>
    <small class="text-body-secondary">Gestao consolidada de politicas, agenda e execucoes por servidor.</small>
  </div>
  <div>
    <a href="/servers.php" class="btn btn-outline-secondary">Abrir servidores</a>
  </div>
</div>

<div class="row">
  <div class="col-lg-3 col-md-6 mb-3">
    <div class="card h-100">
      <div class="card-body">
        <small class="text-body-secondary">Servidores</small>
        <div class="h4 mb-0"><?= (int) $summary['servers_total'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-3 col-md-6 mb-3">
    <div class="card h-100">
      <div class="card-body">
        <small class="text-body-secondary">Politicas ativas</small>
        <div class="h4 mb-0"><?= (int) $summary['policies_enabled'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-3 col-md-6 mb-3">
    <div class="card h-100">
      <div class="card-body">
        <small class="text-body-secondary">Com proxima execucao</small>
        <div class="h4 mb-0"><?= (int) $summary['next_due'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-3 col-md-6 mb-3">
    <div class="card h-100">
      <div class="card-body">
        <small class="text-body-secondary">Ultimo status em erro</small>
        <div class="h4 mb-0"><?= (int) $summary['errors_last'] ?></div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><strong>Acao rapida</strong></div>
  <div class="card-body">
    <p class="mb-2">Disparo manual imediato de snapshot no servidor selecionado.</p>
    <form method="post" action="/snapshots.php" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="create_snapshot_now">
      <div class="col-lg-8">
        <label class="form-label mb-1">Servidor</label>
        <select class="form-select" name="server_id" <?= $canManage ? '' : 'disabled' ?>>
          <option value="">Selecione...</option>
          <?php foreach ($servers as $server): ?>
            <option value="<?= (int) ($server['id'] ?? 0) ?>">
              <?= htmlspecialchars((string) ($server['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars((string) ($server['ipv4'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-4">
        <?php if ($canManage): ?>
          <button type="submit" class="btn btn-primary w-100">Snapshot agora</button>
        <?php else: ?>
          <button type="button" class="btn btn-primary w-100" disabled>Snapshot agora</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="row">
  <div class="col-xl-6 mb-3">
    <div class="card h-100">
      <div class="card-header"><strong>Proximas execucoes</strong></div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Servidor</th>
              <th>Agenda</th>
              <th>Proxima execucao</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($upcoming === []): ?>
              <tr><td colspan="3" class="text-center text-body-secondary py-3">Sem agenda futura.</td></tr>
            <?php else: ?>
              <?php foreach ($upcoming as $row): ?>
                <tr>
                  <td>
                    <a href="/server_details.php?id=<?= (int) ($row['server_id'] ?? 0) ?>&tab=snapshots">
                      <?= htmlspecialchars((string) ($row['server_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                  </td>
                  <td><?= htmlspecialchars($formatSchedule($row), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) ($row['next_run_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-xl-6 mb-3">
    <div class="card h-100">
      <div class="card-header"><strong>Ultimas execucoes (projeto)</strong></div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Inicio</th>
              <th>Servidor</th>
              <th>Status</th>
              <th>Tipo</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($recentRuns === []): ?>
              <tr><td colspan="4" class="text-center text-body-secondary py-3">Sem execucoes registradas.</td></tr>
            <?php else: ?>
              <?php foreach ($recentRuns as $run): ?>
                <tr>
                  <td><?= htmlspecialchars((string) ($run['started_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <a href="/server_details.php?id=<?= (int) ($run['server_id'] ?? 0) ?>&tab=snapshots">
                      <?= htmlspecialchars((string) ($run['server_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                  </td>
                  <td><?= htmlspecialchars((string) ($run['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) ($run['run_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><strong>Politicas por servidor</strong></div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Servidor</th>
          <th>Conta</th>
          <th>Status</th>
          <th>Politica</th>
          <th>Agenda</th>
          <th>Retencao</th>
          <th>Ultimo status</th>
          <th>Acoes</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($servers === []): ?>
          <tr><td colspan="8" class="text-center text-body-secondary py-4">Nenhum servidor neste contexto.</td></tr>
        <?php else: ?>
          <?php foreach ($servers as $server): ?>
            <?php
              $serverId = (int) ($server['id'] ?? 0);
              $policy = $policyByServer[$serverId] ?? [];
              $enabled = (bool) ($policy['enabled'] ?? false);
              $retentionDays = (int) ($policy['retention_days'] ?? 0);
              $retentionCount = (int) ($policy['retention_count'] ?? 0);
            ?>
            <tr>
              <td>
                <a href="/server_details.php?id=<?= $serverId ?>&tab=snapshots">
                  <?= htmlspecialchars((string) ($server['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                </a>
                <div><small class="text-body-secondary"><?= htmlspecialchars((string) ($server['ipv4'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></small></div>
              </td>
              <td><?= htmlspecialchars((string) ($server['account_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($server['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= $enabled ? 'Ativa' : 'Inativa' ?></td>
              <td><?= htmlspecialchars($formatSchedule($policy), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars(($retentionDays > 0 ? $retentionDays . 'd' : '-') . ' / ' . ($retentionCount > 0 ? $retentionCount . ' itens' : '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($policy['last_status'] ?? 'n/a'), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" href="/server_details.php?id=<?= $serverId ?>&tab=snapshots">Gerenciar</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
ui_page_end();

