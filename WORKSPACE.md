# Workspace Codex

## Objetivo
Centralizar referencias de AGENTS.md e SDKs de agentes para orientar implementacao do orquestrador NOC.

## Fonte canonica de arquitetura
- Documento principal: `docs/active/OMNINOC_ARQUITETURA_CANONICA.md`
- Documento principal de UI/UX: `docs/active/OMNINOC_BLUEPRINT_CANONICO.md`
- Em caso de conflito entre documentos, seguir sempre o arquivo canonico.

## Organizacao de documentacao
- Documentacao ativa: `docs/active/`
- Documentacao de legado: `docs/archive/`
- Politicas de governanca: `docs/policies/`
- Registro da migracao: `docs/MIGRATION_2026-02-23.md`
- Politica de legados: `docs/policies/LEGACY_POLICY.md`

## Higiene da raiz (obrigatorio)
- A raiz deve manter apenas arquivos operacionais/canonicos.
- Novos documentos devem nascer em `docs/active/`.
- Documento descontinuado deve ir para `docs/archive/` (nao deletar).
- Rodar verificacao periodica:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\check-root-hygiene.ps1
```

## Repositorios clonados
- `repos/agents.md`
- `repos/openai-agents-python`
- `repos/openai-agents-js`
- `repos/swarm`
- `repos/energia-bmatch`
- `repos/bmad-code-org/BMAD-METHOD`
- `repos/bmad-code-org/*` (todos os publicos da organizacao)

## Comando de sincronizacao
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\sync-repos.ps1
```

## Sync da organizacao BMAD
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\sync-bmad-org.ps1
```

## Backup pre-mudanca (obrigatorio)
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\pre-change-backup.ps1
```

## Executar comando com backup automatico
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\run-with-backup.ps1 -Command "<comando>"
```

## Instalar backup periodico (opcional)
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install-backup-schedule.ps1 -StartTime 08:00 -EndTime 22:00 -RetentionCount 3
```

## Organizacao recomendada para inicio
1. Ler `repos/agents.md` para consolidar padrao de `AGENTS.md`.
2. Definir `AGENTS.md` raiz deste projeto com regras de execucao e qualidade.
3. Extrair exemplos minimos dos SDKs:
   - Python (`openai-agents-python`)
   - JS/TS (`openai-agents-js`)
4. Criar backlog inicial do NOC por fases:
   - Fase 1: Auth/RBAC + Jobs + Runner
   - Fase 2: Portainer + Logs
   - Fase 3: Provisionamento + GitHub ingest
   - Fase 4: IA assistida com aprovacao
