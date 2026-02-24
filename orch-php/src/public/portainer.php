<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/portainer.php';
require_once __DIR__ . '/../app/tenancy.php';
require_once __DIR__ . '/../app/ui.php';

require_auth();
$user = current_user();
if ($user === null) {
    redirect('/login.php');
}

$context = load_user_context((int) $user['id']);
$companyId = $context['company_id'];
$projectId = $context['project_id'];
$providerType = context_provider_type($context);
$canManage = is_int($companyId) ? user_can_manage_company_context((int) $user['id'], $companyId, $user) : false;

$tab = strtolower(trim((string) ($_GET['tab'] ?? 'cadastro')));
$allowedTabs = ['dashboard', 'cadastro', 'services', 'volumes', 'networks', 'containers', 'stacks'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'cadastro';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        flash_set('danger', 'CSRF invalido.');
        redirect('/portainer.php?tab=' . urlencode($tab));
    }
    if (!$canManage || !is_int($companyId) || !is_int($projectId) || $providerType !== 'portainer') {
        flash_set('danger', 'Contexto Portainer invalido ou sem permissao de escrita.');
        redirect('/portainer.php?tab=' . urlencode($tab));
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_portainer_account') {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $label = (string) ($_POST['label'] ?? '');
            $baseUrl = (string) ($_POST['base_url'] ?? '');
            $apiKey = (string) ($_POST['api_key'] ?? '');
            $insecureTls = isset($_POST['insecure_tls']);

            if ($accountId > 0) {
                update_portainer_account($companyId, $projectId, $accountId, (int) $user['id'], $label, $baseUrl, $apiKey !== '' ? $apiKey : null, $insecureTls);
                $test = test_portainer_account($companyId, $projectId, $accountId);
                if (($test['ok'] ?? false) === true) {
                    flash_set('success', 'Conta Portainer atualizada e testada com sucesso.');
                } else {
                    flash_set('warning', 'Conta atualizada, mas o teste automatico falhou: ' . (string) ($test['message'] ?? 'erro desconhecido'));
                }
                redirect('/portainer.php?tab=' . urlencode($tab) . '&account_id=' . $accountId);
            }

            $newId = create_portainer_account($companyId, $projectId, (int) $user['id'], $label, $baseUrl, $apiKey, $insecureTls);
            $test = test_portainer_account($companyId, $projectId, $newId);
            if (($test['ok'] ?? false) === true) {
                flash_set('success', 'Conta Portainer cadastrada e testada com sucesso.');
            } else {
                flash_set('warning', 'Conta cadastrada, mas o teste automatico falhou: ' . (string) ($test['message'] ?? 'erro desconhecido'));
            }
            redirect('/portainer.php?tab=' . urlencode($tab) . '&account_id=' . $newId);
        }

        if ($action === 'test_portainer_account') {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            if ($accountId <= 0) {
                throw new RuntimeException('Selecione a conta para testar.');
            }
            $test = test_portainer_account($companyId, $projectId, $accountId);
            flash_set(($test['ok'] ?? false) ? 'success' : 'danger', (string) ($test['message'] ?? 'Falha no teste.'));
            redirect('/portainer.php?tab=' . urlencode($tab) . '&account_id=' . $accountId);
        }

        if ($action === 'deploy_stack') {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $endpointId = (int) ($_POST['endpoint_id'] ?? 0);
            $stackId = (int) ($_POST['stack_id'] ?? 0);
            $stackContent = (string) ($_POST['stack_content'] ?? '');
            $prune = isset($_POST['prune']);
            $pullImage = isset($_POST['pull_image']);
            if ($accountId <= 0 || $endpointId <= 0 || $stackId <= 0 || trim($stackContent) === '') {
                throw new RuntimeException('Parametros invalidos para deploy da stack.');
            }
            $account = get_portainer_account($companyId, $projectId, $accountId);
            if (!is_array($account)) {
                throw new RuntimeException('Conta Portainer nao encontrada para deploy.');
            }
            $accountScopes = portainer_account_scopes((string) ($account['scopes'] ?? ''));
            $baseUrl = portainer_normalize_base_url((string) ($accountScopes['base_url'] ?? ''));
            $apiKey = decrypt_secret((string) ($account['token_ciphertext'] ?? ''));
            $url = $baseUrl . '/stacks/' . $stackId . '?endpointId=' . $endpointId;
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Falha ao iniciar deploy stack.');
            }
            $body = json_encode([
                'StackFileContent' => $stackContent,
                'Prune' => $prune,
                'PullImage' => $pullImage,
            ], JSON_UNESCAPED_SLASHES);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'X-API-Key: ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_TIMEOUT => 40,
            ]);
            $raw = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false || $err !== '') {
                throw new RuntimeException('Falha no deploy stack: ' . $err);
            }
            if ($http < 200 || $http >= 300) {
                throw new RuntimeException('Deploy stack falhou. HTTP ' . $http . '.');
            }

            flash_set('success', 'Stack atualizada e deploy solicitado com sucesso.');
            redirect('/portainer.php?tab=stacks&account_id=' . $accountId . '&endpoint_id=' . $endpointId . '&stack_id=' . $stackId);
        }

        if ($action === 'collect_container_logs') {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $endpointId = (int) ($_POST['endpoint_id'] ?? 0);
            $containerId = trim((string) ($_POST['container_id'] ?? ''));
            $containerName = trim((string) ($_POST['container_name'] ?? ''));
            $tail = max(20, min(5000, (int) ($_POST['tail'] ?? 500)));
            $archiveR2 = isset($_POST['archive_r2']);
            if ($accountId <= 0 || $endpointId <= 0 || $containerId === '') {
                throw new RuntimeException('Parametros invalidos para coleta de logs.');
            }
            $account = get_portainer_account($companyId, $projectId, $accountId);
            if (!is_array($account)) {
                throw new RuntimeException('Conta Portainer nao encontrada para coleta de logs.');
            }
            $logsResp = portainer_api_request(
                $account,
                'GET',
                '/endpoints/' . $endpointId . '/docker/containers/' . rawurlencode($containerId) . '/logs',
                ['stdout' => 1, 'stderr' => 1, 'tail' => $tail, 'timestamps' => 1]
            );
            if (($logsResp['status'] ?? 0) < 200 || ($logsResp['status'] ?? 0) >= 300) {
                throw new RuntimeException('Falha ao coletar logs. HTTP ' . (int) ($logsResp['status'] ?? 0));
            }
            $rawLogs = (string) ($logsResp['raw'] ?? '');
            if (trim($rawLogs) === '') {
                throw new RuntimeException('Container sem logs no recorte atual.');
            }
            if ($archiveR2) {
                $archive = archive_portainer_container_logs_to_r2(
                    $companyId,
                    $projectId,
                    $accountId,
                    (int) $user['id'],
                    $endpointId,
                    $containerId,
                    $containerName,
                    $rawLogs,
                    'tail-' . $tail
                );
                if (($archive['ok'] ?? false) !== true) {
                    throw new RuntimeException('Logs coletados, mas falha no arquivo R2: ' . (string) ($archive['error'] ?? 'erro desconhecido'));
                }
                flash_set('success', 'Logs coletados e arquivados no R2. Arquivo: ' . (string) ($archive['object_key'] ?? '-'));
            } else {
                flash_set('success', 'Logs coletados com sucesso.');
            }
            $_SESSION['portainer_last_logs'] = [
                'container_id' => $containerId,
                'container_name' => $containerName,
                'tail' => $tail,
                'content' => $rawLogs,
                'created_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            ];
            redirect('/portainer.php?tab=containers&account_id=' . $accountId . '&endpoint_id=' . $endpointId);
        }

        flash_set('warning', 'Acao invalida.');
    } catch (Throwable $exception) {
        flash_set('danger', $exception->getMessage());
    }
    redirect('/portainer.php?tab=' . urlencode($tab));
}

