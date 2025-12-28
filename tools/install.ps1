<#
  Instalador de base de datos para el CRM.
  Crea la BD, aplica el schema y opcionalmente carga seed.
#>
Param(
  [string]$DbHost = '127.0.0.1',
  [int]$DbPort = 3306,
  [string]$DbName = 'crm_spa_medico',
  [string]$DbUser = 'root',
  [SecureString]$DbPassword = $null,
  [string]$DbPasswordPlain = '',
  [string]$MysqlPath = 'mysql',
  [switch]$DropAll,
  [switch]$Seed,
  [string]$AppUrl = 'http://localhost'
)

$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$schemaFile = Join-Path $repoRoot 'backend\docs\schema.mysql.sql'
$seedFile = Join-Path $repoRoot 'backend\docs\seed.mysql.sql'
$envExample = Join-Path $repoRoot 'backend\.env.example'
$envFile = Join-Path $repoRoot 'backend\.env'

if (-not (Get-Command $MysqlPath -ErrorAction SilentlyContinue)) {
  throw "No se encontró el cliente MySQL. Ajusta -MysqlPath (por ejemplo 'C:\\xampp\\mysql\\bin\\mysql.exe') o agrega 'mysql' al PATH."
}

if (-not (Test-Path -LiteralPath $schemaFile)) { throw "No existe: $schemaFile" }
if ($Seed -and -not (Test-Path -LiteralPath $seedFile)) { throw "No existe: $seedFile" }

Write-Host "==> Creando BD si no existe: $DbName" -ForegroundColor Cyan

$dbPasswordPlain = ''
if ($DbPasswordPlain -ne '') {
  $dbPasswordPlain = $DbPasswordPlain
} elseif ($DbPassword) {
  $dbPasswordPlain = (New-Object System.Management.Automation.PSCredential('u', $DbPassword)).GetNetworkCredential().Password
}

$passArg = @()
if ($dbPasswordPlain -ne '') { $passArg = @("-p$dbPasswordPlain") }

$mysqlBaseArgs = @('-h', $DbHost, '-P', $DbPort, '-u', $DbUser) + $passArg
$mysqlDbArgs = $mysqlBaseArgs + @($DbName)

# 1) Crear DB
@(
  "CREATE DATABASE IF NOT EXISTS $DbName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
) | & $MysqlPath @mysqlBaseArgs

# 2) Drop (opcional)
if ($DropAll) {
  Write-Host '==> Eliminando tablas (modo DropAll)' -ForegroundColor Yellow
  @(
    'SET FOREIGN_KEY_CHECKS = 0;',
    'DROP TABLE IF EXISTS comments;',
    'DROP TABLE IF EXISTS tasks;',
    'DROP TABLE IF EXISTS messages;',
    'DROP TABLE IF EXISTS conversation_participants;',
    'DROP TABLE IF EXISTS conversations;',
    'DROP TABLE IF EXISTS updates;',
    'DROP TABLE IF EXISTS document_signatures;',
    'DROP TABLE IF EXISTS documents;',
    'DROP TABLE IF EXISTS patient_photos;',
    'DROP TABLE IF EXISTS audit_logs;',
    'DROP TABLE IF EXISTS email_templates;',
    'DROP TABLE IF EXISTS sale_items;',
    'DROP TABLE IF EXISTS sales;',
    'DROP TABLE IF EXISTS appointments;',
    'DROP TABLE IF EXISTS staff_members;',
    'DROP TABLE IF EXISTS products;',
    'DROP TABLE IF EXISTS patients;',
    'DROP TABLE IF EXISTS users;',
    'SET FOREIGN_KEY_CHECKS = 1;'
  ) | & $MysqlPath @mysqlDbArgs
}

# 3) Schema
Write-Host '==> Aplicando schema' -ForegroundColor Cyan
Get-Content -LiteralPath $schemaFile -Raw | & $MysqlPath @mysqlDbArgs

# 4) Seed (opcional)
if ($Seed) {
  Write-Host '==> Insertando datos de prueba (seed)' -ForegroundColor Cyan
  Get-Content -LiteralPath $seedFile -Raw | & $MysqlPath @mysqlDbArgs
}

# 5) backend/.env
if ((Test-Path -LiteralPath $envExample) -and -not (Test-Path -LiteralPath $envFile)) {
  Write-Host '==> Creando backend/.env desde .env.example' -ForegroundColor Cyan
  $content = Get-Content -LiteralPath $envExample -Raw
  $content = $content -replace '(?m)^DB_HOST=.*$', "DB_HOST=$DbHost"
  $content = $content -replace '(?m)^DB_PORT=.*$', "DB_PORT=$DbPort"
  $content = $content -replace '(?m)^DB_NAME=.*$', "DB_NAME=$DbName"
  $content = $content -replace '(?m)^DB_DATABASE=.*$', "DB_DATABASE=$DbName"
  $content = $content -replace '(?m)^DB_USER=.*$', "DB_USER=$DbUser"
  $content = $content -replace '(?m)^DB_USERNAME=.*$', "DB_USERNAME=$DbUser"
  $content = $content -replace '(?m)^DB_PASS=.*$', "DB_PASS=$dbPasswordPlain"
  $content = $content -replace '(?m)^DB_PASSWORD=.*$', "DB_PASSWORD=$dbPasswordPlain"
  $content = $content -replace '(?m)^APP_URL=.*$', "APP_URL=$AppUrl"
  Set-Content -LiteralPath $envFile -Value $content -Encoding UTF8
}

Write-Host '✅ Instalación de BD completada.' -ForegroundColor Green
if ($Seed) {
  Write-Host 'Credenciales:'
  Write-Host '  superadmin@crm.com / superadmin123'
  Write-Host '  admin@crm.com / admin123'
  Write-Host '  doctor@crm.com / doctor123'
  Write-Host '  staff@crm.com / staff123'
  Write-Host '  patient@crm.com / patient123'
}
