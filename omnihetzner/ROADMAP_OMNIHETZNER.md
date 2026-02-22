# Roadmap OmniHetzner

## Sprint 0 - Preparacao

- [x] Clonar bases Hetzner
- [x] Isolar em projeto separado
- [x] Definir docs de arranque

## Sprint 1 - hcloud-python (prioridade sugerida)

- [ ] Criar PoC de provisionamento de VPS
- [ ] Padronizar naming/tagging de servidores
- [ ] Integrar operacoes no `orch-api` como runbooks

## Sprint 2 - ansible-role-aptly

- [ ] Definir uso de repositorio Debian interno
- [ ] Criar playbook base de publicacao
- [ ] Integrar pipeline de atualizacao de pacotes

## Sprint 3 - nomad-dev-env

- [ ] Subir ambiente de laboratorio
- [ ] Validar workloads de teste
- [ ] Definir se vira trilha de produto ou somente R&D

## Sprint 4 - Cross-cutting

- [ ] RBAC por modulo
- [ ] Auditoria e observabilidade
- [ ] Padrao de rollback por runbook
