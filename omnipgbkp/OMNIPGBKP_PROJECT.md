# OmniPgBkp (Projeto Interno)

Projeto interno da OmniForge baseado no `pgbackweb`, preparado para evolucao e integracao com o OmniNOC.

## Objetivo

Transformar o motor de backup de PostgreSQL em um modulo nativo da plataforma, com:

- identidade OmniForge (`OmniPgBkp`)
- controle de acesso por RBAC e areas
- auditoria central
- operacao no mesmo ambiente do sistema atual

## Status

- Base do `pgbackweb` copiada para `omnipgbkp/`
- Projeto separado, sem deploy automatico
- Estrutura pronta para evolucao em branch propria

## Estrutura de trabalho recomendada

1. `main`: linha estavel interna.
2. `feature/branding-omnipgbkp`: ajustes visuais/textuais.
3. `feature/rbac-integration`: acoplamento com auth/roles do OmniNOC.
4. `feature/audit-and-observability`: trilha de auditoria e alertas.

## Principios tecnicos

- Nao rodar fora do stack atual no produto final.
- Evitar forks desorganizados: manter docs de divergencia do upstream.
- Toda melhoria interna deve ter criterio de rollback.

## Licenca

A base original e AGPLv3. Manter conformidade da licenca nas customizacoes.
