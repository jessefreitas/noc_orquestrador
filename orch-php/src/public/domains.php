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
$userId = (int) $user['id'];

$context = load_user_context($userId);
$companyId = $context['company_id'] ?? null;
$projectId = $context['project_id'] ?? null;
$providerType = context_provider_type($context);
$canManage = is_int($companyId) ? user_can_manage_company_context($userId, $companyId, $user) : false;
$flash = flash_pull();

ui_page_start('OmniNOC | Dominios');
ui_navigation('domains', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Dominios</h3>
    <small class="text-body-secondary">Gestao de zonas e DNS por fornecedor com isolamento de contexto.</small>
  </div>
</div>

<?php if (!is_int($companyId) || !is_int($projectId)): ?>
  <div class="alert alert-warning">Selecione empresa e fornecedor no topo para operar dominios.</div>
<?php elseif ($providerType !== 'cloudflare'): ?>
  <div class="card">
    <div class="card-header"><strong>Dominios indisponivel para este provider</strong></div>
    <div class="card-body">
      <p class="mb-2">O provider atual nao possui modulo de zonas/DNS neste painel. Esta area fica habilitada para contexto <strong>Cloudflare</strong>.</p>
      <a href="/projects.php" class="btn btn-outline-secondary">Trocar contexto de provider</a>
    </div>
  </div>
<?php else: ?>
  <div class="alert alert-info">
    Modulo Cloudflare ativo para este tenant. Estrutura pronta para gestao de zonas e DNS.
  </div>

  <div class="row mb-3">
    <div class="col-xl-4 col-md-6 mb-3">
      <div class="card">
        <div class="card-body">
          <small class="text-body-secondary d-block">Zonas</small>
          <h4 class="mb-0">0</h4>
        </div>
      </div>
    </div>
    <div class="col-xl-4 col-md-6 mb-3">
      <div class="card">
        <div class="card-body">
          <small class="text-body-secondary d-block">Registros DNS</small>
          <h4 class="mb-0">0</h4>
        </div>
      </div>
    </div>
    <div class="col-xl-4 col-md-6 mb-3">
      <div class="card">
        <div class="card-body">
          <small class="text-body-secondary d-block">Provider</small>
          <h4 class="mb-0">Cloudflare</h4>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><strong>Zonas e DNS</strong></div>
    <div class="card-body">
      <p class="mb-2">Fluxo preparado para as proximas entregas:</p>
      <ul class="mb-0">
        <li>listar zonas Cloudflare por tenant/projeto</li>
        <li>listar/filtrar registros DNS por zona</li>
        <li>criar/editar/apagar registro DNS com auditoria</li>
        <li>sincronizacao periodica com status e ultima coleta</li>
      </ul>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><strong>Acesso</strong></div>
    <div class="card-body">
      <?php if ($canManage): ?>
        <p class="mb-0 text-body-secondary">Usuario com permissao de gerenciamento neste contexto Cloudflare.</p>
      <?php else: ?>
        <p class="mb-0 text-body-secondary">Usuario em modo leitura neste contexto Cloudflare.</p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php
ui_page_end();

