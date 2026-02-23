# OMNINOC Blueprint Canonico

## 1. Objetivo
Padrao oficial para gerar UI e backend sem conflito, agora com arquitetura:
- `Tenant -> Service -> Recursos`
- sem ambiguidade de "Principal"
- isolamento total por servico
- UX consistente e sem amontoamento

## 2. Conceito de contexto (regra fixa)
Contexto sempre composto por:
- `tenantId`
- `serviceId`

Topbar obrigatoria:
- `[Tenant ?] [Service ?] [Aplicar contexto]`

Regra:
- sem `serviceId`, paginas operacionais (`servers`, `apis`, `snapshots`, `costs`) ficam bloqueadas com estado de contexto faltando.

## 3. Navegacao oficial
### 3.1 Menu lateral
Bloco `GLOBAL`:
- Empresas
- Servicos

Bloco `SERVICOS`:
- Hetzner
- Cloudflare
- ProxMox
- N8N
- Portainer
- Mega
- LLM

Bloco `SERVICO ATUAL` (dinamico apos selecionar um servico):
- Dashboard
- Contas (fornecedores)
- Servidores
- APIs
- Snapshots
- Custos
- Config e Acesso

Itens extras por capability/tipo:
- Cloudflare: Dominios, DNS, WAF, Cache
- ProxMox: Nodes, VMs, Storage, Backups
- N8N: Workflows, Executions
- Portainer: Endpoints, Stacks
- LLM: Modelos, Chaves, Uso/quotas

### 3.2 Drill-down obrigatorio
- Servico -> Servidores -> Servidor -> abas
- Servico -> APIs -> API -> abas
- Servico -> Contas -> Conta -> abas
- Servico -> Dashboard -> consolidado do servico

## 4. Regras de dados e isolamento
1. Toda tela e endpoint recebe `tenantId + serviceId`.
2. `servers` e `apis` pertencem ao `serviceId`.
3. Fornecedores e credenciais ficam dentro de `Contas` do servico.
4. Nunca permitir recursos sem contexto de servico.
5. Nunca exibir dados de outro servico no mesmo tenant.

## 5. Modelo de banco obrigatorio
Tabelas minimas:
- `tenants(id, name)`
- `services(id, tenant_id, type, name, credentials_encrypted, created_at)`
- `servers(id, service_id, external_id, metadata_json)`
- `apis(id, service_id, token_encrypted, metadata_json)`

Regra explicita:
- proibido modelar `servers -> tenant_id` sem `service_id`.
- proibido modelar `apis -> tenant_id` sem `service_id`.

## 6. Rotas obrigatorias
Global:
- `/app/tenants`
- `/app/tenant/:tenantId/services`
- `/app/tenant/:tenantId/services/new`

Servico:
- `/app/tenant/:tenantId/service/:serviceId/dashboard`
- `/app/tenant/:tenantId/service/:serviceId/accounts`
- `/app/tenant/:tenantId/service/:serviceId/accounts/:accountId`
- `/app/tenant/:tenantId/service/:serviceId/servers`
- `/app/tenant/:tenantId/service/:serviceId/servers/:serverId`
- `/app/tenant/:tenantId/service/:serviceId/apis`
- `/app/tenant/:tenantId/service/:serviceId/apis/:apiId`
- `/app/tenant/:tenantId/service/:serviceId/snapshots`
- `/app/tenant/:tenantId/service/:serviceId/costs`
- `/app/tenant/:tenantId/service/:serviceId/settings`

## 7. UX Guard (obrigatorio)
### 7.1 Layout padrao
- `AppShell + Topbar + SideMenu`
- `PageHeader` com titulo, descricao curta e acoes
- `Sections` com cards/tabelas
- detalhes em `Tabs` e `Drawer`

### 7.2 Hierarquia de acoes
- `Primary`: 1 por tela
- `Secondary`: maximo 2-3
- `Danger`: apenas em `More...` com confirmacao

### 7.3 Estados obrigatorios
- `loading`
- `empty`
- `error`
- `insufficient_scopes`
- `service_context_required`

### 7.4 Regras anti-amontoamento
1. Dashboard: maximo 4 KPIs.
2. Logs nunca no Overview.
3. Lista e Detalhe sempre separados.
4. Acao operacional nao fica na tela de listagem de cadastro.

## 8. Contratos de tela (resumo oficial)
### 8.1 Empresas (global)
- Objetivo: criar e administrar tenants.
- Primary: Criar empresa.

### 8.2 Servicos (global)
- Objetivo: listar servicos por tenant e criar novo servico.
- Primary: Criar servico.
- Campos minimos: tenant, type, name, credenciais.

### 8.3 Dashboard do servico
- Objetivo: consolidado operacional do servico selecionado.
- KPIs: contas, servidores, APIs, jobs/alertas.

### 8.4 Contas do servico
- Objetivo: cadastrar fornecedores e credenciais por servico.
- Primary: Conectar conta.

Regra:
- servidores, dominios e APIs operacionais so podem ser gerenciados apos existir conta/fornecedor conectado ao servico.

### 8.5 Servidores do servico
- Objetivo: navegacao para detalhe operacional do servidor.
- Primary: Sync inventario (ou acao equivalente do provider).

### 8.6 APIs do servico
- Objetivo: gerenciar APIs daquele servico.
- Primary: Criar API.

### 8.7 Custos e Snapshots do servico
- Objetivo: consolidado financeiro e historico de snapshots por servico.

## 9. Contrato de backend minimo
Contexto:
- `GET /tenants/:tenantId/services/:serviceId/context`

Core:
- `GET /tenants/:tenantId/services/:serviceId/dashboard`
- `GET /tenants/:tenantId/services/:serviceId/accounts`
- `GET /tenants/:tenantId/services/:serviceId/servers`
- `GET /tenants/:tenantId/services/:serviceId/apis`
- `GET /tenants/:tenantId/services/:serviceId/costs`
- `GET /tenants/:tenantId/services/:serviceId/snapshots`

## 10. Cadastro futuro de novos servicos (regra fixa)
Sempre que um novo servico entrar (ex: Datadog, Grafana, Supabase):
1. Adicionar novo `service.type` no schema/catalogo.
2. Definir `capabilities` desse tipo.
3. Criar adapter de provider (auth, healthcheck, sync).
4. Registrar menu dinamico e rotas.
5. Incluir contrato de tela antes da implementacao.
6. Validar isolamento de dados e auditoria.

## 11. Definition of Done (Front)
- Contrato de tela aprovado antes do codigo.
- Topbar com `Tenant + Service` funcionando.
- Bloqueio de telas operacionais sem servico selecionado.
- Menu lateral dinamico por tipo/capability.
- Estados obrigatorios implementados.
- Sem excesso de acoes e sem overflow de conteudo.

## 12. Processo oficial para uso no Codex
1. Colar este blueprint.
2. Pedir primeiro o "Contrato de Tela" textual.
3. Aprovar contrato.
4. Gerar codigo.
5. Validar checklist de contexto, isolamento e UX.

## 13. Precedencia documental
Este arquivo e a fonte oficial de UI/UX e contratos de pagina.
