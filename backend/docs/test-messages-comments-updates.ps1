Param(
  [string]$BaseUrl = 'http://127.0.0.1:8000/api/v1'
)

$ErrorActionPreference = 'Stop'

function Write-Section([string]$title) {
  Write-Host "\n=== $title ===" -ForegroundColor Cyan
}

function Assert-True([bool]$condition, [string]$message) {
  if (-not $condition) { throw "ASSERT FAILED: $message" }
}

function Invoke-Api(
  [string]$Method,
  [string]$Path,
  [string]$Token = $null,
  $Body = $null,
  [hashtable]$Query = $null
) {
  $uri = "$BaseUrl$Path"
  if ($Query -and $Query.Count -gt 0) {
    $qs = ($Query.GetEnumerator() | ForEach-Object {
      $k = [System.Uri]::EscapeDataString([string]$_.Key)
      $v = [System.Uri]::EscapeDataString([string]$_.Value)
      "$k=$v"
    }) -join '&'
    $uri = "$uri?$qs"
  }

  $headers = @{}
  if ($Token) { $headers['Authorization'] = "Bearer $Token" }

  $params = @{
    Method      = $Method
    Uri         = $uri
    Headers     = $headers
    ContentType = 'application/json'
  }

  if ($null -ne $Body) {
    $params['Body'] = ($Body | ConvertTo-Json -Depth 20)
  }

  try {
    return Invoke-RestMethod @params
  } catch {
    $resp = $_.Exception.Response
    if ($resp -and $resp.GetResponseStream()) {
      $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
      $text = $reader.ReadToEnd()
      throw "HTTP ERROR calling $Method $uri`n$text"
    }
    throw
  }
}

function Login([string]$email, [string]$password) {
  $res = Invoke-Api -Method 'POST' -Path '/auth/login' -Body @{ email = $email; password = $password }
  Assert-True ($null -ne $res.token) "Login did not return token for $email"
  return $res.token
}

Write-Section "Config"
Write-Host "BaseUrl: $BaseUrl"

Write-Section "Auth"
$doctorToken = Login 'doctor@crm.com' 'doctor123'
$adminToken  = Login 'admin@crm.com' 'admin123'
$patientToken  = Login 'patient@crm.com' 'patient123'
$staffToken  = Login 'staff@crm.com' 'staff123'
Write-Host 'PASS: Logged in as doctor, patient, staff, admin' -ForegroundColor Green

Write-Section "Updates (actualizaciones)"
$updates = Invoke-Api -Method 'GET' -Path '/updates' -Token $adminToken
Assert-True ($updates.success -eq $true) 'GET /updates did not return success=true'
Assert-True ($updates.data -and $updates.data.total -ge 1) 'Expected at least 1 update'
Write-Host "PASS: Listed updates (total=$($updates.data.total))" -ForegroundColor Green

$createdUpdate = Invoke-Api -Method 'POST' -Path '/updates' -Token $adminToken -Body @{
  title = 'Smoke test update'
  body  = 'Update created by automated smoke test'
  audience_type = 'role'
  audience_role = 'doctor'
}
Assert-True ($createdUpdate.success -eq $true) 'POST /updates failed'
$updateId = $createdUpdate.data.id
Assert-True ($updateId -gt 0) 'POST /updates did not return id'
Write-Host "PASS: Created update id=$updateId" -ForegroundColor Green

$deletedUpdate = Invoke-Api -Method 'DELETE' -Path "/updates/$updateId" -Token $adminToken
Assert-True ($deletedUpdate.success -eq $true) 'DELETE /updates failed'
Write-Host "PASS: Deleted update id=$updateId" -ForegroundColor Green

Write-Section "Conversations + messages (mensajes)"
$convs = Invoke-Api -Method 'GET' -Path '/conversations' -Token $doctorToken
Assert-True ($convs.success -eq $true) 'GET /conversations failed'
Write-Host "PASS: Listed conversations (total=$($convs.data.total))" -ForegroundColor Green

$newConv = Invoke-Api -Method 'POST' -Path '/conversations' -Token $doctorToken -Body @{
  subject = 'Smoke test conversation'
  participant_user_ids = @(5) # patient@crm.com (seed)
  first_message = 'Hola (smoke test)'
}
Assert-True ($newConv.success -eq $true) 'POST /conversations failed'
$convId = $newConv.data.id
Assert-True ($convId -gt 0) 'POST /conversations did not return id'
Write-Host "PASS: Created conversation id=$convId" -ForegroundColor Green

