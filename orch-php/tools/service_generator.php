<?php
declare(strict_types=1);

/**
 * Service Generator
 *
 * Gera pacote base de fornecedor no padrao OmniNOC:
 * - DB (SQL base)
 * - Backend (adapter stub)
 * - UI (pagina stub)
 * - Ansible (role + playbook)
 * - Checklist de integracao
 */

function generator_usage(): string
{
    return <<<TXT
Uso:
  php tools/service_generator.php --provider <slug> [opcoes]

Opcoes:
  --provider <slug>           Slug do fornecedor (ex: proxmox)
  --display <nome>            Nome de exibicao (ex: ProxMox)
  --docs <url>                URL de documentacao da API do fornecedor
  --output <path>             Pasta base de saida (default: ./scaffolds/services)
  --force                     Sobrescreve arquivos existentes
  -h, --help                  Exibe esta ajuda

Exemplo:
  php tools/service_generator.php --provider proxmox --display "ProxMox" --docs "https://pve.proxmox.com/pve-docs/api-viewer/index.html"
TXT;
}

function generator_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, '[service-generator] ERRO: ' . $message . PHP_EOL);
    exit($code);
}

/**
 * @return array{
 *   provider:string,
 *   display:string,
 *   docs:string,
 *   output:string,
 *   force:bool
 * }
 */
function generator_parse_args(array $argv): array
{
    $args = [
        'provider' => null,
        'display' => null,
        'docs' => '',
        'output' => null,
        'force' => false,
    ];

    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $arg = (string) $argv[$i];

        if ($arg === '-h' || $arg === '--help') {
            echo generator_usage() . PHP_EOL;
            exit(0);
        }

        if ($arg === '--force') {
            $args['force'] = true;
            continue;
        }

        if (str_starts_with($arg, '--provider=')) {
            $args['provider'] = substr($arg, strlen('--provider='));
            continue;
        }
        if (str_starts_with($arg, '--display=')) {
            $args['display'] = substr($arg, strlen('--display='));
            continue;
        }
        if (str_starts_with($arg, '--docs=')) {
            $args['docs'] = substr($arg, strlen('--docs='));
            continue;
        }
        if (str_starts_with($arg, '--output=')) {
            $args['output'] = substr($arg, strlen('--output='));
            continue;
        }

        if (in_array($arg, ['--provider', '--display', '--docs', '--output'], true)) {
            $next = $argv[$i + 1] ?? null;
            if (!is_string($next) || $next === '') {
                generator_fail('Parametro faltando para ' . $arg . '.');
            }
            $i++;

            if ($arg === '--provider') {
                $args['provider'] = $next;
                continue;
            }
            if ($arg === '--display') {
                $args['display'] = $next;
                continue;
            }
            if ($arg === '--docs') {
                $args['docs'] = $next;
                continue;
            }
            if ($arg === '--output') {
                $args['output'] = $next;
                continue;
            }
        }

        generator_fail('Opcao desconhecida: ' . $arg . PHP_EOL . PHP_EOL . generator_usage());
    }

    $provider = strtolower(trim((string) $args['provider']));
    if ($provider === '') {
        generator_fail('Informe --provider <slug>.');
    }
    if (!preg_match('/^[a-z][a-z0-9_-]{1,49}$/', $provider)) {
        generator_fail('Provider invalido. Use apenas [a-z0-9_-] e inicie com letra.');
    }

    $display = trim((string) ($args['display'] ?? ''));
    if ($display === '') {
        $display = ucwords(str_replace(['-', '_'], ' ', $provider));
    }

    $output = trim((string) ($args['output'] ?? ''));
    if ($output === '') {
        $output = __DIR__ . '/../scaffolds/services';
    }

    return [
        'provider' => $provider,
        'display' => $display,
        'docs' => trim((string) $args['docs']),
        'output' => $output,
        'force' => (bool) $args['force'],
    ];
}

function generator_identifier(string $value): string
{
    $id = strtolower(trim($value));
    $id = preg_replace('/[^a-z0-9]+/', '_', $id) ?? '';
    $id = trim($id, '_');
    return $id !== '' ? $id : 'provider';
}

