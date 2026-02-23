# Politica de Backup Pre-Mudanca

## Objetivo
Garantir ponto de restauracao antes de qualquer alteracao de codigo no workspace.

## Regra obrigatoria
Antes de editar qualquer arquivo, executar:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\pre-change-backup.ps1
```

## O que o backup salva
- `git bundle --all` de cada repositorio Git encontrado.
- status Git e log recente de cada repositorio.
- patch (`diff`) de alteracoes locais nao commitadas.
- manifesto consolidado do checkpoint.

## Onde os backups ficam
- Pasta: `.\backups\prechange-YYYYMMDD-HHMMSS\`
- Marcador de ultimo checkpoint: `.\backups\LATEST`

## Retencao
- Padrao: manter sempre os **3 ultimos** checkpoints.
- Ajuste com `-RetentionCount`.

## Restauracao rapida
Para recuperar um repositorio via bundle:

```powershell
git clone .\backups\prechange-YYYYMMDD-HHMMSS\bundles\NOME.bundle .\restore\NOME
```

## Execucao segura (recomendado)
Para nunca esquecer backup, execute comandos de mudanca via:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\run-with-backup.ps1 -Command "<comando>"
```

Fora da janela 08:00-22:00, o script pergunta confirmacao antes de gerar backup.

## Backup periodico automatico (opcional)
Criar tarefa agendada no Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install-backup-schedule.ps1 -StartTime 08:00 -EndTime 22:00 -RetentionCount 3
```
