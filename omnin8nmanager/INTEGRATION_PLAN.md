# Plano de Integracao OmniN8nManager x OmniNOC

## Fase 1 - Internalizacao

- Congelar baseline do script principal (
8n-manager.sh).
- Definir naming interno e padrao de configuracao.
- Catalogar pontos de extensao para integracao com API.

## Fase 2 - Padrao de execucao interna

- Encapsular comandos de backup/restore como runbooks do OmniNOC.
- Remover dependencia de input manual no fluxo produtivo.
- Guardar parametros sensiveis em storage seguro (secrets).

## Fase 3 - RBAC e Areas

- Perfis sugeridos:
  - dmin_n8n_backup
  - operator_n8n_backup
  - iewer_n8n_backup
- Controle por area/tenant de instancias n8n.

## Fase 4 - Auditoria e monitoramento

- Logar cada backup/restore no udit_log central.
- Alertar falhas por webhook (Slack/Discord/email).
- Dash de sucesso/falha no painel do OmniNOC.

## Fase 5 - Produto interno consolidado

- Operacao assistida via UI do OmniNOC.
- Historico com reteno e trilha de restore.
- Politicas por ambiente (dev/hml/prod).
