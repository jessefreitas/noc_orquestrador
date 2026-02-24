<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/hetzner.php';
require_once __DIR__ . '/../app/tenancy.php';
require_once __DIR__ . '/../app/ui.php';

require_auth();
$user = current_user();
if ($user === null) {
    redirect('/login.php');
}
$isPlatformOwner = is_platform_owner($user);
if (!$isPlatformOwner) {
    flash_set('warning', 'Somente o admin global pode acessar o API Explorer.');
    redirect('/');
}
$userId = (int) $user['id'];

$context = load_user_context($userId);
$companyId = $context['company_id'];
$projectId = $context['project_id'];
$providerType = context_provider_type($context);
$canManage = is_int($companyId) ? user_can_manage_company_context($userId, $companyId, $user) : false;

$flash = flash_pull();
$result = null;
$prefillServerExternalId = trim((string) ($_GET['server_external_id'] ?? ''));
$prefillOperationId = trim((string) ($_POST['operation_id'] ?? ($_GET['operation_id'] ?? '')));
$prefillAccountId = (int) ($_POST['account_id'] ?? 0);
$pathParamsInputValue = trim((string) ($_POST['path_params_json'] ?? ''));
$queryParamsInputValue = trim((string) ($_POST['query_params_json'] ?? ''));
$payloadInputValue = trim((string) ($_POST['payload_json'] ?? ''));

if ($pathParamsInputValue === '') {
    if ($prefillServerExternalId !== '') {
        $pathParamsInputValue = (string) json_encode(['id' => $prefillServerExternalId], JSON_UNESCAPED_SLASHES);
    } else {
        $pathParamsInputValue = '{}';
    }
}
if ($queryParamsInputValue === '') {
    $queryParamsInputValue = '{}';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        flash_set('danger', 'CSRF invalido.');
        redirect('/hetzner_operations.php');
    }

    if (!is_int($companyId) || !is_int($projectId) || $providerType !== 'hetzner') {
        flash_set('warning', 'Selecione um fornecedor Hetzner para operar endpoints.');
        redirect('/projects.php');
    }
    if (!$canManage) {
        flash_set('danger', 'Seu usuario esta em modo leitura para esta empresa.');
        redirect('/hetzner_operations.php');
    }

    try {
        $accountId = $prefillAccountId;
        if ($accountId <= 0) {
            throw new RuntimeException('Selecione a conta API da Hetzner.');
        }

        $operationId = $prefillOperationId;
        if ($operationId === '') {
            throw new RuntimeException('Selecione um endpoint.');
        }

        $pathParamsRaw = $pathParamsInputValue;
        $queryParamsRaw = $queryParamsInputValue;
        $payloadRaw = $payloadInputValue;

        $pathParams = json_decode($pathParamsRaw !== '' ? $pathParamsRaw : '{}', true);
        $queryParams = json_decode($queryParamsRaw !== '' ? $queryParamsRaw : '{}', true);

        if (!is_array($pathParams)) {
            throw new RuntimeException('Path params JSON invalido.');
        }
        if (!is_array($queryParams)) {
            throw new RuntimeException('Query params JSON invalido.');
        }

        $payload = null;
        if ($payloadRaw !== '') {
            $decodedPayload = json_decode($payloadRaw, true);
            if (!is_array($decodedPayload)) {
                throw new RuntimeException('Payload JSON invalido.');
            }
            $payload = $decodedPayload;
        }

        $operationMeta = hetzner_endpoint_by_id($operationId);
        if (!is_array($operationMeta)) {
            throw new RuntimeException('Operacao nao encontrada no catalogo.');
        }
        $method = strtoupper((string) ($operationMeta['method'] ?? 'GET'));
        if ($method === 'DELETE') {
            throw new RuntimeException('DELETE na API da Hetzner esta bloqueado nesta plataforma.');
        }
        if ($method !== 'GET' && !isset($_POST['confirm_mutation'])) {
            throw new RuntimeException('Confirme que deseja executar operacao mutavel (POST/PUT/DELETE).');
        }

        $result = execute_hetzner_operation(
            $companyId,
            $projectId,
            $accountId,
            $userId,
            $operationId,
            $pathParams,
            $queryParams,
            $payload
        );
        flash_set(
            'success',
            'Endpoint executado: ' . $method . ' ' . (string) ($operationMeta['path'] ?? '')
        );
        $flash = flash_pull();
    } catch (Throwable $exception) {
        flash_set('danger', $exception->getMessage());
        $flash = flash_pull();
    }
}

