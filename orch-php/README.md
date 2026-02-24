# NOC Orquestrador (PHP + AdminLTE + PostgreSQL/pgvector)

Base local para desenvolvimento em PHP usando Docker + Apache, com autenticacao por sessao, modelo multi-tenant/multi-projeto e PostgreSQL com `pgvector`.

## Requisitos

- Docker Desktop com `docker compose`

## Subir localmente

```bash
cd orch-php
docker compose up --build -d
```

A aplicação ficará disponível em:

- http://localhost:8080
- Healthcheck: http://localhost:8080/health.php
- PostgreSQL local: `localhost:5433`

## Login inicial

- URL: http://localhost:8080/login.php
- Email: `admin@local.test`
- Senha: `admin123`

## Perfil de acesso (novo)

- `Gestor global` (dono da plataforma): cadastra empresas e habilita servicos por empresa.
- `Admin da empresa`: opera apenas os servicos habilitados no contexto; nao acessa telas de cadastro global.
- O dono global e definido por `PLATFORM_OWNER_EMAIL` (default local: `admin@local.test`).
- O menu global do dono inclui `Empresas e Fornecedores` e `Emular Cliente`.
- `Criar Empresa` fica dentro da pagina `Empresas e Fornecedores` (botao `Nova empresa`).

## Fluxo multi-tenant + Hetzner

1. Acesse `Empresas e Fornecedores` e crie a empresa.
2. Em `Editar empresa`, preencha dados e canais de alerta.
3. Em `Editar empresa`, use a secao `Servicos habilitados` para ativar os servicos da licenca.
4. Aplique o contexto no topo (`Empresa + Fornecedor`).
5. Acesse `Dashboard Hetzner` para visao consolidada e sync geral.
6. Acesse `Contas Hetzner` e cadastre o token da conta.
7. Clique em `Testar` para validar o token.
8. Clique em `Sincronizar` para importar os servidores.
9. Acesse `Servidores` para ver o inventario sincronizado.

## Gestao de empresa (CRUD basico)

- `Criar Empresa`: cadastro inicial.
- `Empresas e Fornecedores`: lista empresas e permite `Editar` e `Vincular servico`.
- `company_details.php?id=<companyId>`:
  - editar dados completos da empresa
  - habilitar/desabilitar servicos da licenca (cria/ativa/desativa vinculos)
  - gerenciar contatos de alerta (email/telefone/whatsapp)
  - arquivar empresa (delete logico)

## Emular cliente (impersonate user)

- Tela: `impersonate.php`
- Apenas para gestor global.
- Permite assumir a sessao de um usuario cliente para diagnostico.
- Sempre aparece botao `Parar emulacao` no topo enquanto a emulacao estiver ativa.

## LLM como fornecedor (Contas)

- Crie um fornecedor do tipo `LLM` em `Empresas e Fornecedores`.
- Selecione o contexto desse fornecedor no topo.
- Tela de contas: `LLM` (submenu de Fornecedores).
- Suporta cadastro por empresa com provider/modelo/chave.
- Providers padrao: OpenAI, OpenRouter (OpenOuter), Z.ai, Anthropic, Google, Groq, DeepSeek e outros.

Se seu banco ja existia antes da migration `003-llm-keys.sql`, aplique manualmente:

```bash
docker compose -f orch-php/docker-compose.yml exec -T db psql -U noc_user -d noc_orquestrador -f /docker-entrypoint-initdb.d/003-llm-keys.sql
```

Se seu banco ja existia antes da migration `004-company-profile.sql`, aplique manualmente:

```bash
docker compose -f orch-php/docker-compose.yml exec -T db psql -U noc_user -d noc_orquestrador -f /docker-entrypoint-initdb.d/004-company-profile.sql
```

Se seu banco ja existia antes da migration `005-backup-storage.sql`, aplique manualmente:

```bash
docker compose -f orch-php/docker-compose.yml exec -T db psql -U noc_user -d noc_orquestrador -f /docker-entrypoint-initdb.d/005-backup-storage.sql
```

Se seu banco ja existia antes da migration `007-snapshot-policies.sql`, aplique manualmente:

```bash
docker compose -f orch-php/docker-compose.yml exec -T db psql -U noc_user -d noc_orquestrador -f /docker-entrypoint-initdb.d/007-snapshot-policies.sql
```

## Langfuse global (telemetria LLM)

