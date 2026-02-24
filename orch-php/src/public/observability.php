<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/observability_config.php';
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
$canManage = is_int($companyId) ? user_can_manage_company_context((int) $user['id'], $companyId, $user) : false;
$isPlatformOwner = is_platform_owner_effective($user);
$authUser = current_auth_user();
$actorUserId = is_array($authUser) ? (int) ($authUser['id'] ?? 0) : (int) ($user['id'] ?? 0);
$canManageEffective = $canManage || $isPlatformOwner;

if (!$isPlatformOwner) {
    flash_set('warning', 'Configuracao de observabilidade e restrita ao administrador global.');
    redirect('/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        flash_set('danger', 'CSRF invalido.');
        redirect('/observability.php');
    }

    if (!$canManageEffective) {
        flash_set('danger', 'Seu usuario esta em modo leitura para esta empresa.');
        redirect('/observability.php');
    }

    if (!is_int($companyId) || !is_int($projectId)) {
        flash_set('warning', 'Selecione empresa/projeto antes de configurar observabilidade.');
        redirect('/projects.php');
    }

    try {
        save_project_observability_config(
            $companyId,
            $projectId,
            $actorUserId > 0 ? $actorUserId : (int) $user['id'],
            [
                'loki_push_url' => (string) ($_POST['loki_push_url'] ?? ''),
                'loki_username' => (string) ($_POST['loki_username'] ?? ''),
                'loki_password' => (string) ($_POST['loki_password'] ?? ''),
                'vm_base_url' => (string) ($_POST['vm_base_url'] ?? ''),
                'retention_hours' => (int) ($_POST['retention_hours'] ?? 168),
                'status' => isset($_POST['enabled']) ? 'active' : 'inactive',
            ]
        );
        flash_set('success', 'Configuracao de observabilidade salva para este tenant/projeto.');
    } catch (Throwable $exception) {
        flash_set('danger', 'Falha ao salvar configuracao: ' . $exception->getMessage());
    }

    redirect('/observability.php');
}

$cfg = null;
if (is_int($companyId) && is_int($projectId)) {
    $cfg = get_project_observability_config($companyId, $projectId);
}

$flash = flash_pull();

ui_page_start('OmniNOC | Observabilidade');
ui_navigation('observability', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Observabilidade</h3>
    <small class="text-body-secondary">Logs, metricas e alertas centralizados do fornecedor.</small>
  </div>
</div>

<div class="card">
  <div class="card-header"><strong>Observabilidade por Tenant</strong></div>
  <div class="card-body">
    <p class="mb-3">Defina aqui os endpoints e credenciais de logs/metricas para a empresa + projeto selecionados.</p>

    <?php if (!is_int($companyId) || !is_int($projectId)): ?>
      <div class="alert alert-warning mb-0">Selecione empresa/projeto no topo para configurar observabilidade.</div>
    <?php else: ?>
      <form method="post" action="/observability.php">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="row g-2">
          <div class="col-lg-7">
            <label class="form-label mb-1">Loki Push URL</label>
            <input type="url" class="form-control" name="loki_push_url" placeholder="https://loki.seudominio.com/loki/api/v1/push" value="<?= htmlspecialchars((string) ($cfg['loki_push_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="col-lg-5">
            <label class="form-label mb-1">VictoriaMetrics Base URL</label>
            <input type="url" class="form-control" name="vm_base_url" placeholder="https://loki.seudominio.com/vm" value="<?= htmlspecialchars((string) ($cfg['vm_base_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-lg-4">
            <label class="form-label mb-1">Loki usuario (tenant)</label>
            <input type="text" class="form-control" name="loki_username" value="<?= htmlspecialchars((string) ($cfg['loki_username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="col-lg-4">
            <label class="form-label mb-1">Loki senha (tenant)</label>
            <input type="password" class="form-control" name="loki_password" autocomplete="new-password" placeholder="<?= ($cfg !== null && trim((string) ($cfg['loki_password'] ?? '')) !== '') ? 'Mantida (preencha para trocar)' : '' ?>">
          </div>
          <div class="col-lg-4">
            <label class="form-label mb-1">Retencao de logs (horas)</label>
            <input type="number" min="24" max="720" class="form-control" name="retention_hours" value="<?= (int) ($cfg['retention_hours'] ?? 168) ?>">
            <small class="text-body-secondary">Faixa: 24h ate 720h (30 dias).</small>
          </div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-lg-4 d-flex align-items-end">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" value="1" id="enabled" name="enabled" <?= ((string) ($cfg['status'] ?? '') === 'active') ? 'checked' : '' ?>>
              <label class="form-check-label" for="enabled">Config ativa neste tenant/projeto</label>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <?php if ($canManageEffective): ?>
            <button type="submit" class="btn btn-primary">Salvar configuracao</button>
          <?php else: ?>
            <button type="button" class="btn btn-primary" disabled>Salvar configuracao</button>
          <?php endif; ?>
          <a href="/" class="btn btn-outline-secondary">Voltar ao dashboard</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php
ui_page_end();

