<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/tenancy.php';

require_auth();
$user = current_user();
if ($user === null) {
    redirect('/login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/');
}

$csrf = $_POST['_csrf'] ?? null;
if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
    flash_set('danger', 'CSRF invalido.');
    redirect('/');
}

$companyId = (int) ($_POST['company_id'] ?? 0);
$projectId = (int) ($_POST['service_id'] ?? ($_POST['project_id'] ?? 0));
$redirectTo = (string) ($_POST['redirect_to'] ?? '/');
if ($redirectTo === '' || strpos($redirectTo, '/') !== 0) {
    $redirectTo = '/';
}

if ($companyId <= 0) {
    flash_set('warning', 'Selecione uma empresa valida.');
    redirect($redirectTo);
}

$ok = set_user_context((int) $user['id'], $companyId, $projectId);
if (!$ok) {
    flash_set('danger', 'Nao foi possivel trocar o contexto.');
    redirect($redirectTo);
}

if ($projectId <= 0) {
    flash_set('success', 'Empresa aplicada. Agora selecione o modulo no menu para carregar os dados.');
    redirect($redirectTo);
}

flash_set('success', 'Contexto atualizado para empresa e fornecedor.');

redirect($redirectTo);
