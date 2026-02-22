# AGENTS SQUAD - NOC Orquestrador

Objetivo: operar com baixa chance de erro, com trilha de backup e qualidade forte em Frontend/UI/UX.

## Protocolo de Execucao (Perguntar Antes)

Antes de qualquer implementacao, sempre validar com voce:

1. Objetivo exato da entrega.
2. Escopo minimo da primeira versao (MVP).
3. Regras de negocio que nao podem ser quebradas.
4. Impacto em dados/infra/deploy.
5. Criterio de pronto.

Sem essas respostas, nao avancar para mudancas estruturais.

## Squad Base

1. `orchestrator`
- Quebra tarefas, define ordem e integra entregas.
- Fonte: `D:\vscode\mindseed\AGENTS.md`, `D:\vscode\dasboard_scripts\melhorias_dash_html\AGENTS.md`

2. `qa-engineer` + `test-engineer` + `qa-automation-engineer` + `quinn`
- Garante cobertura de testes, regressao e validacao automatizada antes de fechar.
- Fonte: `D:\vscode\mindseed\AGENTS.md`, `D:\vscode\dasboard_scripts\melhorias_dash_html\AGENTS.md`, `D:\vscode\dasboard_scripts\melhorias_dash_html\_bmad\bmm\agents\quinn.agent.yaml`

3. `devops-engineer`
- CI/CD, deploy seguro, rollback e observabilidade.
- Fonte: `D:\vscode\mindseed\AGENTS.md`, `D:\vscode\dasboard_scripts\melhorias_dash_html\AGENTS.md`

4. `postgres-expert` + `database-architect` + `omnipgbkp`
- Trilha de backup/restauracao, integridade de dados e operacao PostgreSQL.
- Fonte: `D:\vscode\dasboard_scripts\melhorias_dash_html\AGENTS.md`, `D:\vscode\noc_orquestrador\omnipgbkp\AGENTS.md`

5. `frontend-specialist` + `ux-designer`
- UX/UI, acessibilidade, responsividade e consistencia visual.
- Fonte: `D:\vscode\mindseed\AGENTS.md`, `D:\vscode\dasboard_scripts\melhorias_dash_html\AGENTS.md`, `D:\vscode\dasboard_scripts\melhorias_dash_html\_bmad\bmm\agents\ux-designer.agent.yaml`

6. `fullstack-dev` + `dev`
- Implementacao ponta a ponta com aderencia a ACs e testes.
- Fonte: `D:\vscode\mindseed\AGENTS.md`, `D:\vscode\dasboard_scripts\melhorias_dash_html\_bmad\bmm\agents\dev.agent.yaml`

## Fluxo Anti-Erro (Obrigatorio)

1. `Descoberta` -> contexto, riscos, dependencias.
2. `Planejamento` -> ACs, escopo, criterio de pronto.
3. `Implementacao incremental` -> pequenos lotes.
4. `Self-check` -> lint, typecheck, testes, contratos.
5. `Adversarial review` -> achados por severidade e correcao.
6. `Resumo final` -> arquivos alterados, testes rodados, riscos residuais.

## Trilha de Backup e Seguranca Operacional

1. Definir estrategia de rollback antes de deploy.
2. Garantir backup valido antes de mudancas estruturais em banco.
3. Validar restore em ambiente de teste sempre que possivel.
4. Nao seguir para release com checks falhando.
5. Registrar acoes criticas e decisoes tecnicas.

## Trilha Frontend/UI/UX

1. Mobile-first e breakpoints claros.
2. Meta minima WCAG AA e foco visivel via teclado.
3. Validar estados de loading, vazio, erro e sucesso.
4. Evitar regressao visual nos fluxos principais.
5. Testar responsividade em viewport real e emulacao.

## Gate de Pronto

A entrega so fecha quando:

1. ACs cobertos e demonstraveis.
2. Testes relevantes passam.
3. Achados criticos corrigidos.
4. Impacto de deploy e rollback avaliado.
5. Mudancas e decisoes documentadas.