$msgs1 = Invoke-Api -Method 'GET' -Path "/conversations/$convId/messages" -Token $doctorToken
Assert-True ($msgs1.success -eq $true) 'GET /conversations/{id}/messages failed'
Assert-True ($msgs1.data.total -ge 1) 'Expected at least 1 message in new conversation'
Write-Host "PASS: Listed messages (total=$($msgs1.data.total))" -ForegroundColor Green

$sent = Invoke-Api -Method 'POST' -Path "/conversations/$convId/messages" -Token $doctorToken -Body @{ body = 'Segundo mensaje (smoke test)' }
Assert-True ($sent.success -eq $true) 'POST /conversations/{id}/messages failed'
Write-Host "PASS: Sent message id=$($sent.data.id)" -ForegroundColor Green

# Patient should see unread_count >= 1 for this conversation
$convsPatient = Invoke-Api -Method 'GET' -Path '/conversations' -Token $patientToken
Assert-True ($convsPatient.success -eq $true) 'GET /conversations (patient) failed'
$convRow = @($convsPatient.data.data | Where-Object { $_.id -eq $convId })[0]
Assert-True ($null -ne $convRow) 'Patient did not see the new conversation'
Assert-True ([int]$convRow.unread_count -ge 1) 'Expected unread_count >= 1 for patient'
Write-Host "PASS: Patient sees conversation with unread_count=$($convRow.unread_count)" -ForegroundColor Green

$sentPatient = Invoke-Api -Method 'POST' -Path "/conversations/$convId/messages" -Token $patientToken -Body @{ body = 'Mensaje del paciente (smoke test)' }
Assert-True ($sentPatient.success -eq $true) 'POST /conversations/{id}/messages (patient) failed'
Write-Host "PASS: Patient sent message id=$($sentPatient.data.id)" -ForegroundColor Green

$mark = Invoke-Api -Method 'POST' -Path "/conversations/$convId/read" -Token $doctorToken
Assert-True ($mark.success -eq $true) 'POST /conversations/{id}/read failed'
Write-Host 'PASS: Doctor marked conversation as read' -ForegroundColor Green

try {
  Invoke-Api -Method 'GET' -Path '/conversations' -Token $staffToken | Out-Null
  throw 'Expected staff to be forbidden from conversations'
} catch {
  Write-Host 'PASS: Staff cannot access conversations' -ForegroundColor Green
}

Write-Section "Comments (comentarios)"
# Seed has task id=1 and comments for it
$listComments = Invoke-Api -Method 'GET' -Path '/comments?entity_type=task&entity_id=1' -Token $adminToken
Assert-True ($listComments.success -eq $true) 'GET /comments failed'
Assert-True ($listComments.data.total -ge 1) 'Expected at least 1 seed comment for task=1'
Write-Host "PASS: Listed comments for task=1 (total=$($listComments.data.total))" -ForegroundColor Green

$newComment = Invoke-Api -Method 'POST' -Path '/comments' -Token $adminToken -Body @{ entity_type = 'task'; entity_id = 1; body = 'Comentario (smoke test)' }
Assert-True ($newComment.success -eq $true) 'POST /comments failed'
$commentId = $newComment.data.id
Assert-True ($commentId -gt 0) 'POST /comments did not return id'
Write-Host "PASS: Created comment id=$commentId" -ForegroundColor Green

$updComment = Invoke-Api -Method 'PUT' -Path "/comments/$commentId" -Token $adminToken -Body @{ body = 'Comentario editado (smoke test)' }
Assert-True ($updComment.success -eq $true) 'PUT /comments/{id} failed'
Write-Host "PASS: Updated comment id=$commentId" -ForegroundColor Green

$delComment = Invoke-Api -Method 'DELETE' -Path "/comments/$commentId" -Token $adminToken
Assert-True ($delComment.success -eq $true) 'DELETE /comments/{id} failed'
Write-Host "PASS: Deleted comment id=$commentId" -ForegroundColor Green

Write-Section "Done"
Write-Host 'ALL SMOKE TESTS PASSED' -ForegroundColor Green
