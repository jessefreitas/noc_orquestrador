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

ui_page_start('OmniNOC | Config e Acesso');
ui_navigation('settings', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Config e Acesso</h3>
    <small class="text-body-secondary">Cofre de credenciais, RBAC e operacao remota segura por tenant/projeto.</small>
  </div>
</div>

<div class="alert alert-info">
  Escopo definido para <strong>V3</strong>: mover gestao sensivel para este modulo e evitar exposicao de rotas/segredos fora do fluxo de seguranca.
</div>

<div class="row">
  <div class="col-xl-4 col-md-6 mb-3">
    <div class="card h-100">
      <div class="card-header"><strong>1) Cofre de credenciais</strong></div>
      <div class="card-body">
        <p class="mb-2">Vault por tenant/projeto/servidor com criptografia, RBAC e trilha de auditoria.</p>
        <ul class="mb-0">
          <li>segredos versionados e mascarados</li>
          <li>acesso por papel (global/admin/viewer)</li>
          <li>auditoria completa de leitura/uso/rotacao</li>
        </ul>
      </div>
    </div>
  </div>

  <div class="col-xl-4 col-md-6 mb-3">
    <div class="card h-100">
      <div class="card-header"><strong>2) Aplicar credencial no servidor</strong></div>
      <div class="card-body">
        <p class="mb-2">Selecionar credencial do cofre e aplicar nos fluxos operacionais (instalacao/agentes/acoes remotas).</p>
        <ul class="mb-0">
          <li>sem exibir senha/token em tela</li>
          <li>uso just-in-time com expiracao</li>
          <li>registro de quem aplicou, onde e quando</li>
        </ul>
      </div>
    </div>
  </div>

  <div class="col-xl-4 col-md-6 mb-3">
    <div class="card h-100">
      <div class="card-header"><strong>3) Console Shell com guardrails</strong></div>
      <div class="card-body">
        <p class="mb-2">Execucao remota controlada, com aprovacao e bloqueio de comandos criticos.</p>
        <ul class="mb-0">
          <li>execucao por perfil e escopo</li>
          <li>aprovacao obrigatoria para comando sensivel</li>
          <li>log completo de entrada/saida por sessao</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><strong>Status de implementacao</strong></div>
  <div class="card-body">
    <p class="mb-2">Planejado para V3 (sem liberar terminal root livre). Este modulo substitui o placeholder anterior de Config e Acesso.</p>
    <a href="/" class="btn btn-outline-secondary">Voltar ao dashboard</a>
  </div>
</div>
<?php
ui_page_end();

