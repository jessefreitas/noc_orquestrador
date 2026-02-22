param(
  [Parameter(Mandatory = $true)]
  [string]$Command,
  [int]$RetentionCount = 3,
  [int]$StartHour = 8,
  [int]$EndHour = 22,
  [switch]$ForceOutsideHours
)

$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$BackupScript = Join-Path $ScriptDir "pre-change-backup.ps1"

if (-not (Test-Path $BackupScript)) {
  throw "Script de backup nao encontrado em: $BackupScript"
}

if ($StartHour -lt 0 -or $StartHour -gt 23 -or $EndHour -lt 0 -or $EndHour -gt 23) {
  throw "StartHour/EndHour devem estar entre 0 e 23."
}

$NowHour = (Get-Date).Hour
$InWindow = ($NowHour -ge $StartHour -and $NowHour -le $EndHour)

if (-not $InWindow -and -not $ForceOutsideHours) {
  $Answer = Read-Host "Fora do horario ($StartHour:00-$EndHour`:59). Deseja executar backup agora? (s/N)"
  if ($Answer -notin @("s", "S", "sim", "SIM", "y", "Y", "yes", "YES")) {
    throw "Backup cancelado fora do horario por decisao do usuario."
  }
}

Write-Host "Executando checkpoint pre-mudanca..."
powershell -ExecutionPolicy Bypass -File $BackupScript -RetentionCount $RetentionCount

Write-Host "Checkpoint OK. Executando comando:"
Write-Host $Command

Invoke-Expression $Command
