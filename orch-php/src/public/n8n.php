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

ui_page_start('OmniNOC | N8N');
ui_navigation('n8n', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Fornecedor N8N</h3>
    <small class="text-body-secondary">Espaco reservado para contas, workflows e execucoes.</small>
  </div>
</div>
<div class="card">
  <div class="card-header"><strong>Em breve</strong></div>
  <div class="card-body">
    <p class="mb-0">O modulo N8N sera ativado no mesmo padrao de isolamento por tenant e fornecedor.</p>
  </div>
</div>
<?php
ui_page_end();
