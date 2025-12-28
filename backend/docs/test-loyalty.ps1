param(
  [string]$BaseUrl = "http://127.0.0.1:8000/api/v1",
  [string]$Email = "superadmin@crm.com",
  [string]$Password = "superadmin123",
  [int]$PatientId = 1,
  [int]$AddPointsQr = 10,
  [int]$RedeemPointsQr = 5,
  [int]$AddPointsDirect = 7,
  [int]$RedeemPointsDirect = 3
)

$ErrorActionPreference = 'Stop'

function Invoke-JsonPostCurlStdin {
  param(
    [Parameter(Mandatory=$true)][string]$Url,
    [Parameter(Mandatory=$true)][string]$Json,
    [string]$Token = $null,
    [switch]$IncludeHeaders
  )

  $args = @('-s')
  if ($IncludeHeaders) { $args += '-i' }
  $args += @('-X','POST',$Url,'-H','Content-Type: application/json')
  if ($Token) { $args += @('-H',"Authorization: Bearer $Token") }
  $args += @('--data-binary','@-')

  return ($Json | curl.exe @args)
}

Write-Host "== Loyalty / QR validation ==" -ForegroundColor Cyan
Write-Host "BaseUrl: $BaseUrl"; Write-Host "PatientId: $PatientId"; Write-Host ""

# 1) Login
$loginJson = (@{ email=$Email; password=$Password } | ConvertTo-Json -Compress)
$loginResp = Invoke-JsonPostCurlStdin -Url "$BaseUrl/auth/login" -Json $loginJson
$loginText = ($loginResp | Out-String).Trim()
try {
  $loginObj = ($loginText | ConvertFrom-Json -ErrorAction Stop)
} catch {
  Write-Host "LOGIN RESPONSE IS NOT JSON. Raw response:" -ForegroundColor Red
  Write-Host $loginText
  throw
}
$token = $loginObj.token
if (-not $token) {
  Write-Host "LOGIN FAILED. Response:" -ForegroundColor Red
  Write-Host $loginText
  exit 1
}
Write-Host "Token OK" -ForegroundColor Green

# 2) Baseline points
$before = (curl.exe -s "$BaseUrl/patients/$PatientId" -H "Authorization: Bearer $token" | ConvertFrom-Json).data
$beforePts = 0
if ($null -ne $before -and $null -ne $before.loyalty_points) { $beforePts = [int]$before.loyalty_points }
Write-Host "BEFORE_POINTS=$beforePts" -ForegroundColor Yellow

# 3) Ensure QR exists
$qrResp = (curl.exe -s "$BaseUrl/patients/$PatientId/qr" -H "Authorization: Bearer $token" | ConvertFrom-Json).data
$qr = $qrResp.qr_code
if (-not $qr) {
  Write-Host "No QR code returned from /patients/{id}/qr" -ForegroundColor Red
  exit 1
}
Write-Host "QR_CODE=$qr" -ForegroundColor Yellow

# 4) Add points via QR
$addQrJson = (@{ qr_code=$qr; action='add'; points=$AddPointsQr } | ConvertTo-Json -Compress)
$addQrResp = Invoke-JsonPostCurlStdin -Url "$BaseUrl/qr/scan" -Json $addQrJson -Token $token
Write-Host "ADD_QR_RESP=$addQrResp"
$afterAdd = (curl.exe -s "$BaseUrl/patients/$PatientId" -H "Authorization: Bearer $token" | ConvertFrom-Json).data
$afterAddPts = 0
if ($null -ne $afterAdd -and $null -ne $afterAdd.loyalty_points) { $afterAddPts = [int]$afterAdd.loyalty_points }
Write-Host "AFTER_ADD_POINTS=$afterAddPts" -ForegroundColor Yellow

# 5) Redeem points via QR
$redeemQrJson = (@{ qr_code=$qr; action='redeem'; points=$RedeemPointsQr } | ConvertTo-Json -Compress)
$redeemQrResp = Invoke-JsonPostCurlStdin -Url "$BaseUrl/qr/scan" -Json $redeemQrJson -Token $token
Write-Host "REDEEM_QR_RESP=$redeemQrResp"
$afterRedeem = (curl.exe -s "$BaseUrl/patients/$PatientId" -H "Authorization: Bearer $token" | ConvertFrom-Json).data
$afterRedeemPts = 0
if ($null -ne $afterRedeem -and $null -ne $afterRedeem.loyalty_points) { $afterRedeemPts = [int]$afterRedeem.loyalty_points }
Write-Host "AFTER_REDEEM_POINTS=$afterRedeemPts" -ForegroundColor Yellow

# 6) Redeem too many via QR (should 400)
$tooMany = $afterRedeemPts + 999
$failQrJson = (@{ qr_code=$qr; action='redeem'; points=$tooMany } | ConvertTo-Json -Compress)
$failQrResp = Invoke-JsonPostCurlStdin -Url "$BaseUrl/qr/scan" -Json $failQrJson -Token $token -IncludeHeaders
Write-Host "REDEEM_TOO_MANY_QR_FIRST_LINES:" -ForegroundColor Cyan
($failQrResp -split "`n" | Select-Object -First 12) | ForEach-Object { $_ }

# 7) Direct endpoints (patients/{id}/loyalty-*)
$addDirectJson = (@{ points=$AddPointsDirect } | ConvertTo-Json -Compress)
$addDirectResp = Invoke-JsonPostCurlStdin -Url "$BaseUrl/patients/$PatientId/loyalty-add" -Json $addDirectJson -Token $token
Write-Host "ADD_DIRECT_RESP=$addDirectResp"

$redeemDirectJson = (@{ points=$RedeemPointsDirect } | ConvertTo-Json -Compress)
$redeemDirectResp = Invoke-JsonPostCurlStdin -Url "$BaseUrl/patients/$PatientId/loyalty-redeem" -Json $redeemDirectJson -Token $token
Write-Host "REDEEM_DIRECT_RESP=$redeemDirectResp"

$final = (curl.exe -s "$BaseUrl/patients/$PatientId" -H "Authorization: Bearer $token" | ConvertFrom-Json).data
$finalPts = 0
if ($null -ne $final -and $null -ne $final.loyalty_points) { $finalPts = [int]$final.loyalty_points }
Write-Host "FINAL_POINTS=$finalPts" -ForegroundColor Green

Write-Host "\nOK" -ForegroundColor Green