$accounts = [];
if (is_int($companyId) && is_int($projectId) && $providerType === 'hetzner') {
    $accounts = list_hetzner_accounts($companyId, $projectId);
}
$catalog = hetzner_endpoint_catalog();
$catalog = array_values(array_filter(
    $catalog,
    static fn (array $operation): bool => strtoupper((string) ($operation['method'] ?? 'GET')) !== 'DELETE'
));
$catalogGrouped = hetzner_endpoint_catalog_grouped();
$catalogGrouped = array_map(
    static function (array $operations): array {
        return array_values(array_filter(
            $operations,
            static fn (array $operation): bool => strtoupper((string) ($operation['method'] ?? 'GET')) !== 'DELETE'
        ));
    },
    $catalogGrouped
);
$catalogGrouped = array_filter($catalogGrouped, static fn (array $operations): bool => $operations !== []);
$catalogMap = [];
foreach ($catalog as $operation) {
    $catalogMap[(string) ($operation['id'] ?? '')] = $operation;
}

ui_page_start('OmniNOC | Operacoes Hetzner');
ui_navigation('hetzner_operations', $user, $context, $flash);
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h3 class="mb-1">Operacoes da API Hetzner</h3>
    <small class="text-body-secondary">Catalogo completo de endpoints para uso do cliente nas configuracoes de servidor/conta.</small>
  </div>
  <div class="d-flex gap-2">
    <a href="/hetzner_dashboard.php" class="btn btn-outline-secondary">Dashboard Hetzner</a>
    <a href="/hetzner.php" class="btn btn-outline-secondary">Contas API</a>
    <a href="/hetzner_endpoints_export.php?format=json" class="btn btn-outline-secondary">Export JSON</a>
    <a href="/hetzner_endpoints_export.php?format=md" class="btn btn-outline-secondary">Export Markdown</a>
  </div>
</div>

<?php if (!is_int($companyId) || !is_int($projectId)): ?>
  <div class="alert alert-warning">Selecione empresa e fornecedor no topo.</div>
<?php elseif ($providerType !== 'hetzner'): ?>
  <div class="alert alert-warning">O fornecedor atual nao e Hetzner.</div>
