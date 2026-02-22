param(
  [string]$VpsHost = "5.78.145.125",
  [string]$VpsUser = "root",
  [string]$DeployDomain = "noc.omniforge.com.br",
  [string]$CertbotEmail = "jesse.freitas@omniforge.com.br",
  [string]$RemoteDeployTar = "/root/orch-ui-deploy.tar.gz",
  [string]$LocalDeployTar = "",
  [string]$VpsPassword = "",
  [switch]$SkipPackage,
  [switch]$DryRun
)

$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RootDir = Split-Path -Parent $ScriptDir
$OrchUiDir = Join-Path $RootDir "orch-ui"
$ProvisionScript = Join-Path $ScriptDir "provision_vps.py"

if (-not (Test-Path $ProvisionScript)) {
  throw "Script de provisionamento nao encontrado: $ProvisionScript"
}

if (-not $LocalDeployTar) {
  $LocalDeployTar = Join-Path $RootDir "orch-ui-deploy.tar.gz"
}

if (-not (Get-Command python -ErrorAction SilentlyContinue)) {
  throw "Python nao encontrado no PATH."
}

if (-not $VpsPassword) {
  $secure = Read-Host "Senha da VPS (root@$VpsHost)" -AsSecureString
  $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
  try {
    $VpsPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
  } finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
  }
}

if (-not $SkipPackage) {
  if (-not (Test-Path $OrchUiDir)) {
    throw "Pasta orch-ui nao encontrada: $OrchUiDir"
  }

  $packageCmd = @(
    "tar -czf `"$LocalDeployTar`" -C `"$OrchUiDir`" .gitignore app components lib public next-env.d.ts next.config.ts package.json package-lock.json tsconfig.json"
  ) -join ""

  if ($DryRun) {
    Write-Host "[dry-run] $packageCmd"
  } else {
    Write-Host "Empacotando deploy em $LocalDeployTar ..."
    Invoke-Expression $packageCmd
  }
}

$env:VPS_HOST = $VpsHost
$env:VPS_USER = $VpsUser
$env:VPS_PASSWORD = $VpsPassword
$env:DEPLOY_DOMAIN = $DeployDomain
$env:CERTBOT_EMAIL = $CertbotEmail
$env:REMOTE_DEPLOY_TAR = $RemoteDeployTar
$env:LOCAL_DEPLOY_TAR = $LocalDeployTar

$deployCmd = "python `"$ProvisionScript`""
if ($DryRun) {
  Write-Host "[dry-run] $deployCmd"
  Write-Host "[dry-run] Env: VPS_HOST=$VpsHost VPS_USER=$VpsUser DEPLOY_DOMAIN=$DeployDomain CERTBOT_EMAIL=$CertbotEmail"
} else {
  Write-Host "Iniciando deploy/provisionamento..."
  Invoke-Expression $deployCmd
  Write-Host "Deploy finalizado."
}

