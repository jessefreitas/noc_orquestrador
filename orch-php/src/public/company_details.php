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
    flash_set('warning', 'Somente o gestor global pode editar cadastro de empresa e vinculos de servicos.');
    redirect('/');
}
$userId = (int) $user['id'];
$companyId = (int) ($_GET['id'] ?? $_POST['company_id'] ?? 0);

if ($companyId <= 0) {
    flash_set('warning', 'Empresa invalida.');
    redirect('/projects.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        flash_set('danger', 'CSRF invalido.');
        redirect('/company_details.php?id=' . $companyId);
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_company') {
            update_company_profile(
                $userId,
                $companyId,
                [
                    'name' => (string) ($_POST['name'] ?? ''),
                    'legal_name' => (string) ($_POST['legal_name'] ?? ''),
                    'tax_id' => (string) ($_POST['tax_id'] ?? ''),
                    'billing_email' => (string) ($_POST['billing_email'] ?? ''),
                    'phone' => (string) ($_POST['phone'] ?? ''),
                    'alert_email' => (string) ($_POST['alert_email'] ?? ''),
                    'alert_phone' => (string) ($_POST['alert_phone'] ?? ''),
                    'alert_whatsapp' => (string) ($_POST['alert_whatsapp'] ?? ''),
                    'timezone' => (string) ($_POST['timezone'] ?? ''),
                    'notes' => (string) ($_POST['notes'] ?? ''),
                ]
            );
            flash_set('success', 'Empresa atualizada com sucesso.');
            redirect('/company_details.php?id=' . $companyId);
        }

        if ($action === 'add_contact') {
            create_company_alert_contact(
                $userId,
                $companyId,
                [
                    'name' => (string) ($_POST['contact_name'] ?? ''),
                    'role' => (string) ($_POST['contact_role'] ?? ''),
                    'email' => (string) ($_POST['contact_email'] ?? ''),
                    'phone' => (string) ($_POST['contact_phone'] ?? ''),
                    'whatsapp' => (string) ($_POST['contact_whatsapp'] ?? ''),
                    'receive_incident_alerts' => isset($_POST['receive_incident_alerts']),
                    'receive_billing_alerts' => isset($_POST['receive_billing_alerts']),
                ]
            );
            flash_set('success', 'Contato de alerta adicionado.');
            redirect('/company_details.php?id=' . $companyId);
        }

        if ($action === 'delete_contact') {
            $contactId = (int) ($_POST['contact_id'] ?? 0);
            if ($contactId <= 0) {
                throw new RuntimeException('Contato invalido.');
            }
            delete_company_alert_contact($userId, $companyId, $contactId);
            flash_set('success', 'Contato removido.');
            redirect('/company_details.php?id=' . $companyId);
        }

        if ($action === 'archive_company') {
            if (!isset($_POST['confirm_archive'])) {
                throw new RuntimeException('Confirme o arquivamento da empresa.');
            }
            archive_company($userId, $companyId);
            flash_set('success', 'Empresa arquivada com sucesso.');
            redirect('/projects.php');
        }

        if ($action === 'sync_services') {
            $enabledProviders = $_POST['enabled_providers'] ?? [];
            if (!is_array($enabledProviders)) {
                $enabledProviders = [];
            }
            sync_company_enabled_providers($userId, $companyId, $enabledProviders);
            flash_set('success', 'Servicos habilitados atualizados com sucesso.');
            redirect('/company_details.php?id=' . $companyId . '#servicos-habilitados');
        }

        if ($action === 'save_backup_policy') {
            save_company_backup_policy(
                $userId,
                $companyId,
                [
                    'enabled' => isset($_POST['backup_enabled']),
                    'global_backup_storage_id' => (int) ($_POST['global_backup_storage_id'] ?? 0),
                    'postgres_bucket' => (string) ($_POST['postgres_bucket'] ?? ''),
                    'postgres_prefix' => (string) ($_POST['postgres_prefix'] ?? ''),
                    'retention_days' => (int) ($_POST['retention_days'] ?? 30),
                    'billing_enabled' => isset($_POST['billing_enabled']),
                    'monthly_price' => (string) ($_POST['monthly_price'] ?? ''),
                    'currency' => (string) ($_POST['currency'] ?? 'BRL'),
                    'notes' => (string) ($_POST['backup_notes'] ?? ''),
                ]
            );
            flash_set('success', 'Politica de backup da empresa atualizada.');
            redirect('/company_details.php?id=' . $companyId . '#backup-postgres');
        }

        flash_set('warning', 'Acao invalida.');
        redirect('/company_details.php?id=' . $companyId);
    } catch (Throwable $exception) {
        flash_set('danger', $exception->getMessage());
        redirect('/company_details.php?id=' . $companyId);
    }
}