function generator_constant(string $value): string
{
    $constant = strtoupper(generator_identifier($value));
    return $constant !== '' ? $constant : 'PROVIDER';
}

function generator_ensure_directory(string $dirPath): void
{
    if (is_dir($dirPath)) {
        return;
    }

    if (!mkdir($dirPath, 0777, true) && !is_dir($dirPath)) {
        generator_fail('Falha ao criar diretorio: ' . $dirPath);
    }
}

/**
 * @param array<int,string> $createdFiles
 */
function generator_write_file(string $filePath, string $content, bool $force, array &$createdFiles): void
{
    $dir = dirname($filePath);
    generator_ensure_directory($dir);

    if (file_exists($filePath) && !$force) {
        generator_fail('Arquivo ja existe (use --force para sobrescrever): ' . $filePath);
    }

    $bytes = file_put_contents($filePath, $content);
    if ($bytes === false) {
        generator_fail('Nao foi possivel escrever o arquivo: ' . $filePath);
    }

    $createdFiles[] = $filePath;
}

/**
 * @return array<string,string>
 */
function generator_render_templates(string $providerSlug, string $displayName, string $docsUrl): array
{
    $providerId = generator_identifier($providerSlug);
    $providerConst = generator_constant($providerSlug);
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $docsUrlPhpLiteral = var_export($docsUrl, true);

    $sql = <<<SQL
-- {$displayName} provider base schema
-- Generated at {$timestamp}
-- NOTE: keep isolation by company_id + project_id (service context)

CREATE TABLE IF NOT EXISTS {$providerId}_resources (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    provider_account_id BIGINT NOT NULL REFERENCES provider_accounts(id) ON DELETE CASCADE,
    external_id VARCHAR(190) NOT NULL,
    name VARCHAR(190) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'unknown',
    metadata_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (provider_account_id, external_id)
);

CREATE INDEX IF NOT EXISTS idx_{$providerId}_resources_scope
ON {$providerId}_resources (company_id, project_id, status);
SQL;

    $backend = <<<PHP
<?php
declare(strict_types=1);

require_once __DIR__ . '/../audit.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../tenancy.php';

const {$providerConst}_DOCS_URL = {$docsUrlPhpLiteral};

function list_{$providerId}_accounts(int \$companyId, int \$projectId): array
{
    \$stmt = db()->prepare(
        'SELECT id, label, status, created_at
         FROM provider_accounts
         WHERE company_id = :company_id
           AND project_id = :project_id
           AND provider = :provider
         ORDER BY created_at DESC'
    );
    \$stmt->execute([
        'company_id' => \$companyId,
        'project_id' => \$projectId,
        'provider' => '{$providerSlug}',
    ]);

    return \$stmt->fetchAll();
}

function create_{$providerId}_account(
    int \$companyId,
    int \$projectId,
    int \$userId,
    string \$label,
    string \$token
): int {
    \$normalizedLabel = trim(\$label);
    \$normalizedToken = trim(\$token);
    if (\$normalizedLabel === '' || \$normalizedToken === '') {
        throw new InvalidArgumentException('Label e token sao obrigatorios.');
    }

    \$stmt = db()->prepare(
        'INSERT INTO provider_accounts (
            company_id,
            project_id,
            provider,
            label,
            token_ciphertext,
            created_by
        ) VALUES (
            :company_id,
            :project_id,
            :provider,
            :label,
            :token_ciphertext,
            :created_by
        )
        RETURNING id'
    );

    \$stmt->execute([
        'company_id' => \$companyId,
        'project_id' => \$projectId,
        'provider' => '{$providerSlug}',
        'label' => \$normalizedLabel,
        'token_ciphertext' => encrypt_secret(\$normalizedToken),
        'created_by' => \$userId,
    ]);

    \$accountId = (int) \$stmt->fetchColumn();

    audit_log(
        \$companyId,
        \$projectId,
        \$userId,
        '{$providerSlug}.account.created',
        'provider_account',
        (string) \$accountId,
        null,
        ['label' => \$normalizedLabel]
    );

    return \$accountId;
}