- Variaveis globais no `docker-compose.yml`:
  - `LANGFUSE_SECRET_KEY`
  - `LANGFUSE_PUBLIC_KEY`
  - `LANGFUSE_BASE_URL`
- Integracao aplicada no runtime de IA em `src/app/llm.php`.
- Comportamento: envio nao-bloqueante (se Langfuse indisponivel, a resposta LLM continua funcionando).
- Importante: Langfuse nao substitui provider LLM. Para analise IA funcionar globalmente sem chave por tenant, configure:
  - `LLM_GLOBAL_API_KEY`
  - `LLM_GLOBAL_PROVIDER`
  - `LLM_GLOBAL_MODEL`
  - `LLM_GLOBAL_BASE_URL`
- Para centralizar a execucao no modo global (ignorando chaves por tenant), use:
  - `LLM_FORCE_GLOBAL=1`

## Storage de backup global (R2/S3)

- Tela global: `Storage Backup` (somente gestor global).
- Cadastra credenciais globais de Cloudflare R2 ou Amazon S3.
- Em `company_details.php`, secao `Backup de banco (PostgreSQL)`:
  - habilita/desabilita o servico por empresa
  - seleciona o storage global
  - define bucket/prefixo/retencao
  - configura cobranca opcional por empresa

## Scheduler de snapshots (multiempresa)

- Politica por servidor em `Servidor -> Snapshots` (agendamento e retencao).
- Runner CLI para executar politicas vencidas:

```bash
docker compose -f orch-php/docker-compose.yml exec -T app php /var/www/html/cli/run_snapshot_scheduler.php 50
```

- Exemplo de cron (a cada 5 minutos) no host:

```bash
*/5 * * * * cd /caminho/noc_orquestrador && docker compose -f orch-php/docker-compose.yml exec -T app php /var/www/html/cli/run_snapshot_scheduler.php 50 >> /var/log/omninoc-snapshot-scheduler.log 2>&1
```

## Refresh automatico de inventario Hetzner (multiempresa)

- Runner CLI para atualizar inventario de todas as contas Hetzner ativas:

```bash
docker compose -f orch-php/docker-compose.yml exec -T app php /var/www/html/cli/refresh_hetzner_inventory.php --limit=200
```

- Exemplo de cron (a cada 5 minutos) para evitar status desatualizado (ex: snapshot em `creating`):

```bash
*/5 * * * * cd /caminho/noc_orquestrador && docker compose -f orch-php/docker-compose.yml exec -T app php /var/www/html/cli/refresh_hetzner_inventory.php --limit=200 >> /var/log/omninoc-inventory-refresh.log 2>&1
```

## Service Generator (novo fornecedor)

Gera pacote base com DB + backend + UI + Ansible no padrao do projeto:

```bash
php tools/service_generator.php --provider proxmox --display "ProxMox" --docs "https://pve.proxmox.com/pve-docs/api-viewer/index.html"
```

Saida padrao:

- `scaffolds/services/<provider>/database`
- `scaffolds/services/<provider>/src/app/providers`
- `scaffolds/services/<provider>/src/public/providers`
- `scaffolds/services/<provider>/ansible`
- `scaffolds/services/<provider>/docs/integration-checklist.md`

## Estrutura

- `docker-compose.yml`: serviço local da aplicação.
- `Dockerfile`: imagem `php:8.3-apache` com `mod_rewrite` e extensoes `pdo_pgsql/pgsql`.
- `apache/000-default.conf`: `DocumentRoot` apontando para `public/`.
- `database/init/001-init.sql`: cria `users`, `embeddings`, habilita `vector` e popula admin inicial.
- `database/init/002-multitenant.sql`: cria `companies`, `projects`, `provider_accounts`, `hetzner_servers`, `job_runs`, `audit_events`.
- `src/app/auth.php`: fluxo de sessao, CSRF e controle de autenticacao.
- `src/app/tenancy.php`: contexto tenant/projeto e regras de acesso.
- `src/app/hetzner.php`: conexao com API Hetzner (teste e sync).
- `src/app/security.php`: criptografia AES-GCM para segredos.
- `src/public/login.php`: tela de login.
- `src/public/projects.php`: gestao de empresas/projetos.
- `src/public/hetzner.php`: contas Hetzner por projeto.
- `src/public/servers.php`: inventario de servidores sincronizados.
- `src/public/index.php`: dashboard protegido usando AdminLTE.
- `src/public/vendor/adminlte`: distribuição local do AdminLTE.

## Parar o ambiente

```bash
docker compose down
```
