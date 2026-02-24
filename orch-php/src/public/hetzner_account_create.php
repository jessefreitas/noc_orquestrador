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
$companyId = $context['company_id'];
$projectId = $context['project_id'];
$providerType = context_provider_type($context);
$canManage = is_int($companyId) ? user_can_manage_company_context($userId, $companyId, $user) : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        flash_set('danger', 'CSRF invalido.');
        redirect('/hetzner_account_create.php');
    }

    if (!is_int($companyId) || !is_int($projectId) || $providerType !== 'hetzner') {
        flash_set('warning', 'Selecione empresa e fornecedor antes de cadastrar projeto Hetzner.');
        redirect('/projects.php');
    }
    if (!$canManage) {
        flash_set('danger', 'Seu usuario esta em modo leitura para esta empresa.');
        redirect('/hetzner.php');
    }

    try {
        $accountId = create_hetzner_account(
            $companyId,
            $projectId,
            $userId,
            (string) ($_POST['label'] ?? ''),
            (string) ($_POST['token'] ?? '')
        );

        $testResult = test_hetzner_account($companyId, $projectId, $accountId, $userId);
        if (($testResult['ok'] ?? false) !== true) {
            flash_set(
                'warning',
                'Projeto cadastrado, mas o teste do token falhou: ' . (string) ($testResult['message'] ?? 'erro desconhecido')
            );
            redirect('/hetzner_account_details.php?id=' . $accountId . '&tab=overview');
        }

        $syncServers = sync_hetzner_servers($companyId, $projectId, $accountId, $userId);
        $syncInventory = sync_hetzner_inventory($companyId, $projectId, $accountId, $userId);

        $serversCount = (int) ($syncServers['count'] ?? 0);
        $assetsCount = (int) ($syncInventory['total'] ?? 0);
        $inventoryByType = $syncInventory['by_type'] ?? [];
        $byTypeParts = [];
        if (is_array($inventoryByType)) {
            foreach ($inventoryByType as $type => $count) {
                $byTypeParts[] = (string) $type . ':' . (int) $count;
            }
        }
        $byTypeSuffix = $byTypeParts === [] ? '' : ' | Tipos: ' . implode(', ', $byTypeParts);

        $serversOk = (($syncServers['ok'] ?? false) === true);
        $inventoryOk = (($syncInventory['ok'] ?? false) === true);
        if ($serversOk && $inventoryOk) {
            flash_set(
                'success',
                'Projeto cadastrado e sincronizado automaticamente. Servidores: ' . $serversCount . ' | Inventario: ' . $assetsCount . $byTypeSuffix
            );
        } else {
            $issues = [];
            if (!$serversOk) {
                $issues[] = 'sync servidores: ' . (string) ($syncServers['message'] ?? 'falha');
            }
            if (!$inventoryOk) {
                $issues[] = 'sync inventario: ' . (string) ($syncInventory['message'] ?? 'falha');
            }
            flash_set(
                'warning',
                'Projeto cadastrado, mas a sincronizacao automatica teve alertas. ' . implode(' | ', $issues)
            );
        }
        redirect('/hetzner_account_details.php?id=' . $accountId . '&tab=jobs');
    } catch (Throwable $exception) {
        flash_set('danger', $exception->getMessage());
        redirect('/hetzner_account_create.php');
    }
}

$flash = flash_pull();

ui_page_start('OmniNOC | Cadastrar Projeto Hetzner');
ui_navigation('hetzner', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Conectar projeto Hetzner</h3>
    <small class="text-body-secondary">Cadastro isolado por fornecedor, sem compartilhar token entre tenants.</small>
  </div>
  <a href="/hetzner.php" class="btn btn-outline-secondary">Voltar para lista</a>
</div>

<?php if (!is_int($companyId) || !is_int($projectId)): ?>
  <div class="alert alert-warning">Nenhum contexto ativo. Selecione empresa/fornecedor em <a href="/projects.php">Empresas e Fornecedores</a>.</div>
<?php elseif ($providerType !== 'hetzner'): ?>
  <div class="alert alert-warning">O fornecedor selecionado nao e do tipo Hetzner.</div>
<?php else: ?>
  <?php if (!$canManage): ?>
    <div class="alert alert-info">Acesso em modo leitura. Somente <code>owner/admin</code> da empresa podem cadastrar projeto Hetzner.</div>
  <?php endif; ?>
  <div class="row justify-content-center">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header"><strong>Novo projeto Hetzner</strong></div>
        <div class="card-body">
          <div class="mb-3">
            <small class="text-body-secondary">
              Contexto atual: <?= htmlspecialchars((string) ($context['company']['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?> /
              <?= htmlspecialchars((string) ($context['project']['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
            </small>
          </div>

          <form method="post" action="/hetzner_account_create.php">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="mb-3">
              <label class="form-label">Label do projeto</label>
              <input type="text" class="form-control" name="label" required placeholder="Hetzner Producao" <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="mb-3">
              <label class="form-label">API Token</label>
              <input type="password" class="form-control" name="token" required placeholder="hcloud_..." <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-primary" <?= $canManage ? '' : 'disabled' ?>>Salvar projeto</button>
              <a href="/hetzner.php" class="btn btn-outline-secondary">Cancelar</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php
ui_page_end();
