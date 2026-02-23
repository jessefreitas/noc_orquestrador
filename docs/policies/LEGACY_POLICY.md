# Legacy Policy

## Objetivo
Manter o workspace organizado, reduzindo ruído na raiz e preservando historico sem delecoes acidentais.

## Regras obrigatorias
1. Novos documentos devem ser criados em `docs/active/`.
2. Documentos descontinuados devem ser movidos para `docs/archive/`.
3. Nao deletar arquivos de legado sem aprovacao explicita.
4. Artefatos temporarios (export, tar, dump, conf avulso) nao devem ficar na raiz.
5. Toda reorganizacao deve registrar evidencias em `codex.md`.

## Escopo da raiz
A raiz do repositorio deve conter apenas:
- arquivos operacionais canonicos (ex.: `WORKSPACE.md`, `AGENTS_SQUAD.md`, `codex.md`, `agent.md`)
- diretórios de codigo/projeto
- arquivos de infraestrutura realmente ativos para bootstrap

Todo o restante deve estar em `docs/active/`, `docs/archive/` ou `backups/`.

## Processo de saneamento
1. Rodar a verificacao:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\check-root-hygiene.ps1
```
2. Classificar cada item:
- `ativo` -> mover para `docs/active/`
- `legado` -> mover para `docs/archive/`
- `snapshot/artefato` -> mover para `backups/<lote>/`
3. Registrar no `codex.md`:
- problema
- causa
- correcao
- validacao

## Cadencia
- Verificacao minima semanal.
- Verificacao adicional antes de qualquer release.
