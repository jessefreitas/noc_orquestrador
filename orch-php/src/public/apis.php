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
$isPlatformOwner = is_platform_owner_effective($user);
if (!$isPlatformOwner) {
    flash_set('warning', 'Somente o admin global pode acessar o modulo de APIs.');
    redirect('/');
}

$context = load_user_context((int) $user['id']);
$flash = flash_pull();
$companyId = $context['company_id'];
$projectId = $context['service_id'] ?? $context['project_id'];

ui_page_start('OmniNOC | APIs');
ui_navigation('apis', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">APIs</h3>
    <small class="text-body-secondary">Catalogo de APIs por fornecedor. Lista e detalhe separados serao implementados neste modulo.</small>
  </div>
</div>

<?php if ($companyId === null || !is_int($projectId) || $projectId <= 0): ?>
<div class="alert alert-warning">
  Bloqueado: selecione um fornecedor no topo para acessar APIs.
  <div class="mt-2"><a href="/projects.php" class="btn btn-sm btn-outline-primary">Ir para Empresas e Fornecedores</a></div>
</div>
<?php else: ?>
  <div class="card">
    <div class="card-header"><strong>Modulo em contrato UX</strong></div>
    <div class="card-body">
      <p class="mb-2">Esta pagina faz parte do shell padrao Tenant -> Fornecedor -> Recursos e sera detalhada em listagem + detalhe na proxima etapa.</p>
      <a href="/" class="btn btn-outline-secondary">Voltar ao dashboard</a>
    </div>
  </div>
<?php endif; ?>
<?php
ui_page_end();
