# PACOTE CODEX - ORQUESTRADOR DEVOPS (JARVIS ENTERPRISE)

## 1. Objetivo
Implementar um orquestrador web enterprise com:
- login + RBAC
- execucao de runbooks Ansible via API
- jobs assincronos com logs em tempo real
- integracoes Cloudflare, Portainer, n8n, provisionamento VPS
- observabilidade e auditoria
- IA DevOps assistida (com aprovacao humana)

## 2. Ordem Exata de Implementacao
1. DevOps Base (Swarm, Traefik, secrets, DB, Redis, artifacts, observability)
2. Back-end Base (Auth/RBAC, Jobs, Connections, Audit, Logs SSE/WS)
3. Worker + Runner (fila Redis + ansible-runner + artifacts)
4. Front-end Base (login, dashboard, jobs, logs)
5. Runbooks iniciais (cloudflare_dns_bulk, swarm_deploy, portainer_inventory/logs)
6. Monitoring (docker events + incident pipeline)
7. IA v1 (diagnostico read-only)
8. VPS Provision (create + bootstrap + deploy)
9. GitHub Ingest (orch.yaml + install pipeline)
10. IA v2 (plan approve -> jobs auditaveis)

## 3. Equipes e Responsabilidades

### Front-end
- Next.js App Router + TS + Tailwind + shadcn
- `/login` com layout obrigatorio: hero esquerda 60%, form direita 40%
- dashboard com widgets operacionais
- runbooks com formulario dinamico por `schema_json`
- jobs com timeline, logs streaming e artifacts
- aplicar paleta oficial MEGA definida em `DESIGN_SYSTEM_MEGA.md`

DoD Front:
- login funcionando com JWT/refresh
- dashboard com dados reais
- jobs em tempo real via SSE/WS
- tokens de tema implementados para dark/light conforme padrao MEGA

### Back-end (Control Plane)
- Auth JWT + refresh + RBAC (admin/operator/viewer)
- modelagem Postgres: users, roles, connections, runbooks, jobs, job_steps, audit_log
- conexoes criptografadas (AES-GCM + chave via secret)
- endpoint `POST /v1/runbooks/:name/execute` cria job e publica na fila
- streaming de logs por job

DoD Back:
- RBAC ativo
- jobs assincronos funcionais
- audit log para acoes criticas

### DevOps
- stack Swarm do orquestrador (ui/api/worker/runner/db/redis)
- Traefik com TLS automatico
- observabilidade com Loki, Promtail, Grafana
- CI/CD (lint, test, build, push, deploy)
- hardening (SSH/firewall/fail2ban) + backup DB/artifacts

DoD DevOps:
- stack deployavel via `docker stack deploy`
- logs centralizados
- rollback documentado

### IA/ML
- base de conhecimento: logs, incidentes, resolucoes
- RAG para diagnostico
- plano estruturado com aprovacao obrigatoria

DoD IA:
- `/v1/ai/diagnose` retorna hipoteses e acoes sugeridas
- sem execucao automatica sem approve

## 4. Contratos Minimos

### Auth
- `POST /v1/auth/login`
- `POST /v1/auth/refresh`
- `GET /v1/me`

### Runbooks/Jobs
- `GET /v1/runbooks`
- `POST /v1/runbooks/:name/execute`
- `GET /v1/jobs`
- `GET /v1/jobs/:id`
- `GET /v1/jobs/:id/logs/stream` (SSE) ou `WS /v1/ws/jobs/:id`

### Connections
- `POST /v1/connections`
- `GET /v1/connections`
- `PATCH /v1/connections/:id`
- `DELETE /v1/connections/:id`

### Portainer
- `GET /v1/portainer/endpoints`
- `GET /v1/portainer/containers?endpointId=...`
- `GET /v1/portainer/containers/:id/logs?endpointId=...`

### IA
- `POST /v1/ai/diagnose`
- `POST /v1/ai/plan`
- `POST /v1/ai/plan/:id/approve`

## 5. Runbooks Obrigatorios (Fase Inicial)
- `cloudflare_dns_bulk`
- `swarm_deploy`
- `portainer_inventory`
- `portainer_logs`
- `n8n_backup`
- `vps_provision`

Padrao de artifacts por job:
- `/artifacts/<jobId>/output.json`
- `/artifacts/<jobId>/logs.ndjson`
- `/artifacts/<jobId>/artifacts.tar.gz`

## 6. Regras de Seguranca
- nunca executar playbook de forma sincrona no request HTTP
- segredos fora de codigo/env plain (Swarm secrets ou Vault)
- auditoria para toda acao critica
- IA em modo assistido (approve obrigatorio)
- principio de menor privilegio para tokens

## 7. Prompt Mestre para Codex
Use este padrao em cada tarefa:
1. Contexto do modulo
2. Contrato de API
3. Modelo de dados
4. Regras de negocio
5. Exemplo request/response
6. Requisitos de seguranca
7. Definition of Done

Exemplo de comando:
"Implemente modulo Auth no `orch-api` com JWT+refresh+RBAC, migrations de users/roles, rate limit no login e testes basicos. Entregar endpoints, OpenAPI e DoD validado."

## 8. Ferramentas de Apoio no GitHub
- GitHub Copilot (Agent/Coding workflows)
- Codex (implementacao dirigida por modulo)
- Continue.dev (review/checks em PR)
- Sourcegraph Cody (navegacao de codebase grande)

Regra operacional:
- agentes ajudam em contexto/revisao
- Codex executa implementacao modular com contratos
- merge sempre com revisao humana
