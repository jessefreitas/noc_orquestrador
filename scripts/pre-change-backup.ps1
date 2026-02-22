param(
  [string]$WorkspaceRoot = (Split-Path -Parent $PSScriptRoot),
  [string]$BackupRoot = "",
  [int]$RetentionCount = 3
)

$ErrorActionPreference = "Stop"
if (Get-Variable PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue) {
  $PSNativeCommandUseErrorActionPreference = $false
}

if ([string]::IsNullOrWhiteSpace($BackupRoot)) {
  $BackupRoot = Join-Path $WorkspaceRoot "backups"
}

New-Item -ItemType Directory -Force -Path $BackupRoot | Out-Null

$Timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$RunDir = Join-Path $BackupRoot "prechange-$Timestamp"
$BundlesDir = Join-Path $RunDir "bundles"
$DiffsDir = Join-Path $RunDir "diffs"
$MetaDir = Join-Path $RunDir "meta"

New-Item -ItemType Directory -Force -Path $RunDir, $BundlesDir, $DiffsDir, $MetaDir | Out-Null

function Get-SafeName([string]$value) {
  return ($value -replace "[\\/:*?`"<>| ]", "_")
}

function Get-RelativePathCompat([string]$basePath, [string]$targetPath) {
  $baseResolved = (Resolve-Path $basePath).Path
  $targetResolved = (Resolve-Path $targetPath).Path

  if ($baseResolved -eq $targetResolved) {
    return "."
  }

  $baseWithSlash = $baseResolved.TrimEnd("\") + "\"
  $baseUri = New-Object System.Uri($baseWithSlash)
  $targetUri = New-Object System.Uri($targetResolved)
  $relativeUri = $baseUri.MakeRelativeUri($targetUri)
  return [System.Uri]::UnescapeDataString($relativeUri.ToString()).Replace("/", "\")
}

function Get-RepoList([string]$root) {
  $repos = New-Object System.Collections.Generic.List[string]

  if (Test-Path (Join-Path $root ".git")) {
    $repos.Add((Resolve-Path $root).Path)
  }

  $gitDirs = Get-ChildItem -Path $root -Recurse -Directory -Force -Filter ".git" |
    Where-Object { $_.FullName -notmatch "\\backups\\" }

  foreach ($gitDir in $gitDirs) {
    $repoDir = Split-Path -Parent $gitDir.FullName
    if (-not $repos.Contains($repoDir)) {
      $repos.Add($repoDir)
    }
  }

  return $repos | Sort-Object -Unique
}

function Test-RepoHasCommits([string]$repoPath) {
  $quotedRepo = $repoPath.Replace('"', '""')
  cmd /c "git -C ""$quotedRepo"" rev-parse --verify HEAD >nul 2>nul"
  return ($LASTEXITCODE -eq 0)
}

function Invoke-GitChecked {
  param(
    [Parameter(Mandatory = $true)][string[]]$Args,
    [Parameter(Mandatory = $true)][string]$ErrorMessage
  )

  & git @Args
  if ($LASTEXITCODE -ne 0) {
    throw $ErrorMessage
  }
}

$RepoList = Get-RepoList -root $WorkspaceRoot
if (-not $RepoList -or $RepoList.Count -eq 0) {
  throw "Nenhum repositorio Git encontrado em $WorkspaceRoot"
}

$Manifest = New-Object System.Collections.Generic.List[object]

foreach ($Repo in $RepoList) {
  $Rel = Get-RelativePathCompat -basePath $WorkspaceRoot -targetPath $Repo
  if ([string]::IsNullOrWhiteSpace($Rel) -or $Rel -eq ".") {
    $Rel = "workspace-root"
  }

  $Safe = Get-SafeName $Rel
  $BundlePath = Join-Path $BundlesDir "$Safe.bundle"
  $StatusPath = Join-Path $MetaDir "$Safe.status.txt"
  $LogPath = Join-Path $MetaDir "$Safe.log.txt"
  $DiffPath = Join-Path $DiffsDir "$Safe.patch"
  $DiffStagedPath = Join-Path $DiffsDir "$Safe.staged.patch"

  $Branch = (git -C $Repo branch --show-current).Trim()
  if ([string]::IsNullOrWhiteSpace($Branch)) {
    $Branch = "(detached-or-empty)"
  }

  $HasCommits = Test-RepoHasCommits -repoPath $Repo
  $Commit = ""
  if ($HasCommits) {
    $Commit = (git -C $Repo rev-parse HEAD).Trim()
  }

  $DirtyRaw = git -C $Repo status --porcelain
  $Dirty = -not [string]::IsNullOrWhiteSpace(($DirtyRaw -join ""))

  if ($HasCommits) {
    Invoke-GitChecked -Args @("-C", $Repo, "bundle", "create", $BundlePath, "--all") -ErrorMessage "Falha ao criar bundle para $Rel"
    git -C $Repo log --oneline -n 30 | Out-File -Encoding utf8 $LogPath
  } else {
    "Repositorio sem commits. Bundle nao aplicavel." | Out-File -Encoding utf8 $LogPath
  }

  git -C $Repo status --short --branch | Out-File -Encoding utf8 $StatusPath

  if ($Dirty) {
    git -C $Repo diff | Out-File -Encoding utf8 $DiffPath
    git -C $Repo diff --staged | Out-File -Encoding utf8 $DiffStagedPath
  }

  $Manifest.Add([pscustomobject]@{
      repo_rel_path = $Rel
      repo_full_path = $Repo
      branch = $Branch
      commit = $Commit
      has_commits = $HasCommits
      dirty = $Dirty
      bundle = if ($HasCommits) { $BundlePath } else { "" }
      status_file = $StatusPath
      log_file = $LogPath
      diff_file = if ($Dirty) { $DiffPath } else { "" }
      staged_diff_file = if ($Dirty) { $DiffStagedPath } else { "" }
    })
}

$ManifestPath = Join-Path $RunDir "manifest.json"
$Manifest | ConvertTo-Json -Depth 5 | Out-File -Encoding utf8 $ManifestPath

$LatestPath = Join-Path $BackupRoot "LATEST"
$RunDir | Out-File -Encoding utf8 $LatestPath

if ($RetentionCount -lt 1) {
  throw "RetentionCount deve ser maior ou igual a 1."
}

$AllBackups = Get-ChildItem -Path $BackupRoot -Directory -Filter "prechange-*" |
  Sort-Object LastWriteTime -Descending

$ToRemove = $AllBackups | Select-Object -Skip $RetentionCount
foreach ($Old in $ToRemove) {
  Remove-Item -Recurse -Force $Old.FullName
}

Write-Host "Checkpoint criado em: $RunDir"
Write-Host "Manifesto: $ManifestPath"
Write-Host "Repositorios salvos: $($Manifest.Count)"
Write-Host "Retencao aplicada: ultimos $RetentionCount backups"
