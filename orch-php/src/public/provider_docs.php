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

$context = load_user_context((int) $user['id']);
$flash = flash_pull();

$providers = [
    ['name' => 'Hetzner', 'type' => 'hetzner', 'docs' => 'https://docs.hetzner.cloud/'],
    ['name' => 'Cloudflare', 'type' => 'cloudflare', 'docs' => 'https://developers.cloudflare.com/api/'],
    ['name' => 'ProxMox', 'type' => 'proxmox', 'docs' => 'https://pve.proxmox.com/pve-docs/api-viewer/index.html'],
    ['name' => 'N8N', 'type' => 'n8n', 'docs' => 'https://docs.n8n.io/api/'],
    ['name' => 'Portainer', 'type' => 'portainer', 'docs' => 'https://docs.portainer.io/api/docs'],
    ['name' => 'Mega', 'type' => 'mega', 'docs' => 'Definir documento oficial interno'],
    ['name' => 'LLM', 'type' => 'llm', 'docs' => 'https://platform.openai.com/docs/api-reference'],
];

ui_page_start('OmniNOC | Docs Fornecedores');
ui_navigation('provider_docs', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Documentacao de Fornecedores</h3>
    <small class="text-body-secondary">Catalogo de APIs externas e como operar cada fornecedor dentro do OmniNOC.</small>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><strong>Mapa de APIs</strong></div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Fornecedor</th>
          <th>Tipo</th>
          <th>Documentacao API</th>
          <th>Status no sistema</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($providers as $provider): ?>
          <tr>
            <td><?= htmlspecialchars($provider['name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><code><?= htmlspecialchars($provider['type'], ENT_QUOTES, 'UTF-8') ?></code></td>
            <td>
              <?php if (str_starts_with((string) $provider['docs'], 'http')): ?>
                <a href="<?= htmlspecialchars($provider['docs'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noreferrer">Abrir docs</a>
              <?php else: ?>
                <?= htmlspecialchars($provider['docs'], ENT_QUOTES, 'UTF-8') ?>
              <?php endif; ?>
            </td>
            <td>Operacional base</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><strong>Service Generator</strong></div>
  <div class="card-body">
    <p class="mb-2">Gerador para criar pacote base de novo fornecedor (DB + backend + UI + Ansible):</p>
    <pre class="mb-0"><code>php tools/service_generator.php --provider proxmox --display "ProxMox" --docs "https://pve.proxmox.com/pve-docs/api-viewer/index.html"</code></pre>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><strong>Export para LLM</strong></div>
  <div class="card-body">
    <p class="mb-2">Catalogo completo dos endpoints Hetzner para enviar ao modelo:</p>
    <div class="d-flex flex-wrap gap-2">
      <a href="/hetzner_endpoints_export.php?format=json" class="btn btn-outline-primary">Baixar JSON</a>
      <a href="/hetzner_endpoints_export.php?format=md" class="btn btn-outline-secondary">Baixar Markdown</a>
      <?php if (is_platform_owner($user)): ?>
        <a href="/hetzner_operations.php" class="btn btn-outline-secondary">Abrir API Explorer</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><strong>V3 - Documentacao operacional</strong></div>
  <div class="card-body">
    <ul class="mb-0">
      <li>Fluxos por fornecedor (contas, sync, inventario, custos, snapshots).</li>
      <li>Mapeamento endpoint externo -> endpoint interno OmniNOC.</li>
      <li>Runbooks de operacao e troubleshooting.</li>
      <li>Matriz de escopos/token por fornecedor.</li>
    </ul>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header"><strong>V3 - AI Chat + RAG (Backlog)</strong></div>
  <div class="card-body">
    <ul class="mb-0">
      <li>Chat IA com historico e contexto por tenant + fornecedor.</li>
      <li>Upload de PDF com pipeline de extracao, chunking e indexacao no pgvector.</li>
      <li>Sumarizacao de documentos com bibliotecas Python.</li>
      <li>RAG com citacao de fontes e filtro estrito por isolamento de contexto.</li>
      <li>Suporte a multiplos provedores/modelos LLM por empresa.</li>
    </ul>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header"><strong>V3 - Crawler + Firewall (Backlog)</strong></div>
  <div class="card-body">
    <ul class="mb-0">
      <li>Coleta automatizada de docs oficiais dos fornecedores para alimentar a base RAG.</li>
      <li>Politica de egress firewall com allowlist por dominio oficial.</li>
      <li>Crawler com rate-limit, backoff e rastreabilidade por URL/hash/timestamp.</li>
      <li>Sem bypass de captcha/challenge/anti-bot e respeitando ToS/robots.</li>
      <li>Roadmap detalhado em <code>V3_CRAWLER_FIREWALL_FORNECEDORES.md</code>.</li>
    </ul>
  </div>
</div>
<?php
ui_page_end();
