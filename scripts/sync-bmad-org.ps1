$ErrorActionPreference = "Stop"

$Root = Split-Path -Parent $PSScriptRoot
$Base = Join-Path $Root "repos\bmad-code-org"
New-Item -ItemType Directory -Force -Path $Base | Out-Null

$Api = "https://api.github.com/orgs/bmad-code-org/repos?per_page=100"
$Repos = Invoke-RestMethod -Uri $Api -Headers @{ "User-Agent" = "codex-cli" }

foreach ($Repo in $Repos) {
  $Name = $Repo.name
  $Url = $Repo.clone_url
  $Target = Join-Path $Base $Name

  if (Test-Path (Join-Path $Target ".git")) {
    Write-Host "[UPDATE] $Name"
    git -C $Target pull --rebase --autostash
  } else {
    Write-Host "[CLONE]  $Name"
    git clone --depth=1 $Url $Target
  }
}

Write-Host ""
Write-Host "Repos BMAD sincronizados em: $Base"
Get-ChildItem $Base | Select-Object Name, LastWriteTime
