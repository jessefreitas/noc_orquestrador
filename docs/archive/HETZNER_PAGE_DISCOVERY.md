# Pagina Hetzner - Discovery Inicial

Status: aguardando definicoes do produto antes de implementar.

## Decisoes Ja Fechadas

1. Escopo nao sera apenas Hetzner para sempre; arquitetura deve suportar multiplos provedores.
2. Hetzner pode importar servidores via API.
3. Execucao de servicos exige aceite explicito por item (controle por servidor/servico).
4. Backup e snapshot serao executados internamente no sistema.
5. Agendamento deve ser programavel pelo usuario.
6. Integracao tecnica com Hetzner sera via API oficial.
7. Armazenamento padrao para Hetzner fica na propria Hetzner via API.
8. Retencao sera configurada por usuario (dias, quantidade, frequencia).
9. Multi-tenant entra na V1 de forma simples (tudo vinculado por empresa).
10. Interface sera por secoes separadas por servico.

## Problema

Precisamos de uma pagina para cadastrar e gerenciar multiplos servidores Hetzner, com politicas diferentes de backup e snapshot por servidor.

## Escopo V1 (proposto)

1. Cadastrar servidor Hetzner (nome, projeto, datacenter, IP, tags).
2. Definir politica de backup por servidor.
3. Definir politica de snapshot por servidor.
4. Listar status por servidor (ultimo backup, ultimo snapshot, proxima execucao).
5. Registrar historico de execucoes (sucesso/falha).
6. Estrutura de navegacao por servico: `Hetzner > Cadastro | Servicos | Alertas | Logs`.

## Modelo de dados (rascunho)

- `providers`
  - `id`, `name` (ex.: hetzner), `token_ref`, `created_at`
- `servers`
  - `id`, `provider_id`, `external_id`, `name`, `region`, `ip`, `tags`, `active`
- `backup_policies`
  - `id`, `server_id`, `enabled`, `schedule_cron`, `retention_days`, `storage_target`
- `snapshot_policies`
  - `id`, `server_id`, `enabled`, `schedule_cron`, `retention_count`, `naming_pattern`
- `job_runs`
  - `id`, `server_id`, `job_type` (backup/snapshot), `started_at`, `finished_at`, `status`, `message`

## Perguntas em Aberto

1. Quais status no dashboard: ok, atraso, falha, sem politica, pausado?
2. V1 inclui restauracao ou somente cadastro + politicas + execucao + historico?
3. Modelo de aceite: confirmacao em toda execucao ou habilitacao previa por flag?

## Criterio de pronto da V1

1. Cadastrar N servidores Hetzner.
2. Definir politicas diferentes por servidor.
3. Executar e registrar backup/snapshot com historico.
4. Exibir status consolidado em uma pagina unica.
