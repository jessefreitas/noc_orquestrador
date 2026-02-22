# Plano de Integracao OmniPgBkp x OmniNOC

## Fase 1 - Internalizacao (sem deploy)

- Congelar baseline atual do codigo.
- Definir branding e convencoes de nome internas.
- Catalogar pontos de extensao:
  - autenticacao
  - autorizacao
  - auditoria
  - webhooks/alertas

## Fase 2 - Integracao de identidade e acesso

- Integrar login com o provedor de auth do OmniNOC.
- Mapear perfis:
  - `admin_bkp`: controle total
  - `operator_bkp`: execucao e leitura
  - `viewer_bkp`: somente leitura
- Aplicar escopo por areas (ex.: `financeiro`, `infra`, `clientes`).

## Fase 3 - Integracao operacional

- Publicar sob o mesmo dominio da plataforma:
  - exemplo: `/omnipgbkp` ou subdominio interno.
- Persistir backups em volume local padronizado.
- Habilitar destino secundario S3 quando definido.

## Fase 4 - Governanca e confiabilidade

- Enviar eventos para trilha de auditoria central (`orch-api`).
- Criar padrao de retencao por ambiente.
- Criar playbook de restore validado.

## Fase 5 - Produto interno consolidado

- Painel unificado no OmniNOC.
- Indicadores de sucesso/falha de backup no dashboard.
- Fluxo de aprovacao para restore em producao.
