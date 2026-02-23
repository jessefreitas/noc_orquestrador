# OMNINOC Arquitetura Canonica

## Objetivo
Definir uma unica fonte de verdade para arquitetura do OmniNOC, evitando conflito de conceitos e garantindo escalabilidade.

Documentos complementares obrigatorios:
- `OMNINOC_BLUEPRINT_CANONICO.md` (UI/UX e contratos de tela)
- `MATRIZ_CONFLITOS_DOCUMENTOS.md` (historico de consolidacao)

## Escopo oficial (V2)
- Plataforma multi-tenant e multi-servico.
- Cada `tenant` pode ter varios `services`.
- Cada `service` representa um dominio operacional isolado (hetzner, cloudflare, proxmox, n8n, portainer, mega, llm, etc).
- Dentro de cada `service` ficam os fornecedores/contas daquele escopo (tokens, credenciais e integracoes).
- Recursos operacionais sempre pertencem ao `service`.

Arquitetura oficial:
- `Tenant -> Service -> Contas/Servidores/APIs/Snapshots/Custos`

## Modelo de dominio oficial
Entidades principais:
- `tenants`
- `services`
- `service_accounts`
- `servers`
- `apis`
- `job_runs`
- `audit_events`

Relacoes obrigatorias:
- `services.tenant_id -> tenants.id`
- `service_accounts.service_id -> services.id`
- `servers.service_id -> services.id`
- `apis.service_id -> services.id`

Semantica oficial:
- `service`: escopo de operacao do tenant (ex: "Hetzner Producao", tipo `hetzner`).
- `service_accounts`: fornecedores/credenciais conectadas dentro do service.

Regra critica:
- `servers` e `apis` nunca devem depender diretamente de `tenant_id` sem `service_id`.

## Modelo de banco obrigatorio
Base minima:
- `tenants(id, name, status, created_at)`
- `services(id, tenant_id, type, name, credentials_encrypted, capabilities, status, created_at)`
- `servers(id, service_id, external_id, metadata_json, status, created_at, updated_at)`
- `apis(id, service_id, external_id, token_encrypted, metadata_json, status, created_at, updated_at)`

Sobre `services.type`:
- Pode usar `ENUM('hetzner','cloudflare','proxmox','n8n','portainer','mega','llm')`.
- Para suportar novos servicos no futuro, cada novo tipo exige migracao de schema (`ALTER TYPE`) e registro no catalogo de capacidades.

## Regras obrigatorias de isolamento
1. Toda leitura e escrita deve validar `tenant_id + service_id`.
2. Sem contexto de servico selecionado, paginas operacionais devem bloquear com estado `service_context_required`.
3. Credenciais sempre criptografadas (AES-GCM) e nunca logadas.
4. Toda mutacao gera `audit_events`.
5. Operacoes pesadas (sync, snapshot, inventario) rodam por `job_runs` assincrono.

## Contrato de contexto
Contexto global de sessao:
- `tenant_id`
- `service_id`

Topbar oficial:
- `[Tenant] [Service]`

Estado invalido:
- tenant sem service selecionado permite apenas paginas globais (Empresas e Servicos).

## Navegacao oficial
Global:
- Empresas
- Servicos

Dentro do servico selecionado:
- Dashboard
- Contas (fornecedores)
- Servidores
- APIs
- Snapshots
- Custos
- Config e Acesso

Itens adicionais por tipo:
- Cloudflare: Dominios, DNS, WAF, Cache
- ProxMox: Nodes, VMs, Storage, Backups
- N8N: Workflows, Executions
- Portainer: Endpoints, Stacks
- LLM: Modelos, Chaves, Uso/quotas

## Contrato minimo de backend
Contexto:
- `GET /tenants`
- `GET /tenants/:tenantId/services`
- `GET /tenants/:tenantId/services/:serviceId/context`

Servico:
- `GET /tenants/:tenantId/services/:serviceId/dashboard`
- `GET /tenants/:tenantId/services/:serviceId/accounts`
- `GET /tenants/:tenantId/services/:serviceId/servers`
- `GET /tenants/:tenantId/services/:serviceId/servers/:serverId`
- `GET /tenants/:tenantId/services/:serviceId/apis`
- `GET /tenants/:tenantId/services/:serviceId/apis/:apiId`
- `GET /tenants/:tenantId/services/:serviceId/snapshots`
- `GET /tenants/:tenantId/services/:serviceId/costs`

## Onboarding de novos servicos (obrigatorio)
1. Registrar novo `type` de servico no schema/catalogo.
2. Definir template de `capabilities` do tipo.
3. Implementar adapter do provider (auth, healthcheck, sync, listagem).
4. Registrar menu e rotas por capacidades.
5. Incluir contratos de tela no blueprint antes de codar.
6. Validar isolamento e auditoria em testes.

## Padronizacao de nomes
- Usar sempre `Hetzner`.
- Usar sempre `Cloudflare`.
- Usar sempre `Tenant` e `Service` no contrato oficial.

## Backlog oficial V3 (IA e conhecimento)
Item reservado para V3 conforme direcionamento do projeto:
- Modulo `AI Chat` com historico por `tenant + service`.
- Base de conhecimento com `pgvector` para embeddings e busca semantica.
- Pipeline RAG (retrieval + resposta com citacao de fontes internas).
- Upload de PDF no sistema com processamento e indexacao.
- Sumarizacao automatica de documentos usando bibliotecas Python.
- Ingestao incremental (reindex por arquivo/versao) e auditoria de ingestao.
- Configuracao de modelos/chaves LLM por empresa (OpenAI, OpenRouter/OpenOuter, Z.ai e demais).
- Coleta automatizada de documentacao de fornecedores (crawler/scraper) com politica de firewall e compliance.

Diretriz de isolamento:
- Nenhum chunk/documento/vetor pode vazar entre tenants ou servicos.
- Toda consulta RAG deve filtrar obrigatoriamente por contexto ativo.

Diretriz de coleta externa (V3):
- Priorizar fontes oficiais (documentacao, OpenAPI, changelog).
- Respeitar robots/ToS, rate limits e politicas dos fornecedores.
- Proibido bypass de bloqueios (captcha, anti-bot, WAF challenge).
- Usar allowlist de dominios de saida por fornecedor e registrar trilha de auditoria.
- Armazenar snapshot de origem (URL, hash, timestamp) para rastreabilidade.

## Precedencia
Este documento e a fonte oficial de arquitetura.
