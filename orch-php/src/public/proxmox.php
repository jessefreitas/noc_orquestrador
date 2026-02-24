<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
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
$providerType = context_provider_type($context);

ui_page_start('OmniNOC | ProxMox');
ui_navigation('proxmox', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Fornecedor ProxMox</h3>
    <small class="text-body-secondary">Modulo ProxMox no mesmo padrao de contas, servidores, APIs, snapshots e custos.</small>
  </div>
</div>

<?php if (($context['company_id'] ?? null) === null || ($context['project_id'] ?? null) === null): ?>
  <div class="alert alert-warning">Selecione empresa e fornecedor para operar o modulo ProxMox.</div>
<?php elseif ($providerType !== 'proxmox'): ?>
  <div class="alert alert-warning">O fornecedor selecionado nao e do tipo ProxMox. Selecione um fornecedor ProxMox no topo.</div>
<?php else: ?>
  <div class="row">
    <div class="col-lg-6 mb-3">
      <div class="card h-100">
        <div class="card-header"><strong>Contas ProxMox</strong></div>
        <div class="card-body">
          <p class="mb-2">Nesta etapa inicial, o modulo ProxMox foi habilitado no contexto, menu e contratos.</p>
          <p class="mb-0">Proximo passo: conectar credenciais da API e sincronizar inventario de nodes/VMs.</p>
        </div>
      </div>
    </div>
    <div class="col-lg-6 mb-3">
      <div class="card h-100">
        <div class="card-header"><strong>Operacoes disponiveis</strong></div>
        <div class="card-body">
          <ul class="mb-0">
            <li>Dashboard por fornecedor</li>
            <li>Submenu completo (contas/servidores/apis/snapshots/custos)</li>
            <li>Isolamento total por tenant + fornecedor</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php
ui_page_end();
