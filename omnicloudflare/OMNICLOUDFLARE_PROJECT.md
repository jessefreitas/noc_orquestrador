# OmniCloudflare (Projeto Interno)

Projeto interno da OmniForge baseado no cloudflare-backup, preparado para integracao futura com o OmniNOC.

## Objetivo

Criar um modulo interno para backup e governanca de configuracoes Cloudflare com:

- identidade OmniForge
- execucao controlada por RBAC
- trilha de auditoria
- operacao via runbooks do orquestrador

## Diretrizes

- Sem operacao externa desacoplada no produto final.
- Segredos sempre em storage seguro.
- Toda acao mutavel deve gerar evento de auditoria.
