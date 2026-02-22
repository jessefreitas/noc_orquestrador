# Plano de Integracao OmniHetzner x OmniNOC

## Fase 1 - Discovery tecnico

- Mapear casos reais para cada modulo:
  - `hcloud-python`: criar/destroi/inspecionar VPS
  - `ansible-role-aptly`: publicar repos internos
  - `nomad-dev-env`: laboratorio de jobs

## Fase 2 - Encapsulamento

- Criar wrappers internos por dominio:
  - `omnihetzner/hcloud_adapter`
  - `omnihetzner/ansible_aptly_adapter`
  - `omnihetzner/nomad_adapter`

## Fase 3 - Orquestracao

- Expor operacoes como runbooks no `orch-api`.
- Parametrizar com secrets e validacoes de entrada.

## Fase 4 - Governanca

- RBAC por tipo de operacao.
- Restricao por area/projeto.
- Auditoria obrigatoria em toda acao mutavel.

## Fase 5 - Operacao

- Dashboard de saude e execucao.
- Alertas por webhook para falhas criticas.
