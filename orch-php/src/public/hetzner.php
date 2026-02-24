<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
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
$flash = flash_pull();
$companyId = $context['company_id'];
$projectId = $context['project_id'];
$providerType = context_provider_type($context);
$canManage = is_int($companyId) ? user_can_manage_company_context($userId, $companyId, $user) : false;

$accounts = [];
if (is_int($companyId) && is_int($projectId) && $providerType === 'hetzner') {
    $accounts = list_hetzner_accounts($companyId, $projectId);
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$allowedStatusFilters = ['all', 'active', 'invalid', 'error', 'pending'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
}

$filteredAccounts = [];
foreach ($accounts as $account) {
    $label = strtolower((string) ($account['label'] ?? ''));
    $status = strtolower((string) ($account['status'] ?? ''));
    if ($search !== '' && strpos($label, strtolower($search)) === false) {
        continue;
    }
    if ($statusFilter !== 'all' && $status !== $statusFilter) {
        continue;
    }
    $filteredAccounts[] = $account;
}

$accountsTotal = count($accounts);
$accountsActive = 0;
$accountsError = 0;
$accountsStaleSync = 0;
$serversTotal = 0;
$now = time();
foreach ($accounts as $account) {
    $status = strtolower((string) ($account['status'] ?? ''));
    if ($status === 'active') {
        $accountsActive++;
    }
    if (in_array($status, ['invalid', 'error'], true)) {
        $accountsError++;
    }

    $serversTotal += (int) ($account['server_count'] ?? 0);
    $lastSync = strtotime((string) ($account['last_synced_at'] ?? ''));
    if ($lastSync === false || ($now - $lastSync) > 86400) {
        $accountsStaleSync++;
    }
}

$statusClass = static function (string $status): string {
    $normalized = strtolower(trim($status));
    if ($normalized === 'active') {
        return 'text-bg-success';
    }
    if (in_array($normalized, ['invalid', 'error'], true)) {
        return 'text-bg-danger';
    }
    if ($normalized === 'pending') {
        return 'text-bg-warning';
    }
    return 'text-bg-secondary';
};

ui_page_start('OmniNOC | Hetzner');
ui_navigation('hetzner', $user, $context, $flash);
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h3 class="mb-1">Projetos Hetzner</h3>
    <small class="text-body-secondary">Saude de acesso, sincronizacao e inventario do fornecedor atual.</small>
  </div>
  <div class="d-flex gap-2">
    <a href="/hetzner_dashboard.php" class="btn btn-outline-secondary">Dashboard Hetzner</a>
    <?php if (is_platform_owner($user)): ?>
      <a href="/hetzner_operations.php" class="btn btn-outline-secondary">API Explorer</a>
    <?php endif; ?>
    <?php if ($canManage): ?>
      <a href="/hetzner_account_create.php" class="btn btn-primary">Conectar projeto Hetzner</a>
    <?php else: ?>
      <button type="button" class="btn btn-primary" disabled title="Sem permissao de escrita para esta empresa">Conectar projeto Hetzner</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($companyId === null || $projectId === null): ?>
  <div class="alert alert-warning">Nenhum fornecedor selecionado. Defina o contexto em <a href="/projects.php">Empresas e Fornecedores</a>.</div>
<?php elseif ($providerType !== 'hetzner'): ?>
  <div class="alert alert-warning">
    O fornecedor atual nao e do tipo Hetzner. Selecione um fornecedor Hetzner para acessar este modulo.
  </div>
<?php else: ?>
  <?php if (!$canManage): ?>
    <div class="alert alert-info">Acesso em modo leitura. Para testar token, sincronizar e executar operacoes, use um usuario com papel <code>owner/admin</code> na empresa.</div>
  <?php endif; ?>

  <div class="row">
    <div class="col-lg-3 col-12">
      <div class="small-box text-bg-primary">
        <div class="inner">
          <h3><?= number_format($accountsTotal, 0, ',', '.') ?></h3>
          <p>Projetos cadastrados</p>
          <small><?= number_format($accountsActive, 0, ',', '.') ?> ativas</small>
        </div>
        <i class="small-box-icon bi bi-key"></i>
      </div>
    </div>
    <div class="col-lg-3 col-12">
      <div class="small-box <?= $accountsError > 0 ? 'text-bg-danger' : 'text-bg-success' ?>">
        <div class="inner">
          <h3><?= number_format($accountsError, 0, ',', '.') ?></h3>
          <p>Projetos com erro</p>
          <small>invalid/error</small>
        </div>
        <i class="small-box-icon bi bi-exclamation-triangle"></i>
      </div>
    </div>
    <div class="col-lg-3 col-12">
      <div class="small-box <?= $accountsStaleSync > 0 ? 'text-bg-warning' : 'text-bg-success' ?>">
        <div class="inner">
          <h3><?= number_format($accountsStaleSync, 0, ',', '.') ?></h3>
          <p>Sync desatualizado</p>
          <small>sem sync nas ultimas 24h</small>
        </div>
        <i class="small-box-icon bi bi-clock-history"></i>
      </div>
    </div>
    <div class="col-lg-3 col-12">
      <div class="small-box text-bg-info">
        <div class="inner">
          <h3><?= number_format($serversTotal, 0, ',', '.') ?></h3>
          <p>Servidores coletados</p>
          <small>em todas as contas</small>
        </div>
        <i class="small-box-icon bi bi-server"></i>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><strong>Filtros</strong></div>
    <div class="card-body">
      <form method="get" action="/hetzner.php" class="row g-2">
        <div class="col-lg-5">
          <label class="form-label mb-1">Buscar por label</label>
          <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="ex: producao">
        </div>
        <div class="col-lg-3">
          <label class="form-label mb-1">Status</label>
          <select class="form-select" name="status">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Todos</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Ativas</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="invalid" <?= $statusFilter === 'invalid' ? 'selected' : '' ?>>Invalid</option>
            <option value="error" <?= $statusFilter === 'error' ? 'selected' : '' ?>>Error</option>
          </select>
        </div>
        <div class="col-lg-4 d-flex align-items-end gap-2">
          <button type="submit" class="btn btn-primary">Aplicar filtros</button>
          <a href="/hetzner.php" class="btn btn-outline-secondary">Limpar</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Projetos Hetzner no fornecedor</strong>
      <small class="text-body-secondary"><?= number_format(count($filteredAccounts), 0, ',', '.') ?> resultado(s)</small>
    </div>
    <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Label</th>
            <th>Status</th>
            <th>Ultimo teste</th>
            <th>Ultimo sync</th>
            <th>Servidores</th>
            <th>Ultima coleta</th>
            <th>Acoes</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($filteredAccounts === []): ?>
            <tr>
              <td colspan="7" class="text-center text-body-secondary py-4">
                Nenhum projeto encontrado com os filtros atuais.
                <?php if ($accounts === []): ?>
                  <div class="mt-2">
                    <?php if ($canManage): ?>
                      <a href="/hetzner_account_create.php" class="btn btn-sm btn-primary">Conectar primeiro projeto</a>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($filteredAccounts as $account): ?>
              <?php
              $statusValue = (string) ($account['status'] ?? '-');
              ?>
              <tr>
                <td><?= htmlspecialchars((string) $account['label'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="badge <?= $statusClass($statusValue) ?>"><?= htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars((string) ($account['last_tested_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($account['last_synced_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= number_format((int) ($account['server_count'] ?? 0), 0, ',', '.') ?></td>
                <td><?= htmlspecialchars((string) ($account['last_server_seen_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <a href="/hetzner_account_details.php?id=<?= (int) $account['id'] ?>" class="btn btn-sm btn-outline-primary">Abrir detalhes</a>
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