function list_{$providerId}_resources(int \$companyId, int \$projectId): array
{
    \$stmt = db()->prepare(
        'SELECT r.id,
                r.external_id,
                r.name,
                r.status,
                r.last_seen_at,
                pa.label AS account_label
         FROM {$providerId}_resources r
         INNER JOIN provider_accounts pa ON pa.id = r.provider_account_id
         WHERE r.company_id = :company_id
           AND r.project_id = :project_id
         ORDER BY r.name'
    );
    \$stmt->execute([
        'company_id' => \$companyId,
        'project_id' => \$projectId,
    ]);

    return \$stmt->fetchAll();
}
PHP;

    $ui = <<<PHP
<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/flash.php';
require_once __DIR__ . '/../../app/tenancy.php';
require_once __DIR__ . '/../../app/ui.php';
require_once __DIR__ . '/../../app/providers/{$providerSlug}.php';

require_auth();
\$user = current_user();
if (\$user === null) {
    redirect('/login.php');
}

\$context = load_user_context((int) \$user['id']);
\$flash = flash_pull();
\$providerType = context_provider_type(\$context);

ui_page_start('OmniNOC | {$displayName}');
ui_navigation('{$providerSlug}', \$user, \$context, \$flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Fornecedor {$displayName}</h3>
    <small class="text-body-secondary">Modulo base gerado. Configure contas e implemente sincronizacao.</small>
  </div>
</div>

<?php if ((\$context['company_id'] ?? null) === null || (\$context['project_id'] ?? null) === null): ?>
  <div class="alert alert-warning">Selecione empresa e fornecedor para operar este modulo.</div>
<?php elseif (\$providerType !== '{$providerSlug}'): ?>
  <div class="alert alert-warning">O fornecedor selecionado nao e do tipo {$displayName}.</div>
<?php else: ?>
  <div class="card">
    <div class="card-header"><strong>Proximos passos</strong></div>
    <div class="card-body">
      <ol class="mb-0">
        <li>Conectar conta(s) do fornecedor.</li>
        <li>Implementar endpoint de teste de token.</li>
        <li>Implementar sync de recursos no inventario local.</li>
        <li>Adicionar metricas, custos e snapshots conforme capability.</li>
      </ol>
    </div>
  </div>
<?php endif; ?>
<?php
ui_page_end();
PHP;

    $ansibleDefaults = <<<YAML
---
# {$displayName} role defaults
{$providerId}_api_base_url: ""
{$providerId}_api_token: ""
{$providerId}_sync_enabled: false
YAML;

    $ansibleTasks = <<<YAML
---
# Base tasks for {$displayName} orchestration
- name: Validate {$displayName} variables
  ansible.builtin.assert:
    that:
      - {$providerId}_api_base_url | length > 0
      - {$providerId}_api_token | length > 0
    fail_msg: "Configure {$providerId}_api_base_url e {$providerId}_api_token antes de executar."

- name: Ping {$displayName} API (placeholder)
  ansible.builtin.uri:
    url: "{{ {$providerId}_api_base_url }}"
    method: GET
    headers:
      Authorization: "Bearer {{ {$providerId}_api_token }}"
    status_code: [200, 401, 403]
    return_content: true
  register: {$providerId}_api_ping

- name: Show {$displayName} API response (placeholder)
  ansible.builtin.debug:
    var: {$providerId}_api_ping.status
YAML;

    $ansiblePlaybook = <<<YAML
---
- name: {$displayName} sync bootstrap
  hosts: localhost
  gather_facts: false
  roles:
    - role: roles/{$providerSlug}
YAML;

    $manifest = json_encode(
        [
            'provider' => $providerSlug,
            'display_name' => $displayName,
            'docs_url' => $docsUrl,
            'generated_at' => $timestamp,
            'package_version' => 1,
            'artifacts' => [
                'database/init/1000-' . $providerSlug . '-base.sql',
                'src/app/providers/' . $providerSlug . '.php',
                'src/public/providers/' . $providerSlug . '.php',
                'ansible/roles/' . $providerSlug . '/defaults/main.yml',
                'ansible/roles/' . $providerSlug . '/tasks/main.yml',
                'ansible/playbooks/' . $providerSlug . '-sync.yml',
                'docs/integration-checklist.md',
            ],
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($manifest)) {
        generator_fail('Falha ao gerar manifest JSON.');
    }

    $readme = <<<MD
# {$displayName} Service Package

Pacote base gerado para novo fornecedor no OmniNOC.

## Entradas
- Provider slug: `{$providerSlug}`
- Nome de exibicao: `{$displayName}`
- Docs API: {$docsUrl}

## Conteudo
- `database/init/1000-{$providerSlug}-base.sql`: schema inicial para recursos do fornecedor.
- `src/app/providers/{$providerSlug}.php`: adapter backend (stub).
- `src/public/providers/{$providerSlug}.php`: tela base do fornecedor (stub).
- `ansible/roles/{$providerSlug}`: role base para automacao.
- `ansible/playbooks/{$providerSlug}-sync.yml`: playbook bootstrap.
- `docs/integration-checklist.md`: passos para integrar no app principal.

## Regra de isolamento
Todo recurso operacional deve filtrar por `company_id + project_id` (tenant + fornecedor).
Nunca operar servidores/APIs sem contexto de fornecedor selecionado.
MD;

    $checklist = <<<MD
# Integration Checklist - {$displayName}

1. Banco de dados
- Revisar `database/init/1000-{$providerSlug}-base.sql`.
- Aplicar migration no ambiente local/producao.

2. Backend
- Integrar `src/app/providers/{$providerSlug}.php` em `src/app`.
- Implementar chamadas reais da API do fornecedor.
- Registrar trilha de auditoria em cada acao critica.

3. Frontend/UI
- Integrar `src/public/providers/{$providerSlug}.php` como pagina do fornecedor.
- Adicionar o fornecedor no menu lateral e no seletor de cadastro.
- Garantir bloqueio de operacao sem fornecedor selecionado.

4. Capabilities
- Ajustar `infer_provider_type_from_project` e `default_capabilities_for_provider`.
- Definir quais modulos ficam ativos: servers/apis/domains/observability/costs/snapshots.

5. Ansible
- Completar role em `ansible/roles/{$providerSlug}`.
- Definir inventario/variaveis seguras (vault/secret manager).
- Criar playbooks de sync e remediation.

6. Definicao de pronto (DoD)
- CRUD de contas do fornecedor.
- Sync inicial de recursos funcionando.
- Dashboard/list/detail no padrao UX Guard.
- Logs de auditoria e tratamento de erros.
MD;

    return [
        'README.md' => $readme . PHP_EOL,
        'manifest.json' => $manifest . PHP_EOL,
        'database/init/1000-' . $providerSlug . '-base.sql' => $sql . PHP_EOL,
        'src/app/providers/' . $providerSlug . '.php' => $backend . PHP_EOL,
        'src/public/providers/' . $providerSlug . '.php' => $ui . PHP_EOL,
        'ansible/roles/' . $providerSlug . '/defaults/main.yml' => $ansibleDefaults . PHP_EOL,
        'ansible/roles/' . $providerSlug . '/tasks/main.yml' => $ansibleTasks . PHP_EOL,
        'ansible/playbooks/' . $providerSlug . '-sync.yml' => $ansiblePlaybook . PHP_EOL,
        'docs/integration-checklist.md' => $checklist . PHP_EOL,
    ];
}

function generator_main(array $argv): int
{
    $options = generator_parse_args($argv);
    $provider = $options['provider'];
    $display = $options['display'];
    $docs = $options['docs'];
    $outputBase = rtrim($options['output'], '/\\');
    $packageRoot = $outputBase . DIRECTORY_SEPARATOR . $provider;
    $force = $options['force'];

    $templates = generator_render_templates($provider, $display, $docs);
    $createdFiles = [];

    foreach ($templates as $relativePath => $content) {
        $target = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        generator_write_file($target, $content, $force, $createdFiles);
    }

    echo '[service-generator] Pacote criado com sucesso.' . PHP_EOL;
    echo '[service-generator] Provider: ' . $provider . PHP_EOL;
    echo '[service-generator] Caminho: ' . $packageRoot . PHP_EOL;
    echo '[service-generator] Arquivos:' . PHP_EOL;
    foreach ($createdFiles as $file) {
        echo '  - ' . $file . PHP_EOL;
    }

    return 0;
}

exit(generator_main($argv));
