param(
  [string]$VpsHost = "5.78.145.125",
  [string]$VpsUser = "root",
  [string]$DeployDomain = "noc.omniforge.com.br",
  [string]$LocalAdmintyDir = "d:\vscode\noc_orquestrador\repos\adminty-dashboard-upstream\files\extra-pages\landingpage",
  [string]$VpsPassword = ""
)

$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$DeployScript = Join-Path $ScriptDir "deploy_adminty.py"

if (-not (Test-Path $DeployScript)) {
  throw "Script Python nao encontrado: $DeployScript"
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

$env:VPS_HOST = $VpsHost
$env:VPS_USER = $VpsUser
$env:VPS_PASSWORD = $VpsPassword
$env:DEPLOY_DOMAIN = $DeployDomain
$env:LOCAL_ADMINTY_DIR = $LocalAdmintyDir

python $DeployScript
