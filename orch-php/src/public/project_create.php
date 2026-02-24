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
if (!is_platform_owner($user)) {
    flash_set('warning', 'Somente o gestor global pode vincular servicos para empresas.');
    redirect('/');
}
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        flash_set('danger', 'CSRF invalido.');
        redirect('/project_create.php');
    }

    try {
        $companyId = (int) ($_POST['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new RuntimeException('Selecione uma empresa valida.');
        }
        $providerType = strtolower(trim((string) ($_POST['provider_type'] ?? '')));
        $capabilities = default_capabilities_for_provider($providerType);

        create_project(
            $userId,
            $companyId,
            (string) ($_POST['project_name'] ?? ''),
            (string) ($_POST['project_slug'] ?? ''),
            null,
            $capabilities
        );
        flash_set('success', 'Fornecedor criado com sucesso.');
        redirect('/projects.php');
    } catch (Throwable $exception) {
        flash_set('danger', $exception->getMessage());
        redirect('/project_create.php');
    }
}

$context = load_user_context($userId);
$flash = flash_pull();
$companies = $context['companies'];
$selectedCompanyId = (int) ($_GET['company_id'] ?? 0);
if ($selectedCompanyId <= 0) {
    $selectedCompanyId = (int) ($_POST['company_id'] ?? 0);
}

ui_page_start('OmniNOC | Criar Fornecedor');
ui_navigation('projects', $user, $context, $flash);
?>
<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><strong>Vincular fornecedor</strong></div>
      <div class="card-body">
        <form method="post" action="/project_create.php">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <div class="mb-3">
            <label class="form-label">Empresa</label>
            <select class="form-select" name="company_id" required>
              <option value="">Selecione</option>
              <?php foreach ($companies as $company): ?>
                <option value="<?= (int) $company['id'] ?>" <?= $selectedCompanyId === (int) $company['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) $company['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Nome do fornecedor</label>
            <input type="text" class="form-control" name="project_name" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Tipo do fornecedor</label>
            <select class="form-select" name="provider_type" required>
              <option value="">Selecione</option>
              <option value="hetzner">Hetzner</option>
              <option value="cloudflare">Cloudflare</option>
              <option value="n8n">N8N</option>
              <option value="portainer">Portainer</option>
              <option value="mega">Mega</option>
              <option value="proxmox">ProxMox</option>
              <option value="llm">LLM (OpenAI/OpenRouter/Z.ai)</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Slug (opcional)</label>
            <input type="text" class="form-control" name="project_slug" placeholder="hetzner-producao">
          </div>
          <p class="text-body-secondary mb-3">
            Esta tela apenas vincula a empresa ao servico ofertado. Credenciais/API serao configuradas em <strong>Contas</strong> do fornecedor.
          </p>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Salvar fornecedor</button>
            <a href="/projects.php" class="btn btn-outline-secondary">Voltar</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php
ui_page_end();
