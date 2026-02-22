# Plano de Integracao OmniPortainerBackup x OmniNOC

## Fase 1 - Benchmark interno

- Comparar as 3 bases por:
  - cobertura de backup
  - simplicidade de restore
  - manutencao e confiabilidade

## Fase 2 - Encapsulamento

- Criar adaptador interno unico (`omniportainerbackup`).
- Padronizar entrada/saida de artefatos.

## Fase 3 - Orquestracao

- Expor como runbooks no `orch-api`:
  - `portainer_backup`
  - `portainer_restore`
  - `portainer_validate`

## Fase 4 - Governanca

- RBAC por perfil e area.
- Auditoria completa em cada acao.
- Alertas de falha de backup/restore.