$accounts = [];
$selectedAccount = null;
$selectedAccountId = (int) ($_GET['account_id'] ?? 0);
$isCadastroNew = ((string) ($_GET['new'] ?? '0')) === '1';
$selectedEndpointId = (int) ($_GET['endpoint_id'] ?? 0);
$selectedStackId = (int) ($_GET['stack_id'] ?? 0);
$endpoints = [];
$rows = [];
$apiError = null;
$stackFileContent = '';
$stackEditorName = '';
$stackArchives = [];
$lastLogs = isset($_SESSION['portainer_last_logs']) && is_array($_SESSION['portainer_last_logs']) ? $_SESSION['portainer_last_logs'] : null;
$dashboardMetrics = [
    'accounts_total' => 0,
    'accounts_active' => 0,
    'accounts_error' => 0,
    'api_errors' => 0,
    'endpoints' => 0,
    'services' => 0,
    'volumes' => 0,
    'networks' => 0,
    'containers' => 0,
    'containers_running' => 0,
    'containers_stopped' => 0,
    'container_states' => [
        'running' => 0,
        'exited' => 0,
        'paused' => 0,
        'restarting' => 0,
        'created' => 0,
        'dead' => 0,
        'other' => 0,
    ],
    'stacks' => 0,
    'logs_r2_total' => 0,
];
if (is_int($companyId) && is_int($projectId) && $providerType === 'portainer') {
    $accounts = list_portainer_accounts($companyId, $projectId);
    $dashboardMetrics['accounts_total'] = count($accounts);
    $dashboardMetrics['logs_r2_total'] = count_portainer_log_archives_total($companyId, $projectId);
    foreach ($accounts as $accountItem) {
        $status = strtolower((string) ($accountItem['status'] ?? ''));
        if ($status === 'active') {
            $dashboardMetrics['accounts_active']++;
        }
        if (in_array($status, ['error', 'invalid'], true)) {
            $dashboardMetrics['accounts_error']++;
        }
    }

    if ($selectedAccountId <= 0 && $accounts !== [] && !$isCadastroNew) {
        $selectedAccountId = (int) ($accounts[0]['id'] ?? 0);
    }
    if ($selectedAccountId > 0) {
        $selectedAccount = get_portainer_account($companyId, $projectId, $selectedAccountId);
    }

    if ($tab === 'dashboard') {
        $stackArchives = list_portainer_log_archives_all($companyId, $projectId, 20);
        foreach ($accounts as $accountItem) {
            $accountFull = get_portainer_account($companyId, $projectId, (int) ($accountItem['id'] ?? 0));
            if (!is_array($accountFull)) {
                $dashboardMetrics['api_errors']++;
                continue;
            }
            try {
                $endpointsResp = portainer_api_request($accountFull, 'GET', '/endpoints');
                if (($endpointsResp['status'] ?? 0) < 200 || ($endpointsResp['status'] ?? 0) >= 300) {
                    $dashboardMetrics['api_errors']++;
                    continue;
                }
                $payload = $endpointsResp['body'];
                $accountEndpoints = [];
                if (is_array($payload) && array_is_list($payload)) {
                    $accountEndpoints = $payload;
                } else {
                    $accountEndpoints = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                }
                $dashboardMetrics['endpoints'] += count($accountEndpoints);

                foreach ($accountEndpoints as $endpointRow) {
                    $endpointId = (int) ($endpointRow['Id'] ?? 0);
                    if ($endpointId <= 0) {
                        continue;
                    }

                    $servicesResp = portainer_api_request($accountFull, 'GET', '/endpoints/' . $endpointId . '/docker/services');
                    if (($servicesResp['status'] ?? 0) >= 200 && ($servicesResp['status'] ?? 0) < 300) {
                        $servicesRows = is_array($servicesResp['body']) && array_is_list($servicesResp['body']) ? $servicesResp['body'] : [];
                        $dashboardMetrics['services'] += count($servicesRows);
                    } else {
                        $dashboardMetrics['api_errors']++;
                    }

                    $volumesResp = portainer_api_request($accountFull, 'GET', '/endpoints/' . $endpointId . '/docker/volumes');
                    if (($volumesResp['status'] ?? 0) >= 200 && ($volumesResp['status'] ?? 0) < 300) {
                        $volRows = is_array($volumesResp['body']['Volumes'] ?? null) ? $volumesResp['body']['Volumes'] : [];
                        $dashboardMetrics['volumes'] += count($volRows);
                    } else {
                        $dashboardMetrics['api_errors']++;
                    }

                    $networksResp = portainer_api_request($accountFull, 'GET', '/endpoints/' . $endpointId . '/docker/networks');
                    if (($networksResp['status'] ?? 0) >= 200 && ($networksResp['status'] ?? 0) < 300) {
                        $netRows = is_array($networksResp['body']) && array_is_list($networksResp['body']) ? $networksResp['body'] : [];
                        $dashboardMetrics['networks'] += count($netRows);
                    } else {
                        $dashboardMetrics['api_errors']++;
                    }

                    $containersResp = portainer_api_request($accountFull, 'GET', '/endpoints/' . $endpointId . '/docker/containers/json', ['all' => 1]);
                    if (($containersResp['status'] ?? 0) >= 200 && ($containersResp['status'] ?? 0) < 300) {
                        $conRows = is_array($containersResp['body']) && array_is_list($containersResp['body']) ? $containersResp['body'] : [];
                        $dashboardMetrics['containers'] += count($conRows);
                        foreach ($conRows as $conRow) {
                            $state = strtolower((string) ($conRow['State'] ?? ''));
                            if ($state === 'running') {
                                $dashboardMetrics['containers_running']++;
                            } else {
                                $dashboardMetrics['containers_stopped']++;
                            }
                            if (array_key_exists($state, $dashboardMetrics['container_states'])) {
                                $dashboardMetrics['container_states'][$state]++;
                            } else {
                                $dashboardMetrics['container_states']['other']++;
                            }
                        }
                    } else {
                        $dashboardMetrics['api_errors']++;
                    }

                    $stacksResp = portainer_api_request($accountFull, 'GET', '/stacks', ['endpointId' => $endpointId]);
                    if (($stacksResp['status'] ?? 0) >= 200 && ($stacksResp['status'] ?? 0) < 300) {
                        $stackRows = is_array($stacksResp['body']) && array_is_list($stacksResp['body']) ? $stacksResp['body'] : [];
                        $dashboardMetrics['stacks'] += count($stackRows);
                    } else {
                        $dashboardMetrics['api_errors']++;
                    }
                }
            } catch (Throwable $exception) {
                $dashboardMetrics['api_errors']++;
            }
        }
    } elseif (is_array($selectedAccount)) {
        $stackArchives = list_portainer_log_archives($companyId, $projectId, $selectedAccountId, 20);
        try {
            $endpointsResp = portainer_api_request($selectedAccount, 'GET', '/endpoints');
            if (($endpointsResp['status'] ?? 0) >= 200 && ($endpointsResp['status'] ?? 0) < 300) {
                $payload = $endpointsResp['body'];
                if (is_array($payload) && array_is_list($payload)) {
                    $endpoints = $payload;
                } else {
                    $endpoints = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                }
            } else {
                $apiError = 'Falha ao listar endpoints. HTTP ' . (int) ($endpointsResp['status'] ?? 0);
            }
        } catch (Throwable $exception) {
            $apiError = $exception->getMessage();
        }

        if ($selectedEndpointId <= 0 && $endpoints !== []) {
            $selectedEndpointId = (int) ($endpoints[0]['Id'] ?? 0);
        }

        if ($selectedEndpointId > 0 && $apiError === null) {
            try {
                if ($tab === 'containers') {
                    $resp = portainer_api_request($selectedAccount, 'GET', '/endpoints/' . $selectedEndpointId . '/docker/containers/json', ['all' => 1]);
                    $rows = is_array($resp['body']) && array_is_list($resp['body']) ? $resp['body'] : [];
                } elseif ($tab === 'services') {
                    $resp = portainer_api_request($selectedAccount, 'GET', '/endpoints/' . $selectedEndpointId . '/docker/services');
                    $rows = is_array($resp['body']) && array_is_list($resp['body']) ? $resp['body'] : [];
                } elseif ($tab === 'volumes') {
                    $resp = portainer_api_request($selectedAccount, 'GET', '/endpoints/' . $selectedEndpointId . '/docker/volumes');
                    $rows = is_array($resp['body']['Volumes'] ?? null) ? $resp['body']['Volumes'] : [];
                } elseif ($tab === 'networks') {
                    $resp = portainer_api_request($selectedAccount, 'GET', '/endpoints/' . $selectedEndpointId . '/docker/networks');
                    $rows = is_array($resp['body']) && array_is_list($resp['body']) ? $resp['body'] : [];
                } else {
                    $resp = portainer_api_request($selectedAccount, 'GET', '/stacks', ['endpointId' => $selectedEndpointId]);
                    $rows = is_array($resp['body']) && array_is_list($resp['body']) ? $resp['body'] : [];
                    if ($selectedStackId <= 0 && $rows !== []) {
                        $selectedStackId = (int) ($rows[0]['Id'] ?? 0);
                    }
                    if ($selectedStackId > 0) {
                        $stackMeta = null;
                        foreach ($rows as $candidate) {
                            if ((int) ($candidate['Id'] ?? 0) === $selectedStackId) {
                                $stackMeta = $candidate;
                                break;
                            }
                        }
                        $stackEditorName = is_array($stackMeta) ? (string) ($stackMeta['Name'] ?? ('stack-' . $selectedStackId)) : ('stack-' . $selectedStackId);
                        $stackFileResp = portainer_api_request($selectedAccount, 'GET', '/stacks/' . $selectedStackId . '/file', ['endpointId' => $selectedEndpointId]);
                        if (($stackFileResp['status'] ?? 0) >= 200 && ($stackFileResp['status'] ?? 0) < 300) {
                            $stackFileContent = (string) (($stackFileResp['body']['StackFileContent'] ?? '') ?: '');
                        }
                    }
                }
            } catch (Throwable $exception) {
                $apiError = $exception->getMessage();
            }
        }
    }
}

