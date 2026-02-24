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
$userId = (int) $user['id'];

$context = load_user_context($userId);
$companyId = $context['company_id'];
$projectId = $context['project_id'];
$providerType = context_provider_type($context);

if (!is_int($companyId) || !is_int($projectId) || $providerType !== 'hetzner') {
    flash_set('warning', 'Selecione empresa/projeto do fornecedor Hetzner para abrir jobs.');
    redirect('/projects.php');
}

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$allowedStatus = ['all', 'success', 'error', 'running'];
if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = 'all';
}

$accountFilter = (int) ($_GET['account_id'] ?? 0);
$filterSql = '';
$params = [
    'company_id' => $companyId,
    'project_id' => $projectId,
];
if ($statusFilter !== 'all') {
    $filterSql .= ' AND jr.status = :status';
    $params['status'] = $statusFilter;
}
if ($accountFilter > 0) {
    $filterSql .= ' AND NULLIF(jr.meta_json->>\'account_id\', \'\')::bigint = :account_id';
    $params['account_id'] = $accountFilter;
}

$jobsStmt = db()->prepare(
    "SELECT jr.id,
            jr.job_type,
            jr.status,
            jr.message,
            jr.started_at,
            jr.finished_at,
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
       {$filterSql}
     ORDER BY jr.started_at DESC
     LIMIT 300"
);
$jobsStmt->execute($params);
$jobs = $jobsStmt->fetchAll();
if (!is_array($jobs)) {
    $jobs = [];
}

$accountsStmt = db()->prepare(
    'SELECT id, label
     FROM provider_accounts
     WHERE company_id = :company_id
       AND project_id = :project_id
       AND provider = :provider_type
     ORDER BY label'
);
$accountsStmt->execute([
    'company_id' => $companyId,
    'project_id' => $projectId,
    'provider_type' => 'hetzner',
]);
$accounts = $accountsStmt->fetchAll();
if (!is_array($accounts)) {
    $accounts = [];
}

$flash = flash_pull();
ui_page_start('OmniNOC | Jobs Hetzner');
ui_navigation('hetzner_jobs', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Jobs Hetzner</h3>
    <small class="text-body-secondary">Timeline detalhada de sincronizacao e coleta de inventario.</small>
  </div>
  <a href="/hetzner_dashboard.php" class="btn btn-outline-secondary">Voltar ao dashboard</a>
</div>

<div class="card mb-3">
  <div class="card-header"><strong>Filtros</strong></div>
  <div class="card-body">
    <form method="get" action="/hetzner_jobs.php" class="row g-2 align-items-end">
      <div class="col-lg-3">
        <label class="form-label mb-1">Status</label>
        <select name="status" class="form-select">
          <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Todos</option>
          <option value="success" <?= $statusFilter === 'success' ? 'selected' : '' ?>>Success</option>
          <option value="error" <?= $statusFilter === 'error' ? 'selected' : '' ?>>Error</option>
          <option value="running" <?= $statusFilter === 'running' ? 'selected' : '' ?>>Running</option>
        </select>
      </div>
      <div class="col-lg-5">
        <label class="form-label mb-1">Projeto/Conta</label>
        <select name="account_id" class="form-select">
          <option value="0">Todas</option>
          <?php foreach ($accounts as $account): ?>
            <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $accountFilter === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>>
              <?= htmlspecialchars((string) ($account['label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Aplicar</button>
        <a href="/hetzner_jobs.php" class="btn btn-outline-secondary">Limpar</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Timeline</strong>
    <small class="text-body-secondary"><?= number_format(count($jobs), 0, ',', '.') ?> job(s)</small>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>ID</th>
          <th>Conta</th>
          <th>Tipo</th>
          <th>Status</th>
          <th>Inicio</th>
          <th>Fim</th>
          <th>Mensagem</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($jobs === []): ?>
          <tr><td colspan="7" class="text-center text-body-secondary py-4">Sem jobs para os filtros atuais.</td></tr>
        <?php else: ?>
          <?php foreach ($jobs as $job): ?>
            <tr>
              <td>#<?= (int) ($job['id'] ?? 0) ?></td>
              <td>
                <?php if ((int) ($job['account_id'] ?? 0) > 0): ?>
                  <a href="/hetzner_account_details.php?id=<?= (int) ($job['account_id'] ?? 0) ?>&tab=jobs">
                    <?= htmlspecialchars((string) ($job['account_label'] ?? '#N/A'), ENT_QUOTES, 'UTF-8') ?>
                  </a>
                <?php else: ?>
                  <?= htmlspecialchars((string) ($job['account_label'] ?? '#N/A'), ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
              </td>
              <td><code><?= htmlspecialchars((string) ($job['job_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></code></td>
              <td><?= htmlspecialchars((string) ($job['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($job['started_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($job['finished_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($job['message'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
ui_page_end();
