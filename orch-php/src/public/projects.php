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
    flash_set('warning', 'Somente o gestor global pode acessar cadastros de empresa e vinculos de servicos.');
    redirect('/');
}
$userId = (int) $user['id'];

$context = load_user_context($userId);
$flash = flash_pull();
$companies = $context['companies'] ?? [];
$projects = list_accessible_projects($userId);

ui_page_start('OmniNOC | Fornecedores');
ui_navigation('projects', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h3 class="mb-1">Empresas e Fornecedores</h3>
    <small class="text-body-secondary">Cadastre empresas e habilite os servicos licenciados em cada uma.</small>
  </div>
  <div class="d-flex gap-2">
    <a href="/backup_storage.php" class="btn btn-outline-secondary">Storage backup</a>
    <a href="/company_create.php" class="btn btn-outline-secondary">Nova empresa</a>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><strong>Empresas</strong></div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Nome</th>
          <th>Documento</th>
          <th>Email alerta</th>
          <th>Telefone alerta</th>
          <th>Acoes</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($companies === []): ?>
          <tr>
            <td colspan="5" class="text-center text-body-secondary py-4">Nenhuma empresa cadastrada.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($companies as $company): ?>
            <tr>
              <td><?= htmlspecialchars((string) $company['name'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($company['tax_id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($company['alert_email'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($company['alert_phone'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-primary" href="/company_details.php?id=<?= (int) $company['id'] ?>">Editar</a>
                <a class="btn btn-sm btn-primary" href="/company_details.php?id=<?= (int) $company['id'] ?>#servicos-habilitados">Servicos habilitados</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><strong>Lista de servicos habilitados</strong></div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Empresa</th>
          <th>Servico/Fornecedor</th>
          <th>Tipo</th>
          <th>Slug</th>
          <th>Status</th>
          <th>Acoes</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($projects === []): ?>
          <tr>
            <td colspan="6" class="text-center text-body-secondary py-4">
              Nenhum fornecedor encontrado.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($projects as $project): ?>
            <?php $providerType = infer_provider_type_from_project($project) ?? 'indefinido'; ?>
            <tr>
              <td><?= htmlspecialchars((string) $project['company_name'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) $project['name'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($providerType, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) $project['slug'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) $project['status'], ENT_QUOTES, 'UTF-8') ?></td>
              <td class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-primary" href="/project_details.php?id=<?= (int) $project['id'] ?>">
                  Abrir detalhes
                </a>
                <a class="btn btn-sm btn-outline-secondary" href="/switch_context.php" onclick="event.preventDefault(); document.getElementById('apply-context-<?= (int) $project['id'] ?>').submit();">
                  Entrar no contexto
                </a>
                <form id="apply-context-<?= (int) $project['id'] ?>" method="post" action="/switch_context.php" class="d-none">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="company_id" value="<?= (int) $project['company_id'] ?>">
                  <input type="hidden" name="service_id" value="<?= (int) $project['id'] ?>">
                  <input type="hidden" name="redirect_to" value="/projects.php">
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
ui_page_end();