$flash = flash_pull();
$tabLabels = [
    'dashboard' => 'Dashboard',
    'cadastro' => 'Cadastro',
    'services' => 'Servicos',
    'volumes' => 'Volumes',
    'networks' => 'Redes',
    'containers' => 'Containers',
    'stacks' => 'Stacks',
];
$tabLabel = $tabLabels[$tab] ?? ucfirst($tab);

ui_page_start('OmniNOC | Portainer');
ui_navigation('portainer', $user, $context, $flash);
?>
<section class="portainer-page">
  <div class="portainer-hero d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Fornecedor Portainer</h3>
      <small class="text-body-secondary">Espaco reservado para endpoints, stacks e operacoes de cluster.</small>
      <div class="mt-1"><span class="badge text-bg-secondary">Modulo: <?= htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8') ?></span></div>
      <div class="mt-1 small text-body-secondary">
        Projeto atual: <?= htmlspecialchars((string) ($context['project']['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
        (ID <?= (int) ($projectId ?? 0) ?>)
      </div>
    </div>
  </div>

<?php if ($providerType !== 'portainer'): ?>
  <div class="alert alert-warning">Selecione um contexto Portainer no topo para acessar este modulo.</div>
<?php else: ?>
  <?php if ($tab === 'cadastro'): ?>
    <div class="card mb-3 portainer-cadastro-card">
      <div class="card-header"><strong>Cadastro API Portainer</strong></div>
      <div class="card-body">
        <form method="get" action="/portainer.php" class="row g-3 mb-4">
          <input type="hidden" name="tab" value="cadastro">
          <div class="col-lg-8">
            <label class="form-label mb-1">Conta cadastrada</label>
            <select name="account_id" class="form-select" onchange="this.form.submit()">
              <option value="0">Nova conta</option>
              <?php foreach ($accounts as $account): ?>
                <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= (int) ($account['id'] ?? 0) === $selectedAccountId ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) ($account['label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-4 d-grid">
            <a href="/portainer.php?tab=cadastro&new=1" class="btn btn-outline-secondary portainer-new-account-btn">Cadastrar nova conta</a>
          </div>
        </form>

        <form method="post" action="/portainer.php?tab=cadastro" class="row g-3 align-items-end portainer-cadastro-form">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="save_portainer_account">
          <input type="hidden" name="account_id" value="<?= (int) ($selectedAccount['id'] ?? 0) ?>">
          <div class="col-lg-3">
            <label class="form-label mb-1">Label</label>
            <input type="text" class="form-control" name="label" required value="<?= htmlspecialchars((string) ($selectedAccount['label'] ?? 'Portainer principal'), ENT_QUOTES, 'UTF-8') ?>" <?= $canManage ? '' : 'disabled' ?>>
          </div>
          <div class="col-lg-4">
            <label class="form-label mb-1">Base URL</label>
            <?php $selectedScopes = is_array($selectedAccount) ? portainer_account_scopes((string) ($selectedAccount['scopes'] ?? '')) : []; ?>
            <input type="url" class="form-control" name="base_url" required placeholder="https://seu-portainer:9443/api" value="<?= htmlspecialchars((string) ($selectedScopes['base_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= $canManage ? '' : 'disabled' ?>>
          </div>
          <div class="col-lg-3">
            <label class="form-label mb-1">API Key <?= is_array($selectedAccount) ? '(opcional em update)' : '' ?></label>
            <input type="password" class="form-control" name="api_key" <?= is_array($selectedAccount) ? '' : 'required' ?> <?= $canManage ? '' : 'disabled' ?>>
          </div>
          <div class="col-lg-2 d-grid">
            <button type="submit" class="btn btn-primary" <?= $canManage ? '' : 'disabled' ?>>Salvar conta</button>
          </div>
          <div class="col-12">
            <div class="form-check portainer-insecure-wrap">
              <input class="form-check-input" type="checkbox" id="insecure_tls" name="insecure_tls" value="1" <?= (($selectedScopes['insecure_tls'] ?? true) ? 'checked' : '') ?> <?= $canManage ? '' : 'disabled' ?>>
              <label class="form-check-label" for="insecure_tls">Ignorar validacao TLS (homologacao/self-signed)</label>
            </div>
          </div>
        </form>

        <form method="post" action="/portainer.php?tab=cadastro&account_id=<?= (int) $selectedAccountId ?>" class="mt-3">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="test_portainer_account">
          <input type="hidden" name="account_id" value="<?= (int) $selectedAccountId ?>">
          <button type="submit" class="btn btn-outline-secondary" <?= ($selectedAccountId > 0 && $canManage) ? '' : 'disabled' ?>>Testar conexao</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($tab !== 'cadastro' && $tab !== 'dashboard'): ?>
    <form method="get" action="/portainer.php" class="row g-2 mb-3">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
      <div class="col-lg-5">
        <label class="form-label mb-1">Conta</label>
        <select name="account_id" class="form-select" onchange="this.form.submit()">
          <option value="0">Selecione</option>
          <?php foreach ($accounts as $account): ?>
            <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= (int) ($account['id'] ?? 0) === $selectedAccountId ? 'selected' : '' ?>>
              <?= htmlspecialchars((string) ($account['label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-5">
        <label class="form-label mb-1">Endpoint</label>
        <select name="endpoint_id" class="form-select" onchange="this.form.submit()">
          <option value="0">Selecione</option>
          <?php foreach ($endpoints as $ep): ?>
            <?php $epId = (int) ($ep['Id'] ?? 0); ?>
            <option value="<?= $epId ?>" <?= $epId === $selectedEndpointId ? 'selected' : '' ?>>
              <?= htmlspecialchars((string) (($ep['Name'] ?? 'Endpoint') . ' #' . $epId), ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-2 d-flex align-items-end justify-content-lg-end">
        <button type="submit" class="btn btn-outline-primary portainer-btn-compact">Atualizar</button>
      </div>
    </form>
  <?php endif; ?>

  <?php if ($apiError !== null): ?>
    <div class="alert alert-danger mb-3"><?= htmlspecialchars($apiError, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <?php if ($tab === 'dashboard'): ?>
  <div class="row mb-3">
    <div class="col-lg-3 col-sm-6 mb-2"><div class="card"><div class="card-body"><small class="text-body-secondary">Contas Portainer</small><h4 class="mb-0"><?= number_format((int) $dashboardMetrics['accounts_total'], 0, ',', '.') ?></h4></div></div></div>
    <div class="col-lg-3 col-sm-6 mb-2"><div class="card"><div class="card-body"><small class="text-body-secondary">Contas ativas</small><h4 class="mb-0"><?= number_format((int) $dashboardMetrics['accounts_active'], 0, ',', '.') ?></h4></div></div></div>
    <div class="col-lg-3 col-sm-6 mb-2"><div class="card"><div class="card-body"><small class="text-body-secondary">Endpoints totais</small><h4 class="mb-0"><?= number_format((int) $dashboardMetrics['endpoints'], 0, ',', '.') ?></h4></div></div></div>
    <div class="col-lg-3 col-sm-6 mb-2"><div class="card"><div class="card-body"><small class="text-body-secondary">Logs R2 (total)</small><h4 class="mb-0"><?= number_format((int) $dashboardMetrics['logs_r2_total'], 0, ',', '.') ?></h4></div></div></div>
  </div>
  <div class="row mb-3">
    <div class="col-lg-2 col-sm-6 mb-2"><div class="card"><div class="card-body"><small class="text-body-secondary">Servicos</small><h4 class="mb-0"><?= number_format((int) $dashboardMetrics['services'], 0, ',', '.') ?></h4></div></div></div>
    <div class="col-lg-2 col-sm-6 mb-2"><div class="card"><div class="card-body"><small class="text-body-secondary">Volumes</small><h4 class="mb-0"><?= number_format((int) $dashboardMetrics['volumes'], 0, ',', '.') ?></h4></div></div></div>
    <div class="col-lg-2 col-sm-6 mb-2"><div class="card"><div class="card-body"><small class="text-body-secondary">Redes</small><h4 class="mb-0"><?= number_format((int) $dashboardMetrics['networks'], 0, ',', '.') ?></h4></div></div></div>
    <div class="col-lg-2 col-sm-6 mb-2"><div class="card"><div class="card-body"><small class="text-body-secondary">Containers</small><h4 class="mb-0"><?= number_format((int) $dashboardMetrics['containers'], 0, ',', '.') ?></h4></div></div></div>
    <div class="col-lg-2 col-sm-6 mb-2"><div class="card"><div class="card-body"><small class="text-body-secondary">Running</small><h4 class="mb-0"><?= number_format((int) $dashboardMetrics['containers_running'], 0, ',', '.') ?></h4></div></div></div>
    <div class="col-lg-2 col-sm-6 mb-2"><div class="card"><div class="card-body"><small class="text-body-secondary">Stopped</small><h4 class="mb-0"><?= number_format((int) $dashboardMetrics['containers_stopped'], 0, ',', '.') ?></h4></div></div></div>
  </div>
  <div class="row mb-3">
    <div class="col-lg-3 col-sm-6 mb-2"><div class="card"><div class="card-body"><small class="text-body-secondary">Stacks</small><h4 class="mb-0"><?= number_format((int) $dashboardMetrics['stacks'], 0, ',', '.') ?></h4></div></div></div>
    <div class="col-lg-3 col-sm-6 mb-2"><div class="card"><div class="card-body"><small class="text-body-secondary">Contas com erro</small><h4 class="mb-0"><?= number_format((int) $dashboardMetrics['accounts_error'], 0, ',', '.') ?></h4></div></div></div>
    <div class="col-lg-3 col-sm-6 mb-2"><div class="card"><div class="card-body"><small class="text-body-secondary">Falhas API</small><h4 class="mb-0"><?= number_format((int) $dashboardMetrics['api_errors'], 0, ',', '.') ?></h4></div></div></div>
  </div>
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Status dos containers (geral)</strong>
      <small class="text-body-secondary"><?= number_format((int) $dashboardMetrics['containers'], 0, ',', '.') ?> total</small>
    </div>
    <div class="card-body p-0">
      <table class="table table-sm table-hover mb-0">
        <thead>
          <tr>
            <th>Status</th>
            <th>Quantidade</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dashboardMetrics['container_states'] as $state => $count): ?>
            <tr>
              <td><code><?= htmlspecialchars($state, ENT_QUOTES, 'UTF-8') ?></code></td>
              <td><?= number_format((int) $count, 0, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab !== 'cadastro' && $tab !== 'dashboard'): ?>
  <div class="row mb-3">
    <div class="col-lg-3 col-sm-6 mb-2">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Endpoints</small><h4 class="mb-0"><?= number_format(count($endpoints), 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-2">
      <div class="card"><div class="card-body"><small class="text-body-secondary"><?= htmlspecialchars(ucfirst($tab), ENT_QUOTES, 'UTF-8') ?></small><h4 class="mb-0"><?= number_format(count($rows), 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-2">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Logs coletados (R2)</small><h4 class="mb-0"><?= number_format(count($stackArchives), 0, ',', '.') ?></h4></div></div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-2">
      <div class="card"><div class="card-body"><small class="text-body-secondary">Conta ativa</small><h4 class="mb-0"><?= htmlspecialchars((string) ($selectedAccount['label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></h4></div></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab !== 'cadastro' && $tab !== 'dashboard'): ?>
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong><?= htmlspecialchars(ucfirst($tab), ENT_QUOTES, 'UTF-8') ?></strong>
      <small class="text-body-secondary"><?= number_format(count($rows), 0, ',', '.') ?> item(ns)</small>
    </div>
    <div class="card-body p-0">
      <table class="table table-hover mb-0 portainer-resource-table">
        <thead>
          <tr>
            <th class="portainer-col-name">Nome</th>
            <th class="portainer-col-status">Status</th>
            <th class="portainer-col-details">Detalhes</th>
            <?php if ($tab === 'containers'): ?><th>Acoes</th><?php endif; ?>
            <?php if ($tab === 'stacks'): ?><th>Acoes</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if ($selectedAccountId <= 0): ?>
            <tr><td colspan="<?= $tab === 'containers' || $tab === 'stacks' ? '4' : '3' ?>" class="text-center text-body-secondary py-4">Cadastre ou selecione uma conta Portainer.</td></tr>
          <?php elseif ($selectedEndpointId <= 0): ?>
            <tr><td colspan="<?= $tab === 'containers' || $tab === 'stacks' ? '4' : '3' ?>" class="text-center text-body-secondary py-4">Selecione um endpoint para listar recursos.</td></tr>
          <?php elseif ($rows === []): ?>
            <tr><td colspan="<?= $tab === 'containers' || $tab === 'stacks' ? '4' : '3' ?>" class="text-center text-body-secondary py-4">Nenhum recurso retornado para este endpoint/aba.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $row): ?>
              <?php
                $name = (string) ($row['Spec']['Name'] ?? $row['Name'] ?? $row['Id'] ?? '-');
                if ($tab === 'containers' && is_array($row['Names'] ?? null) && $row['Names'] !== []) {
                    $name = (string) ($row['Names'][0] ?? $name);
                }
                $status = (string) ($row['UpdateStatus']['State'] ?? $row['State'] ?? $row['Status'] ?? '-');
                $details = '';
                if ($tab === 'containers') {
                    $details = (string) (($row['Image'] ?? '-') . ' | ' . ($row['Created'] ?? '-'));
                } elseif ($tab === 'volumes') {
                    $details = (string) (($row['Driver'] ?? '-') . ' | ' . ($row['Mountpoint'] ?? '-'));
                } elseif ($tab === 'networks') {
                    $details = (string) (($row['Driver'] ?? '-') . ' | Scope: ' . ($row['Scope'] ?? '-'));
                } elseif ($tab === 'services') {
                    $details = (string) (($row['Spec']['TaskTemplate']['ContainerSpec']['Image'] ?? '-') . ' | Mode: ' . (array_key_exists('Replicated', (array) ($row['Spec']['Mode'] ?? [])) ? 'replicated' : 'global'));
                } elseif ($tab === 'stacks') {
                    $details = (string) ('Env: ' . ($row['EndpointId'] ?? '-') . ' | Type: ' . ($row['Type'] ?? '-'));
                }
              ?>
              <tr>
                <td class="portainer-cell-name"><code><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></code></td>
                <td class="portainer-cell-status"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></td>
                <td class="portainer-cell-details"><?= htmlspecialchars($details, ENT_QUOTES, 'UTF-8') ?></td>
                <?php if ($tab === 'containers'): ?>
                  <td>
                    <form method="post" action="/portainer.php?tab=containers&account_id=<?= $selectedAccountId ?>&endpoint_id=<?= $selectedEndpointId ?>" class="d-flex gap-2 align-items-center">
                      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="collect_container_logs">
                      <input type="hidden" name="account_id" value="<?= $selectedAccountId ?>">
                      <input type="hidden" name="endpoint_id" value="<?= $selectedEndpointId ?>">
                      <input type="hidden" name="container_id" value="<?= htmlspecialchars((string) ($row['Id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="container_name" value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="number" name="tail" min="20" max="5000" value="500" class="form-control form-control-sm" style="width:100px;">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="archive_r2" value="1" checked>
                        <label class="form-check-label small">R2</label>
                      </div>
                      <button type="submit" class="btn btn-sm btn-outline-primary">Coletar logs</button>
                    </form>
                  </td>
                <?php endif; ?>
                <?php if ($tab === 'stacks'): ?>
                  <td>
                    <a class="btn btn-sm btn-outline-primary" href="/portainer.php?tab=stacks&account_id=<?= $selectedAccountId ?>&endpoint_id=<?= $selectedEndpointId ?>&stack_id=<?= (int) ($row['Id'] ?? 0) ?>">Editar</a>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'stacks' && $selectedStackId > 0): ?>
    <div class="card mt-3">
      <div class="card-header"><strong>Editor da stack: <?= htmlspecialchars($stackEditorName !== '' ? $stackEditorName : ('#' . $selectedStackId), ENT_QUOTES, 'UTF-8') ?></strong></div>
      <div class="card-body">
        <form method="post" action="/portainer.php?tab=stacks&account_id=<?= $selectedAccountId ?>&endpoint_id=<?= $selectedEndpointId ?>&stack_id=<?= $selectedStackId ?>">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="deploy_stack">
          <input type="hidden" name="account_id" value="<?= $selectedAccountId ?>">
          <input type="hidden" name="endpoint_id" value="<?= $selectedEndpointId ?>">
          <input type="hidden" name="stack_id" value="<?= $selectedStackId ?>">
          <div class="mb-2">
            <textarea name="stack_content" class="form-control" rows="18" style="font-family:Consolas,monospace;"><?= htmlspecialchars($stackFileContent, ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>
          <div class="d-flex gap-3 align-items-center">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="prune_stack" name="prune" value="1">
              <label class="form-check-label" for="prune_stack">Prune</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="pull_stack" name="pull_image" value="1" checked>
              <label class="form-check-label" for="pull_stack">Pull image</label>
            </div>
            <button type="submit" class="btn btn-primary">Salvar e Deploy</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <?php if (is_array($lastLogs) && $tab === 'containers'): ?>
    <div class="card mt-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Ultimos logs coletados: <?= htmlspecialchars((string) ($lastLogs['container_name'] ?? $lastLogs['container_id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
        <small class="text-body-secondary"><?= htmlspecialchars((string) ($lastLogs['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></small>
      </div>
      <div class="card-body">
        <pre class="mb-0" style="max-height: 420px; overflow: auto; white-space: pre-wrap;"><?= htmlspecialchars((string) ($lastLogs['content'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($stackArchives !== [] && $tab !== 'cadastro'): ?>
    <div class="card mt-3">
      <div class="card-header"><strong>Arquivos de logs no R2 (recentes)</strong></div>
      <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
          <thead>
            <tr>
              <th>Quando</th>
              <th>Container</th>
              <th>Endpoint</th>
              <th>Tamanho</th>
              <th>Object key</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($stackArchives, 0, 10) as $archive): ?>
              <tr>
                <td><?= htmlspecialchars((string) ($archive['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) (($archive['container_name'] ?? '') !== '' ? $archive['container_name'] : ($archive['container_id'] ?? '-')), ENT_QUOTES, 'UTF-8') ?></td>
                <td>#<?= (int) ($archive['endpoint_id'] ?? 0) ?></td>
                <td><?= number_format((int) ($archive['object_size'] ?? 0), 0, ',', '.') ?> bytes</td>
                <td><code><?= htmlspecialchars((string) ($archive['object_key'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>
</section>
<?php
ui_page_end();
