param(
    [string]$WorkspaceRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
)

$ErrorActionPreference = "Stop"

$allowedRootFiles = @(
    ".deploy-trigger.txt",
    ".gitignore",
    "AGENTS_SQUAD.md",
    "WORKSPACE.md",
    "agent.md",
    "codex.md"
)

$allowedRootDirs = @(
    ".git",
    ".github",
    "agents",
    "backups",
    "docs",
    "omnicloudflare",
    "omnihetzner",
    "omnin8nmanager",
    "omnipgbkp",
    "omniportainerbackup",
    "orch-api",
    "orch-php",
    "orch-ui",
    "repos",
    "scripts"
)

$rootItems = Get-ChildItem -LiteralPath $WorkspaceRoot -Force
$unexpected = @()

foreach ($item in $rootItems) {
    if ($item.PSIsContainer) {
        if ($allowedRootDirs -notcontains $item.Name) {
            $unexpected += $item.FullName
        }
        continue
    }

    if ($allowedRootFiles -notcontains $item.Name) {
        $unexpected += $item.FullName
    }
}

Write-Host "Workspace root: $WorkspaceRoot"
Write-Host "Allowed dirs : $($allowedRootDirs -join ', ')"
Write-Host "Allowed files: $($allowedRootFiles -join ', ')"

if ($unexpected.Count -eq 0) {
    Write-Host "ROOT_HYGIENE_OK: nenhum item inesperado na raiz."
    exit 0
}

Write-Host "ROOT_HYGIENE_WARN: itens fora da politica encontrados:"
$unexpected | ForEach-Object { Write-Host "- $_" }
exit 1
