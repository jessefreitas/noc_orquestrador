param(
  [string]$TaskName = "NOC-Workspace-Backup",
  [string]$StartTime = "08:00",
  [string]$EndTime = "22:00",
  [int]$RetentionCount = 3
)

$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$BackupScript = Join-Path $ScriptDir "pre-change-backup.ps1"
if (-not (Test-Path $BackupScript)) {
  throw "Script nao encontrado: $BackupScript"
}

$Cmd = "powershell -NoProfile -ExecutionPolicy Bypass -File `"$BackupScript`" -RetentionCount $RetentionCount"

$start = [datetime]::ParseExact($StartTime, "HH:mm", $null)
$end = [datetime]::ParseExact($EndTime, "HH:mm", $null)
if ($end -le $start) {
  throw "EndTime deve ser maior que StartTime no mesmo dia."
}
$duration = $end - $start
$durationArg = "{0:D2}:{1:D2}" -f [int]$duration.TotalHours, $duration.Minutes

schtasks /Create /TN $TaskName /SC DAILY /MO 1 /ST $StartTime /RI 60 /DU $durationArg /TR $Cmd /F | Out-Null

Write-Host "Tarefa agendada criada/atualizada:"
Write-Host "Nome: $TaskName"
Write-Host "Frequencia: diaria, a cada 1 hora entre $StartTime e $EndTime (duracao $durationArg)"
Write-Host "Retencao: ultimos $RetentionCount backups"
Write-Host "Comando: $Cmd"
