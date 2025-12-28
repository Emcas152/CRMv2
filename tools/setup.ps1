Param(
  [string]$DbHost = '127.0.0.1',
  [int]$DbPort = 3306,
  [string]$DbName = 'crm_spa_medico',
  [string]$DbUser = 'root',
  [string]$DbPassword = '',
  [string]$MysqlPath = 'mysql',
  [switch]$DropAll,
  [switch]$Seed,
  [string]$AppUrl = 'http://localhost'
)

$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path

Write-Host '==> Instalando dependencias frontend (npm install)' -ForegroundColor Cyan
Push-Location $repoRoot
try {
  if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
    throw "No se encontró 'npm'. Instala Node.js LTS."
  }
  npm install
} finally {
  Pop-Location
}

Write-Host '==> Instalando base de datos' -ForegroundColor Cyan
& (Join-Path $repoRoot 'tools\install.ps1') -DbHost $DbHost -DbPort $DbPort -DbName $DbName -DbUser $DbUser -DbPasswordPlain $DbPassword -MysqlPath $MysqlPath -AppUrl $AppUrl -DropAll:$DropAll -Seed:$Seed

Write-Host '✅ Setup completado.' -ForegroundColor Green
Write-Host 'Siguientes pasos sugeridos:'
Write-Host '  Frontend: npm start'
Write-Host '  Backend (XAMPP/Apache): apunta el docroot a /backend/public o configura VirtualHost'
