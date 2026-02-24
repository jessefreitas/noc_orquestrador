<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/llm.php';
require_once __DIR__ . '/../app/tenancy.php';
require_once __DIR__ . '/../app/ui.php';

require_auth();
$user = current_user();
if ($user === null) {
    redirect('/login.php');
}
$userId = (int) $user['id'];

$context = load_user_context($userId);
$flash = flash_pull();
$companyId = $context['company_id'];
$projectId = $context['project_id'];
$providerType = context_provider_type($context);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? null;
    if (!validate_csrf(is_string($csrf) ? $csrf : null)) {
        flash_set('danger', 'CSRF invalido.');
        redirect('/llm.php');
    }

    try {
        if (!is_int($companyId) || !is_int($projectId)) {
            throw new RuntimeException('Selecione empresa e fornecedor LLM no contexto.');
        }
        if ($providerType !== 'llm') {
            throw new RuntimeException('O fornecedor ativo nao e do tipo LLM.');
        }

        create_company_llm_key(
            $userId,
            $companyId,
            (string) ($_POST['provider'] ?? ''),
            (string) ($_POST['model'] ?? ''),
            (string) ($_POST['api_key'] ?? ''),
            null,
            (string) ($_POST['key_label'] ?? '')
        );

        $providerQuery = urlencode((string) ($_POST['provider'] ?? 'openai'));
        flash_set('success', 'Credencial LLM cadastrada com sucesso.');
        redirect('/llm.php?provider=' . $providerQuery);
    } catch (Throwable $exception) {
        flash_set('danger', $exception->getMessage());
        redirect('/llm.php');
    }
}

$selectedProvider = llm_normalize_provider((string) ($_GET['provider'] ?? 'openai'));
$providerCatalog = llm_provider_catalog();
if ($selectedProvider === '' || !array_key_exists($selectedProvider, $providerCatalog)) {
    $selectedProvider = 'openai';
}
$providerModelsMap = [];
foreach (array_keys($providerCatalog) as $providerKey) {
    $providerModelsMap[$providerKey] = llm_provider_models($providerKey);
}
$keys = is_int($companyId) ? list_company_llm_keys($userId, $companyId) : [];
$storageReady = llm_storage_ready();

ui_page_start('OmniNOC | LLM');
ui_navigation('llm', $user, $context, $flash);
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h3 class="mb-1">Fornecedor LLM</h3>
    <small class="text-body-secondary">Credenciais dos modelos de IA no escopo da empresa/fornecedor selecionado.</small>
  </div>
</div>

<?php if (!$storageReady): ?>
  <div class="alert alert-warning">
    Estrutura de chaves LLM nao encontrada no banco atual.
    <div class="mt-2"><code><?= htmlspecialchars(llm_migration_hint_command(), ENT_QUOTES, 'UTF-8') ?></code></div>
  </div>
<?php elseif (!is_int($companyId) || !is_int($projectId)): ?>
  <div class="alert alert-warning">Selecione empresa e fornecedor no topo para configurar credenciais LLM.</div>
<?php elseif ($providerType !== 'llm'): ?>
  <div class="alert alert-warning">O fornecedor atual nao e do tipo LLM. Selecione um fornecedor LLM no contexto.</div>
<?php else: ?>
  <div class="row">
    <div class="col-lg-6 mb-3">
      <div class="card">
        <div class="card-header"><strong>Nova credencial LLM</strong></div>
        <div class="card-body">
          <form method="post" action="/llm.php">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="mb-3">
              <label class="form-label">Provedor LLM</label>
              <select class="form-select" name="provider" required>
                <?php foreach ($providerCatalog as $providerKey => $providerData): ?>
                  <?php $providerLabel = (string) ($providerData['label'] ?? strtoupper($providerKey)); ?>
                  <option value="<?= htmlspecialchars($providerKey, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedProvider === $providerKey ? 'selected' : '' ?>>
                    <?= htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Modelo</label>
              <input type="text" class="form-control" name="model" placeholder="gpt-4.1-mini" list="llm-model-list" required>
              <datalist id="llm-model-list">
                <?php foreach (llm_provider_models($selectedProvider) as $modelOption): ?>
                  <option value="<?= htmlspecialchars((string) $modelOption, ENT_QUOTES, 'UTF-8') ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>
            <div class="mb-3">
              <label class="form-label">Nome da chave (opcional)</label>
              <input type="text" class="form-control" name="key_label" placeholder="producao">
            </div>
            <div class="mb-3">
              <label class="form-label">Chave do provedor</label>
              <input type="password" class="form-control" name="api_key" required>
            </div>
            <button type="submit" class="btn btn-primary">Salvar credencial</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-6 mb-3">
      <div class="card h-100">
        <div class="card-header"><strong>Credenciais cadastradas</strong></div>
        <div class="card-body p-0">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Provider</th>
                <th>Modelo</th>
                <th>Label</th>
                <th>Hint</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($keys === []): ?>
                <tr>
                  <td colspan="5" class="text-center text-body-secondary py-4">Nenhuma credencial LLM cadastrada para esta empresa.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($keys as $key): ?>
                  <tr>
                    <td><?= htmlspecialchars(llm_provider_label((string) $key['provider']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $key['model'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($key['key_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($key['key_hint'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $key['status'], ENT_QUOTES, 'UTF-8') ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      var providerModels = <?= json_encode($providerModelsMap, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      var providerSelect = document.querySelector('select[name="provider"]');
      var datalist = document.getElementById('llm-model-list');
      if (!providerSelect || !datalist) {
        return;
      }

      var syncModels = function () {
        var provider = providerSelect.value || 'other';
        var models = providerModels[provider] || [];
        datalist.innerHTML = '';
        models.forEach(function (model) {
          var option = document.createElement('option');
          option.value = model;
          datalist.appendChild(option);
        });
      };

      providerSelect.addEventListener('change', syncModels);
      syncModels();
    })();
  </script>
<?php endif; ?>
<?php
ui_page_end();