$context = load_user_context($userId);
$flash = flash_pull();
$company = get_company_for_user($userId, $companyId);
if ($company === null) {
    flash_set('danger', 'Empresa nao encontrada ou sem acesso.');
    redirect('/projects.php');
}
$contacts = list_company_alert_contacts($userId, $companyId);
$providerCatalog = provider_catalog();
$enabledProviders = enabled_provider_types_for_company($userId, $companyId);
$serviceBindings = list_company_service_bindings($userId, $companyId);
$backupStorageReady = backup_storage_ready();
$backupStorages = $backupStorageReady ? list_global_backup_storages(true) : [];
$backupPolicy = $backupStorageReady ? get_company_backup_policy($userId, $companyId) : null;
if (!is_array($backupPolicy)) {
    $backupPolicy = [
        'global_backup_storage_id' => null,
        'enabled' => false,
        'billing_enabled' => false,
        'monthly_price' => null,
        'currency' => 'BRL',
        'postgres_bucket' => null,
        'postgres_prefix' => 'postgres',
        'retention_days' => 30,
        'notes' => null,
    ];
}

ui_page_start('OmniNOC | Empresa');
ui_navigation('projects', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Empresa: <?= htmlspecialchars((string) $company['name'], ENT_QUOTES, 'UTF-8') ?></h3>
    <small class="text-body-secondary">Edite o cadastro, contatos de alerta e os servicos licenciados desta empresa.</small>
  </div>
  <div class="d-flex gap-2">
    <a href="#servicos-habilitados" class="btn btn-primary">Servicos habilitados</a>
    <a href="#backup-postgres" class="btn btn-outline-primary">Backup Postgres</a>
    <a href="/projects.php" class="btn btn-outline-secondary">Voltar</a>
  </div>
</div>

<div class="row">
  <div class="col-lg-8 mb-3">
    <div class="card">
      <div class="card-header"><strong>Dados da empresa</strong></div>
      <div class="card-body">
        <form method="post" action="/company_details.php?id=<?= (int) $company['id'] ?>">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="update_company">
          <input type="hidden" name="company_id" value="<?= (int) $company['id'] ?>">
          <div class="row">
            <div class="col-lg-6 mb-3">
              <label class="form-label">Nome fantasia</label>
              <input type="text" class="form-control" name="name" value="<?= htmlspecialchars((string) $company['name'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="col-lg-6 mb-3">
              <label class="form-label">Razao social</label>
              <input type="text" class="form-control" name="legal_name" value="<?= htmlspecialchars((string) ($company['legal_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-lg-4 mb-3">
              <label class="form-label">CNPJ/Documento</label>
              <input type="text" class="form-control" name="tax_id" value="<?= htmlspecialchars((string) ($company['tax_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-lg-4 mb-3">
              <label class="form-label">Email financeiro</label>
              <input type="email" class="form-control" name="billing_email" value="<?= htmlspecialchars((string) ($company['billing_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-lg-4 mb-3">
              <label class="form-label">Telefone principal</label>
              <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars((string) ($company['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-lg-4 mb-3">
              <label class="form-label">Email de alerta</label>
              <input type="email" class="form-control" name="alert_email" value="<?= htmlspecialchars((string) ($company['alert_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-lg-4 mb-3">
              <label class="form-label">Telefone de alerta</label>
              <input type="text" class="form-control" name="alert_phone" value="<?= htmlspecialchars((string) ($company['alert_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-lg-4 mb-3">
              <label class="form-label">WhatsApp de alerta</label>
              <input type="text" class="form-control" name="alert_whatsapp" value="<?= htmlspecialchars((string) ($company['alert_whatsapp'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-lg-4 mb-3">
              <label class="form-label">Timezone</label>
              <input type="text" class="form-control" name="timezone" value="<?= htmlspecialchars((string) ($company['timezone'] ?? 'America/Sao_Paulo'), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-lg-8 mb-3">
              <label class="form-label">Observacoes</label>
              <input type="text" class="form-control" name="notes" value="<?= htmlspecialchars((string) ($company['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
          </div>
          <button type="submit" class="btn btn-primary">Salvar alteracoes</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-4 mb-3">
    <div class="card mb-3">
      <div class="card-header"><strong>Status</strong></div>
      <div class="card-body">
        <p class="mb-2">Status atual: <strong><?= htmlspecialchars((string) $company['status'], ENT_QUOTES, 'UTF-8') ?></strong></p>
        <p class="text-body-secondary mb-3">Ao arquivar, a empresa sai da operacao e do seletor de contexto.</p>
        <form method="post" action="/company_details.php?id=<?= (int) $company['id'] ?>">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="archive_company">
          <input type="hidden" name="company_id" value="<?= (int) $company['id'] ?>">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="confirm_archive" name="confirm_archive" value="1">
            <label class="form-check-label" for="confirm_archive">Confirmo arquivar esta empresa</label>
          </div>
          <button type="submit" class="btn btn-outline-danger w-100">Arquivar empresa</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><strong>Licenca</strong></div>
      <div class="card-body">
        <p class="text-body-secondary mb-0">Os servicos ativos desta empresa sao configurados na secao <strong>Servicos habilitados</strong>.</p>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3" id="servicos-habilitados">
  <div class="card-header"><strong>Servicos habilitados (licenca de uso)</strong></div>
  <div class="card-body">
    <form method="post" action="/company_details.php?id=<?= (int) $company['id'] ?>">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="sync_services">
      <input type="hidden" name="company_id" value="<?= (int) $company['id'] ?>">
      <div class="row">
        <?php foreach ($providerCatalog as $providerType => $providerLabel): ?>
          <div class="col-lg-3 col-md-4 col-6 mb-2">
            <div class="form-check">
              <input
                class="form-check-input"
                type="checkbox"
                id="provider_<?= htmlspecialchars($providerType, ENT_QUOTES, 'UTF-8') ?>"
                name="enabled_providers[]"
                value="<?= htmlspecialchars($providerType, ENT_QUOTES, 'UTF-8') ?>"
                <?= in_array($providerType, $enabledProviders, true) ? 'checked' : '' ?>
              >
              <label class="form-check-label" for="provider_<?= htmlspecialchars($providerType, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') ?>
              </label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn btn-primary mt-2">Salvar servicos habilitados</button>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><strong>Vinculos de servico criados</strong></div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Servico</th>
          <th>Tipo</th>
          <th>Status</th>
          <th>Acoes</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($serviceBindings === []): ?>
          <tr>
            <td colspan="4" class="text-center text-body-secondary py-4">Nenhum servico vinculado para esta empresa.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($serviceBindings as $providerType => $projects): ?>
            <?php foreach ($projects as $serviceProject): ?>
              <tr>
                <td><?= htmlspecialchars((string) $serviceProject['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><code><?= htmlspecialchars((string) $providerType, ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars((string) ($serviceProject['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <a href="/project_details.php?id=<?= (int) $serviceProject['id'] ?>" class="btn btn-sm btn-outline-primary">Abrir detalhes</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3" id="backup-postgres">
  <div class="card-header"><strong>Backup de banco (PostgreSQL)</strong></div>
  <div class="card-body">
    <?php if (!$backupStorageReady): ?>
      <div class="alert alert-warning mb-0">
        Estrutura de backup storage nao encontrada no banco atual.
        <div class="mt-2"><code><?= htmlspecialchars(backup_storage_migration_hint_command(), ENT_QUOTES, 'UTF-8') ?></code></div>
      </div>
    <?php elseif ($backupStorages === []): ?>
      <div class="alert alert-warning mb-3">
        Nenhum storage global ativo cadastrado.
        <a href="/backup_storage.php" class="alert-link">Cadastre um storage global de backup</a> e retorne para habilitar nesta empresa.
      </div>
    <?php endif; ?>

    <?php
    $backupEnabled = (bool) (($backupPolicy['enabled'] ?? false) === true || (int) ($backupPolicy['enabled'] ?? 0) === 1);
    $billingEnabled = (bool) (($backupPolicy['billing_enabled'] ?? false) === true || (int) ($backupPolicy['billing_enabled'] ?? 0) === 1);
    ?>
    <form method="post" action="/company_details.php?id=<?= (int) $company['id'] ?>">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="save_backup_policy">
      <input type="hidden" name="company_id" value="<?= (int) $company['id'] ?>">
      <div class="row">
        <div class="col-lg-4 mb-3">
          <label class="form-label">Storage global</label>
          <select class="form-select" name="global_backup_storage_id" <?= $backupStorages === [] ? 'disabled' : '' ?>>
            <option value="0">Selecione</option>
            <?php foreach ($backupStorages as $storage): ?>
              <option
                value="<?= (int) $storage['id'] ?>"
                <?= (int) ($backupPolicy['global_backup_storage_id'] ?? 0) === (int) $storage['id'] ? 'selected' : '' ?>
              >
                <?= htmlspecialchars((string) $storage['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars(backup_storage_provider_label((string) $storage['provider']), ENT_QUOTES, 'UTF-8') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-lg-4 mb-3">
          <label class="form-label">Bucket Postgres da empresa</label>
          <input
            type="text"
            class="form-control"
            name="postgres_bucket"
            placeholder="cliente-x-postgres-backups"
            value="<?= htmlspecialchars((string) ($backupPolicy['postgres_bucket'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
          >
        </div>
        <div class="col-lg-4 mb-3">
          <label class="form-label">Prefixo</label>
          <input
            type="text"
            class="form-control"
            name="postgres_prefix"
            placeholder="postgres"
            value="<?= htmlspecialchars((string) ($backupPolicy['postgres_prefix'] ?? 'postgres'), ENT_QUOTES, 'UTF-8') ?>"
          >
        </div>
        <div class="col-lg-4 mb-3">
          <label class="form-label">Retencao (dias)</label>
          <input
            type="number"
            min="1"
            max="3650"
            class="form-control"
            name="retention_days"
            value="<?= (int) ($backupPolicy['retention_days'] ?? 30) ?>"
          >
        </div>
        <div class="col-lg-4 mb-3">
          <label class="form-label">Valor mensal (se cobrar)</label>
          <input
            type="text"
            class="form-control"
            name="monthly_price"
            placeholder="99.90"
            value="<?= htmlspecialchars((string) ($backupPolicy['monthly_price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
          >
        </div>
        <div class="col-lg-4 mb-3">
          <label class="form-label">Moeda</label>
          <input
            type="text"
            class="form-control"
            name="currency"
            placeholder="BRL"
            value="<?= htmlspecialchars((string) ($backupPolicy['currency'] ?? 'BRL'), ENT_QUOTES, 'UTF-8') ?>"
          >
        </div>
        <div class="col-12 mb-3">
          <label class="form-label">Observacoes de backup</label>
          <input
            type="text"
            class="form-control"
            name="backup_notes"
            placeholder="Regra de snapshot, janela de backup, SLA de restore..."
            value="<?= htmlspecialchars((string) ($backupPolicy['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
          >
        </div>
      </div>
      <div class="d-flex gap-4 flex-wrap mb-3">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="backup_enabled" name="backup_enabled" value="1" <?= $backupEnabled ? 'checked' : '' ?>>
          <label class="form-check-label" for="backup_enabled">Servico de backup habilitado para esta empresa</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="billing_enabled" name="billing_enabled" value="1" <?= $billingEnabled ? 'checked' : '' ?>>
          <label class="form-check-label" for="billing_enabled">Cobrar backup desta empresa</label>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" <?= (!$backupStorageReady || $backupStorages === []) ? 'disabled' : '' ?>>Salvar politica de backup</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><strong>Contatos de alerta</strong></div>
  <div class="card-body">
    <form method="post" action="/company_details.php?id=<?= (int) $company['id'] ?>" class="mb-4">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="add_contact">
      <input type="hidden" name="company_id" value="<?= (int) $company['id'] ?>">
      <div class="row">
        <div class="col-lg-3 mb-3">
          <label class="form-label">Nome</label>
          <input type="text" class="form-control" name="contact_name" required>
        </div>
        <div class="col-lg-2 mb-3">
          <label class="form-label">Funcao</label>
          <input type="text" class="form-control" name="contact_role">
        </div>
        <div class="col-lg-3 mb-3">
          <label class="form-label">Email</label>
          <input type="email" class="form-control" name="contact_email">
        </div>
        <div class="col-lg-2 mb-3">
          <label class="form-label">Telefone</label>
          <input type="text" class="form-control" name="contact_phone">
        </div>
        <div class="col-lg-2 mb-3">
          <label class="form-label">WhatsApp</label>
          <input type="text" class="form-control" name="contact_whatsapp">
        </div>
      </div>
      <div class="d-flex gap-3 mb-3">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="incident_alerts" name="receive_incident_alerts" value="1" checked>
          <label class="form-check-label" for="incident_alerts">Receber alertas de incidente</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="billing_alerts" name="receive_billing_alerts" value="1">
          <label class="form-check-label" for="billing_alerts">Receber alertas financeiros</label>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Adicionar contato</button>
    </form>

    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Funcao</th>
            <th>Email</th>
            <th>Telefone</th>
            <th>WhatsApp</th>
            <th>Alertas</th>
            <th>Acoes</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($contacts === []): ?>
            <tr>
              <td colspan="7" class="text-center text-body-secondary py-4">Nenhum contato cadastrado.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($contacts as $contact): ?>
              <?php
              $alerts = [];
              if ((bool) ($contact['receive_incident_alerts'] ?? false)) {
                  $alerts[] = 'Incidente';
              }
              if ((bool) ($contact['receive_billing_alerts'] ?? false)) {
                  $alerts[] = 'Financeiro';
              }
              ?>
              <tr>
                <td><?= htmlspecialchars((string) $contact['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($contact['role'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($contact['email'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($contact['phone'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($contact['whatsapp'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($alerts !== [] ? implode(', ', $alerts) : '-', ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <form method="post" action="/company_details.php?id=<?= (int) $company['id'] ?>" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="delete_contact">
                    <input type="hidden" name="company_id" value="<?= (int) $company['id'] ?>">
                    <input type="hidden" name="contact_id" value="<?= (int) $contact['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Remover</button>
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
<?php
ui_page_end();
