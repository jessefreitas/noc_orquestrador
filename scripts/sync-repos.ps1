$ErrorActionPreference = "Stop"

$Root = Split-Path -Parent $PSScriptRoot
$ReposDir = Join-Path $Root "repos"
New-Item -ItemType Directory -Force -Path $ReposDir | Out-Null

$Repos = @(
  "https://github.com/agentsmd/agents.md.git",
  "https://github.com/openai/openai-agents-python.git",
  "https://github.com/openai/openai-agents-js.git",
  "https://github.com/openai/swarm.git",
  "https://github.com/bmatch-org/energia-bmatch.git"
)

foreach ($Repo in $Repos) {
  $Name = [System.IO.Path]::GetFileNameWithoutExtension($Repo)
  $Target = Join-Path $ReposDir $Name

  if (Test-Path (Join-Path $Target ".git")) {
    Write-Host "[UPDATE] $Name"
    git -C $Target pull --rebase --autostash
  } else {
    Write-Host "[CLONE]  $Name"
    git clone --depth=1 $Repo $Target
  }
}

Write-Host ""
Write-Host "Repos sincronizados em: $ReposDir"
Get-ChildItem $ReposDir | Select-Object Name, LastWriteTime
