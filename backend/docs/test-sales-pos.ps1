param(
  [string]$BaseUrl = "http://localhost",
  [int]$Port = 8000,
  [string]$Email = "admin@crm.com",
  [string]$Password = "admin123"
)

$ErrorActionPreference = 'Stop'

function Invoke-Api {
  param(
    [string]$Method,
    [string]$Path,
    $Body = $null,
    [hashtable]$Headers = @{}
  )

  $uri = "$BaseUrl`:$Port/api/v1$Path"
  $opts = @{ Uri = $uri; Method = $Method; Headers = $Headers; ContentType = 'application/json' }
  if ($null -ne $Body) { $opts.Body = ($Body | ConvertTo-Json -Depth 20) }
  return Invoke-RestMethod @opts
}

function Get-ListData {
  param($Response)
  if (-not $Response) { return @() }
  $d = $Response.data
  if (-not $d) { return @() }
  if ($d.PSObject.Properties.Name -contains 'data') {
    return @($d.data)
  }
  return @($d)
}

function Get-Total {
  param($Response)
  if (-not $Response) { return $null }
  $d = $Response.data
  if (-not $d) { return $null }
  if ($d.PSObject.Properties.Name -contains 'total') { return $d.total }
  return $null
}

Write-Host "Login..." -ForegroundColor Cyan
$login = Invoke-Api -Method POST -Path "/auth/login" -Body @{ email = $Email; password = $Password }
$token = $login.token
if (-not $token) { throw "No token returned from login" }
$h = @{ Authorization = "Bearer $token" }

Write-Host "List products (pick first)..." -ForegroundColor Cyan
$productsResp = Invoke-Api -Method GET -Path "/products" -Headers $h
$firstProduct = (Get-ListData $productsResp)[0]
if (-not $firstProduct) { throw "No products found" }

Write-Host "List patients (pick first)..." -ForegroundColor Cyan
$patientsResp = Invoke-Api -Method GET -Path "/patients" -Headers $h
$firstPatient = (Get-ListData $patientsResp)[0]
if (-not $firstPatient) { throw "No patients found" }

$patientId = [int]$firstPatient.id

Write-Host "Create sale with loyalty_points=5..." -ForegroundColor Cyan
$saleResp = Invoke-Api -Method POST -Path "/sales" -Headers $h -Body @{ 
  patient_id = $patientId
  payment_method = 'cash'
  discount = 0
  notes = 'POS smoke test'
  loyalty_points = 5
  items = @(
    @{ product_id = [int]$firstProduct.id; price = [double]$firstProduct.price; quantity = 1 }
  )
}

$sale = $saleResp.data

if (-not $sale.id) { throw "Sale create did not return id" }
Write-Host ("Created sale #" + $sale.id + "; awarded=" + $sale.loyalty_points_awarded) -ForegroundColor Green

Write-Host "Fetch sale..." -ForegroundColor Cyan
$sale2 = (Invoke-Api -Method GET -Path ("/sales/" + $sale.id) -Headers $h).data
Write-Host ("Sale total=" + $sale2.total + "; items=" + @($sale2.items).Count) -ForegroundColor Green

Write-Host "List sales page=1 per_page=5..." -ForegroundColor Cyan
$list = Invoke-Api -Method GET -Path ("/sales?page=1&per_page=5") -Headers $h
$rows = Get-ListData $list
$total = Get-Total $list
Write-Host ("List returned " + @($rows).Count + " rows; total=" + $total) -ForegroundColor Green

Write-Host "OK" -ForegroundColor Green
