param(
  # Example: https://41252429.servicio-online.net/backend
  [string]$BaseUrl = "http://localhost",
  # If you use BaseUrl without :port, set Port to 0.
  [int]$Port = 8000,
  [string]$ApiPrefix = "/api/v1",
  [string]$Email = "admin@crm.com",
  [string]$Password = "admin123"
)

$ErrorActionPreference = 'Stop'

function Join-Url {
  param([string]$A, [string]$B)
  if ([string]::IsNullOrWhiteSpace($A)) { return $B }
  if ([string]::IsNullOrWhiteSpace($B)) { return $A }
  $A2 = $A.TrimEnd('/')
  $B2 = $B.TrimStart('/')
  return "$A2/$B2"
}

function Read-ErrorBody {
  param($ErrorRecord)
  try {
    $resp = $ErrorRecord.Exception.Response
    if (-not $resp) { return $null }
    $stream = $resp.GetResponseStream()
    if (-not $stream) { return $null }
    $reader = New-Object System.IO.StreamReader($stream)
    return $reader.ReadToEnd()
  } catch {
    return $null
  }
}

function Invoke-Api {
  param(
    [string]$Method,
    [string]$Path,
    $Body = $null,
    [hashtable]$Headers = @{}
  )

  $base = $BaseUrl
  if ($Port -and $Port -ne 0 -and ($BaseUrl -notmatch ':\d+(/|$)')) {
    $base = "$BaseUrl`:$Port"
  }

  $uri = Join-Url (Join-Url $base $ApiPrefix) $Path

  $opts = @{ Uri = $uri; Method = $Method; Headers = $Headers; ContentType = 'application/json' }
  if ($null -ne $Body) { $opts.Body = ($Body | ConvertTo-Json -Depth 20) }

  try {
    $json = Invoke-RestMethod @opts
    return [pscustomobject]@{ ok = $true; status = 200; uri = $uri; json = $json; raw = $null }
  } catch {
    $status = $null
    try { $status = [int]$_.Exception.Response.StatusCode } catch { }
    $raw = Read-ErrorBody $_
    $parsed = $null
    if ($raw) {
      try { $parsed = $raw | ConvertFrom-Json } catch { }
    }
    return [pscustomobject]@{ ok = $false; status = $status; uri = $uri; json = $parsed; raw = $raw }
  }
}

function Assert-Ok {
  param($Result, [string]$Name)
  if ($Result.ok) {
    Write-Host "OK: $Name" -ForegroundColor Green
    return
  }
  Write-Host "FAIL: $Name" -ForegroundColor Red
  Write-Host "  URL: $($Result.uri)" -ForegroundColor DarkGray
  if ($Result.status) { Write-Host "  Status: $($Result.status)" -ForegroundColor DarkGray }
  if ($Result.json) {
    Write-Host ("  Body: " + ($Result.json | ConvertTo-Json -Depth 10)) -ForegroundColor DarkGray
  } elseif ($Result.raw) {
    $snippet = $Result.raw
    if ($snippet.Length -gt 800) { $snippet = $snippet.Substring(0, 800) + '...' }
    Write-Host ("  Body: " + $snippet) -ForegroundColor DarkGray
  }
  throw "Smoke test failed at: $Name"
}

Write-Host "== API Smoke Test ==" -ForegroundColor Cyan
Write-Host "BaseUrl: $BaseUrl" -ForegroundColor DarkGray
Write-Host "Port: $Port" -ForegroundColor DarkGray
Write-Host "ApiPrefix: $ApiPrefix" -ForegroundColor DarkGray
Write-Host "Email: $Email" -ForegroundColor DarkGray
Write-Host "" 

$login = Invoke-Api -Method POST -Path "/auth/login" -Body @{ email = $Email; password = $Password }
Assert-Ok $login "POST /auth/login"

if ($login.json.requires_2fa -eq $true) {
  Write-Host "Login requires 2FA. Smoke test stops here." -ForegroundColor Yellow
  Write-Host ("Method: " + $login.json.method) -ForegroundColor Yellow
  Write-Host "Next: call POST /auth/verify-2fa with code + temp_token" -ForegroundColor Yellow
  exit 0
}

$token = $login.json.token
if (-not $token) { throw "No token returned from login" }
$h = @{ Authorization = "Bearer $token" }

Assert-Ok (Invoke-Api -Method GET -Path "/auth/me" -Headers $h) "GET /auth/me"
Assert-Ok (Invoke-Api -Method GET -Path "/dashboard/stats" -Headers $h) "GET /dashboard/stats"
Assert-Ok (Invoke-Api -Method GET -Path "/2fa/status" -Headers $h) "GET /2fa/status"
Assert-Ok (Invoke-Api -Method GET -Path "/2fa/methods" -Headers $h) "GET /2fa/methods"

# Core lists (role-dependent)
Assert-Ok (Invoke-Api -Method GET -Path "/patients" -Headers $h) "GET /patients"
Assert-Ok (Invoke-Api -Method GET -Path "/products" -Headers $h) "GET /products"

# Users list is admin/superadmin only; show warning instead of failing.
$users = Invoke-Api -Method GET -Path "/users" -Headers $h
if ($users.ok) {
  Write-Host "OK: GET /users" -ForegroundColor Green
} else {
  Write-Host "WARN: GET /users not accessible (role?)" -ForegroundColor Yellow
}

Write-Host "" 
Write-Host "Smoke test completed." -ForegroundColor Green