<?php else: ?>
  <?php if (!$canManage): ?>
    <div class="alert alert-info">Acesso em modo leitura. Execucao de endpoints mutaveis foi bloqueada.</div>
  <?php endif; ?>
  <div class="row">
    <div class="col-lg-5 mb-3">
      <div class="card h-100">
        <div class="card-header"><strong>Executar endpoint</strong></div>
        <div class="card-body">
          <form method="post" action="/hetzner_operations.php">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="mb-3">
              <label class="form-label">Conta API Hetzner</label>
              <select class="form-select" name="account_id" required>
                <option value="">Selecione</option>
                <?php foreach ($accounts as $account): ?>
                  <option value="<?= (int) $account['id'] ?>" <?= (int) $account['id'] === $prefillAccountId ? 'selected' : '' ?>><?= htmlspecialchars((string) $account['label'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Endpoint do catalogo</label>
              <select class="form-select" name="operation_id" id="operation-select" required>
                <option value="">Selecione</option>
                <?php foreach ($catalogGrouped as $category => $operations): ?>
                  <optgroup label="<?= htmlspecialchars((string) $category, ENT_QUOTES, 'UTF-8') ?>">
                    <?php foreach ($operations as $operation): ?>
                      <?php $operationId = (string) ($operation['id'] ?? ''); ?>
                      <option value="<?= htmlspecialchars($operationId, ENT_QUOTES, 'UTF-8') ?>" <?= $operationId === $prefillOperationId ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) ($operation['method'] ?? ''), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string) ($operation['path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Path params (JSON)</label>
              <textarea class="form-control font-monospace" name="path_params_json" id="path-params-json" rows="4"><?= htmlspecialchars($pathParamsInputValue, ENT_QUOTES, 'UTF-8') ?></textarea>
              <small class="text-body-secondary">Exemplo: {"id":12345,"action_id":99}</small>
            </div>
            <div class="mb-3">
              <label class="form-label">Query params (JSON)</label>
              <textarea class="form-control font-monospace" name="query_params_json" rows="3"><?= htmlspecialchars($queryParamsInputValue, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label">Payload body (JSON, opcional)</label>
              <textarea class="form-control font-monospace" name="payload_json" rows="6"><?= htmlspecialchars($payloadInputValue, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" value="1" id="confirm_mutation" name="confirm_mutation">
              <label class="form-check-label" for="confirm_mutation">Confirmo operacao mutavel (POST/PUT/DELETE)</label>
            </div>
            <button type="submit" class="btn btn-primary" <?= $canManage ? '' : 'disabled' ?>>Executar endpoint</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7 mb-3">
      <div class="card mb-3">
        <div class="card-header"><strong>Catalogo completo de endpoints</strong></div>
        <div class="card-body p-0" style="max-height: 520px; overflow-y: auto;">
          <table class="table table-sm table-hover mb-0">
            <thead>
              <tr>
                <th>Categoria</th>
                <th>Metodo</th>
                <th>Path</th>
                <th>ID</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($catalog === []): ?>
                <tr><td colspan="4" class="text-center text-body-secondary py-4">Catalogo vazio.</td></tr>
              <?php else: ?>
                <?php foreach ($catalog as $operation): ?>
                  <tr>
                    <td><?= htmlspecialchars((string) ($operation['category'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><code><?= htmlspecialchars((string) ($operation['method'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><code><?= htmlspecialchars((string) ($operation['path'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><code><?= htmlspecialchars((string) ($operation['id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></code></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if (is_array($result)): ?>
        <div class="card">
          <div class="card-header"><strong>Resposta da execucao</strong></div>
          <div class="card-body">
            <div class="mb-2">
              <strong>Status HTTP:</strong> <?= (int) (($result['response']['status'] ?? 0)) ?>
            </div>
            <div class="mb-2">
              <strong>Request:</strong>
              <pre class="mb-0 mt-2" style="white-space: pre-wrap;"><?= htmlspecialchars(json_encode((array) ($result['request'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
            </div>
            <div class="mb-0">
              <strong>Response body:</strong>
              <pre class="mb-0 mt-2" style="white-space: pre-wrap; max-height: 380px; overflow-y: auto;"><?= htmlspecialchars(json_encode((array) (($result['response']['body'] ?? [])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
    (function () {
      var catalogMap = <?= json_encode($catalogMap, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      var operationSelect = document.getElementById('operation-select');
      var pathParamsInput = document.getElementById('path-params-json');
      if (!operationSelect || !pathParamsInput) {
        return;
      }

      operationSelect.addEventListener('change', function () {
        var operationId = String(operationSelect.value || '');
        var operation = catalogMap[operationId];
        if (!operation) {
          return;
        }

        var path = String(operation.path || '');
        var matches = path.match(/\{[a-zA-Z0-9_]+\}/g) || [];
        if (matches.length === 0) {
          return;
        }

        var currentValue = {};
        try {
          currentValue = JSON.parse(pathParamsInput.value || '{}');
        } catch (error) {
          currentValue = {};
        }
        if (typeof currentValue !== 'object' || currentValue === null || Array.isArray(currentValue)) {
          currentValue = {};
        }

        matches.forEach(function (matchItem) {
          var key = matchItem.replace('{', '').replace('}', '');
          if (!Object.prototype.hasOwnProperty.call(currentValue, key)) {
            currentValue[key] = '';
          }
        });

        pathParamsInput.value = JSON.stringify(currentValue, null, 2);
      });
    })();
  </script>
<?php endif; ?>
<?php
ui_page_end();
