# Workspace Codex

## Objetivo
Centralizar referências de AGENTS.md e SDKs de agentes para orientar implementação do orquestrador NOC.

## Repositórios clonados
- `repos/agents.md`
- `repos/openai-agents-python`
- `repos/openai-agents-js`
- `repos/swarm`
- `repos/energia-bmatch`
- `repos/bmad-code-org/BMAD-METHOD`
- `repos/bmad-code-org/*` (todos os públicos da organização)

## Comando de sincronização
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\sync-repos.ps1
```

## Sync da organização BMAD
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

## Organização recomendada para início
1. Ler `repos/agents.md` para consolidar padrão de `AGENTS.md`.
2. Definir `AGENTS.md` raiz deste projeto com regras de execução e qualidade.
3. Extrair exemplos mínimos dos SDKs:
   - Python (`openai-agents-python`)
   - JS/TS (`openai-agents-js`)
4. Criar backlog inicial do NOC por fases:
   - Fase 1: Auth/RBAC + Jobs + Runner
   - Fase 2: Portainer + Logs
   - Fase 3: Provisionamento + GitHub ingest
   - Fase 4: IA assistida com aprovação
