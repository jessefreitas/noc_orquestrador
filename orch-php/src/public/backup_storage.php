<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/backup_storage.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/tenancy.php';
require_once __DIR__ . '/../app/ui.php';

require_auth();
$user = current_user();
if ($user === null) {
    redirect('/login.php');
}
if (!is_platform_owner($user)) {
    flash_set('warning', 'Somente o gestor global pode gerenciar storage de backup.');
    redirect('/');
}

$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        flash_set('danger', 'CSRF invalido.');
        redirect('/backup_storage.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create_storage') {
            create_global_backup_storage(
                $userId,
                (string) ($_POST['provider'] ?? ''),
                [
                    'name' => (string) ($_POST['name'] ?? ''),
                    'account_identifier' => (string) ($_POST['account_identifier'] ?? ''),
                    'endpoint_url' => (string) ($_POST['endpoint_url'] ?? ''),
                    'region' => (string) ($_POST['region'] ?? ''),
                    'default_bucket' => (string) ($_POST['default_bucket'] ?? ''),
                    'access_key_id' => (string) ($_POST['access_key_id'] ?? ''),
                    'secret_access_key' => (string) ($_POST['secret_access_key'] ?? ''),
                    'force_path_style' => isset($_POST['force_path_style']),
                ]
            );
            flash_set('success', 'Storage global de backup cadastrado com sucesso.');
            redirect('/backup_storage.php');
        }

        if ($action === 'set_status') {
            $storageId = (int) ($_POST['storage_id'] ?? 0);
            if ($storageId <= 0) {
                throw new RuntimeException('Storage invalido.');
            }
            set_global_backup_storage_status(
                $userId,
                $storageId,
                (string) ($_POST['status'] ?? '')
            );
            flash_set('success', 'Status do storage atualizado.');
            redirect('/backup_storage.php');
        }

        flash_set('warning', 'Acao invalida.');
        redirect('/backup_storage.php');
    } catch (Throwable $exception) {
        flash_set('danger', $exception->getMessage());
        redirect('/backup_storage.php');
    }
}

$context = load_user_context($userId);
$flash = flash_pull();
$storageReady = backup_storage_ready();
$providerCatalog = backup_storage_provider_catalog();
$storages = $storageReady ? list_global_backup_storages() : [];

ui_page_start('OmniNOC | Storage Backup');
ui_navigation('backup_storage', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Storage Backup Global</h3>
    <small class="text-body-secondary">
      Cadastre Cloudflare R2 ou Amazon S3 para oferecer backup de bancos PostgreSQL para as empresas.
    </small>
  </div>
</div>

<?php if (!$storageReady): ?>
  <div class="alert alert-warning">
    Estrutura de backup storage nao encontrada no banco atual.
    <div class="mt-2"><code><?= htmlspecialchars(backup_storage_migration_hint_command(), ENT_QUOTES, 'UTF-8') ?></code></div>
  </div>
<?php else: ?>
  <div class="row">
    <div class="col-lg-5 mb-3">
      <div class="card">
        <div class="card-header"><strong>Novo storage global</strong></div>
        <div class="card-body">
          <form method="post" action="/backup_storage.php">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create_storage">
            <div class="mb-3">
              <label class="form-label">Provider</label>
              <select class="form-select" name="provider" required>
                <?php foreach ($providerCatalog as $providerKey => $providerMeta): ?>
                  <option value="<?= htmlspecialchars($providerKey, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string) ($providerMeta['label'] ?? strtoupper($providerKey)), ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Nome do cadastro</label>
              <input type="text" class="form-control" name="name" placeholder="R2 Producao Global" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Account ID / Identificador (opcional)</label>
              <input type="text" class="form-control" name="account_identifier" placeholder="Cloudflare account id ou conta AWS">
            </div>
            <div class="mb-3">
              <label class="form-label">Endpoint (opcional)</label>
              <input type="text" class="form-control" name="endpoint_url" placeholder="https://<accountid>.r2.cloudflarestorage.com">
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Regiao (opcional)</label>
                <input type="text" class="form-control" name="region" placeholder="us-east-1">
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Bucket padrao (opcional)</label>
                <input type="text" class="form-control" name="default_bucket" placeholder="omninoc-postgres-backups">
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label">Access key id</label>
              <input type="password" class="form-control" name="access_key_id" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Secret access key</label>
              <input type="password" class="form-control" name="secret_access_key" required>
            </div>
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" id="force_path_style" name="force_path_style" value="1">
              <label class="form-check-label" for="force_path_style">Force path style (normalmente para R2/S3 compatibilidade)</label>
            </div>
            <button type="submit" class="btn btn-primary">Salvar storage</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7 mb-3">
      <div class="card h-100">
        <div class="card-header"><strong>Storages globais cadastrados</strong></div>
        <div class="card-body p-0">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Nome</th>
                <th>Provider</th>
                <th>Bucket padrao</th>
                <th>Uso em empresas</th>
                <th>Status</th>
                <th>Acoes</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($storages === []): ?>
                <tr>
                  <td colspan="6" class="text-center text-body-secondary py-4">Nenhum storage global cadastrado.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($storages as $storage): ?>
                  <?php $isActive = (string) ($storage['status'] ?? '') === 'active'; ?>
                  <tr>
                    <td>
                      <strong><?= htmlspecialchars((string) $storage['name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                      <small class="text-body-secondary">
                        key: <?= htmlspecialchars((string) ($storage['access_key_hint'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                        /
                        secret: <?= htmlspecialchars((string) ($storage['secret_key_hint'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                      </small>
                    </td>
                    <td><?= htmlspecialchars(backup_storage_provider_label((string) $storage['provider']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($storage['default_bucket'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= number_format((int) ($storage['enabled_companies'] ?? 0), 0, ',', '.') ?></td>
                    <td>
                      <span class="badge <?= $isActive ? 'text-bg-success' : 'text-bg-secondary' ?>">
                        <?= htmlspecialchars((string) ($storage['status'] ?? 'inactive'), ENT_QUOTES, 'UTF-8') ?>
                      </span>
                    </td>
                    <td>
                      <form method="post" action="/backup_storage.php" class="d-inline">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="storage_id" value="<?= (int) $storage['id'] ?>">
                        <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>">
                        <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                          <?= $isActive ? 'Inativar' : 'Ativar' ?>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php
ui_page_end();
