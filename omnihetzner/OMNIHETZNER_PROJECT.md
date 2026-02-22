# OmniHetzner (Projeto Interno)

Conjunto interno de referencias e bases de codigo Hetzner para integracao progressiva com o ecossistema OmniForge.

## Modulos base

- `hcloud-python`: automacao via API Hetzner Cloud.
- `ansible-role-aptly`: gestao de repositorios Debian internos.
- `nomad-dev-env`: base para lab/POC de workload scheduler.

## Diretriz

- Nenhum modulo entra em producao sem:
  - modelo de seguranca
  - trilha de auditoria
  - estrategia de rollback

## Integracao alvo com OmniNOC

- executar automacoes como runbooks
- registrar eventos no `audit_log`
- controlar operacoes por RBAC e areas
