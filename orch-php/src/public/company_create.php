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
    flash_set('warning', 'Somente o gestor global pode criar empresas.');
    redirect('/');
}
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        flash_set('danger', 'CSRF invalido.');
        redirect('/company_create.php');
    }

    try {
        $companyId = create_company(
            $userId,
            (string) ($_POST['company_name'] ?? ''),
            [
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

        $primaryContactName = trim((string) ($_POST['primary_contact_name'] ?? ''));
        if ($primaryContactName !== '') {
            create_company_alert_contact(
                $userId,
                $companyId,
                [
                    'name' => $primaryContactName,
                    'role' => (string) ($_POST['primary_contact_role'] ?? ''),
                    'email' => (string) ($_POST['primary_contact_email'] ?? ''),
                    'phone' => (string) ($_POST['primary_contact_phone'] ?? ''),
                    'whatsapp' => (string) ($_POST['primary_contact_whatsapp'] ?? ''),
                    'receive_incident_alerts' => true,
                    'receive_billing_alerts' => true,
                ]
            );
        }

        flash_set('success', 'Empresa criada com sucesso.');
        redirect('/company_details.php?id=' . $companyId);
    } catch (Throwable $exception) {
        flash_set('danger', $exception->getMessage());
        redirect('/company_create.php');
    }
}

$context = load_user_context($userId);
$flash = flash_pull();

ui_page_start('OmniNOC | Criar Empresa');
ui_navigation('company', $user, $context, $flash);
?>
<div class="row justify-content-center">
  <div class="col-xl-10">
    <div class="card">
      <div class="card-header"><strong>Criar empresa</strong></div>
      <div class="card-body">
        <form method="post" action="/company_create.php">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <div class="row">
            <div class="col-lg-6 mb-3">
              <label class="form-label">Nome da empresa</label>
              <input type="text" class="form-control" name="company_name" required>
            </div>
            <div class="col-lg-6 mb-3">
              <label class="form-label">Razao social (opcional)</label>
              <input type="text" class="form-control" name="legal_name">
            </div>
            <div class="col-lg-4 mb-3">
              <label class="form-label">CNPJ/Documento</label>
              <input type="text" class="form-control" name="tax_id">
            </div>
            <div class="col-lg-4 mb-3">
              <label class="form-label">Email financeiro</label>
              <input type="email" class="form-control" name="billing_email" placeholder="financeiro@empresa.com">
            </div>
            <div class="col-lg-4 mb-3">
              <label class="form-label">Telefone principal</label>
              <input type="text" class="form-control" name="phone" placeholder="+55 11 99999-0000">
            </div>
          </div>

          <hr>
          <h6 class="mb-3">Canais de alerta da empresa</h6>
          <div class="row">
            <div class="col-lg-4 mb-3">
              <label class="form-label">Email de alerta</label>
              <input type="email" class="form-control" name="alert_email" placeholder="noc-alertas@empresa.com">
            </div>
            <div class="col-lg-4 mb-3">
              <label class="form-label">Telefone de alerta</label>
              <input type="text" class="form-control" name="alert_phone" placeholder="+55 11 99999-0000">
            </div>
            <div class="col-lg-4 mb-3">
              <label class="form-label">WhatsApp de alerta</label>
              <input type="text" class="form-control" name="alert_whatsapp" placeholder="+55 11 99999-0000">
            </div>
            <div class="col-lg-4 mb-3">
              <label class="form-label">Timezone</label>
              <input type="text" class="form-control" name="timezone" value="America/Sao_Paulo">
            </div>
            <div class="col-lg-8 mb-3">
              <label class="form-label">Observacoes</label>
              <input type="text" class="form-control" name="notes" placeholder="SLA, janela de manutencao, regras de acionamento...">
            </div>
          </div>

          <hr>
          <h6 class="mb-3">Contato responsavel inicial (opcional)</h6>
          <div class="row">
            <div class="col-lg-4 mb-3">
              <label class="form-label">Nome</label>
              <input type="text" class="form-control" name="primary_contact_name">
            </div>
            <div class="col-lg-3 mb-3">
              <label class="form-label">Funcao</label>
              <input type="text" class="form-control" name="primary_contact_role" placeholder="Gestor de TI">
            </div>
            <div class="col-lg-5 mb-3">
              <label class="form-label">Email</label>
              <input type="email" class="form-control" name="primary_contact_email">
            </div>
            <div class="col-lg-6 mb-3">
              <label class="form-label">Telefone</label>
              <input type="text" class="form-control" name="primary_contact_phone">
            </div>
            <div class="col-lg-6 mb-3">
              <label class="form-label">WhatsApp</label>
              <input type="text" class="form-control" name="primary_contact_whatsapp">
            </div>
          </div>
          <p class="text-body-secondary mb-3">
            Depois de salvar, acesse os detalhes da empresa para habilitar os servicos licenciados e configurar alertas.
          </p>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Salvar empresa</button>
            <a href="/projects.php" class="btn btn-outline-secondary">Voltar</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php
ui_page_end();
