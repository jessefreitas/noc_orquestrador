<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/tenancy.php';
require_once __DIR__ . '/../app/ui.php';

require_auth();
$user = current_user();
if ($user === null) {
    redirect('/login.php');
}

$authUser = current_auth_user();
if (!is_platform_owner($authUser)) {
    flash_set('warning', 'Somente o gestor global pode usar emulacao de cliente.');
    redirect('/');
}

$userId = (int) ($authUser['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        flash_set('danger', 'CSRF invalido.');
        redirect('/impersonate.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    $redirectTo = (string) ($_POST['redirect_to'] ?? '/impersonate.php');
    if ($redirectTo === '' || strpos($redirectTo, '/') !== 0) {
        $redirectTo = '/impersonate.php';
    }

    try {
        if ($action === 'start') {
            $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
            if ($targetUserId <= 0) {
                throw new RuntimeException('Selecione um usuario para emular.');
            }

            $targetUser = find_user_by_id($targetUserId);
            if (!is_array($targetUser)) {
                throw new RuntimeException('Usuario nao encontrado.');
            }
            if (!user_has_company_links($targetUserId)) {
                throw new RuntimeException('Usuario sem empresa vinculada nao pode ser emulado no fluxo operacional.');
            }

            start_impersonation_as($targetUser);
            flash_set(
                'success',
                'Emulacao iniciada para ' . ((string) ($targetUser['name'] ?? $targetUser['email'] ?? 'usuario'))
            );
            redirect('/');
        }

        if ($action === 'stop') {
            stop_impersonation();
            flash_set('success', 'Emulacao encerrada. Voce voltou ao modo gestor global.');
            redirect($redirectTo);
        }

        flash_set('warning', 'Acao invalida.');
        redirect('/impersonate.php');
    } catch (Throwable $exception) {
        flash_set('danger', $exception->getMessage());
        redirect('/impersonate.php');
    }
}

$context = load_user_context($userId);
$flash = flash_pull();
$users = list_impersonable_users();
$ownerEmail = platform_owner_email();
$users = array_values(array_filter(
    $users,
    static fn (array $row): bool => strtolower((string) ($row['email'] ?? '')) !== $ownerEmail
));
$usersById = [];
foreach ($users as $row) {
    $usersById[(int) ($row['id'] ?? 0)] = $row;
}
$isImpersonating = is_impersonating();
$actingUser = current_user();
$actingUserInfo = is_array($actingUser) ? ($usersById[(int) ($actingUser['id'] ?? 0)] ?? null) : null;

ui_page_start('OmniNOC | Emular Cliente');
ui_navigation('impersonate', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Emular Cliente</h3>
    <small class="text-body-secondary">Ferramenta do gestor global para reproduzir problemas com o contexto real do cliente.</small>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><strong>Como ler esta tela</strong></div>
  <div class="card-body">
    <p class="mb-2"><strong>Identidade:</strong> usuario que voce esta emulando (cliente).</p>
    <p class="mb-2"><strong>Contexto:</strong> empresa + fornecedor selecionados no topo para operar dados.</p>
    <p class="mb-0 text-body-secondary">Para evitar erro de operacao, so permitimos emular usuarios que tenham empresa vinculada.</p>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><strong>Status da emulacao</strong></div>
  <div class="card-body">
    <?php if ($isImpersonating): ?>
      <div class="alert alert-warning mb-3">
        Emulacao ativa para
        <strong><?= htmlspecialchars((string) ($actingUser['email'] ?? 'usuario'), ENT_QUOTES, 'UTF-8') ?></strong>
        <?php if (is_array($actingUserInfo)): ?>
          (<?= (int) ($actingUserInfo['companies_count'] ?? 0) ?> empresa(s): <?= htmlspecialchars((string) ($actingUserInfo['companies'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>)
        <?php endif; ?>.
      </div>
      <form method="post" action="/impersonate.php">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="stop">
        <input type="hidden" name="redirect_to" value="/impersonate.php">
        <button type="submit" class="btn btn-outline-warning">Parar emulacao</button>
      </form>
    <?php else: ?>
      <div class="alert alert-success mb-0">Sem emulacao ativa. Voce esta operando como gestor global.</div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><strong>Usuarios disponiveis para emular</strong></div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Nome</th>
          <th>Email</th>
          <th>Tipo de usuario</th>
          <th>Papel</th>
          <th>Qtd empresas</th>
          <th>Empresas vinculadas</th>
          <th>Acoes</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($users === []): ?>
          <tr><td colspan="7" class="text-center text-body-secondary py-4">Nenhum usuario de cliente encontrado.</td></tr>
        <?php else: ?>
          <?php foreach ($users as $target): ?>
            <?php
            $companiesCount = (int) ($target['companies_count'] ?? 0);
            $isClientUser = $companiesCount > 0;
            $userTypeLabel = $isClientUser ? 'Cliente' : 'Interno sem empresa';
            $userTypeClass = $isClientUser ? 'text-bg-primary' : 'text-bg-secondary';
            ?>
            <tr>
              <td><?= htmlspecialchars((string) $target['name'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) $target['email'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><span class="badge <?= $userTypeClass ?>"><?= htmlspecialchars($userTypeLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
              <td><?= htmlspecialchars((string) ($target['company_roles'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= number_format($companiesCount, 0, ',', '.') ?></td>
              <td><?= htmlspecialchars((string) ($target['companies'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <form method="post" action="/impersonate.php" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="start">
                  <input type="hidden" name="target_user_id" value="<?= (int) $target['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-primary" <?= $isClientUser ? '' : 'disabled' ?>>Emular usuario</button>
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
