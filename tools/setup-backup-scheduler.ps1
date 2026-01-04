# ============================================================================
# Backup Scheduler Setup for CRM Database
# ============================================================================
# This script configures Windows Task Scheduler to run automated encrypted
# backups of the CRM database and critical files daily.
#
# PREREQUISITES:
#   1. PowerShell 5.0+ (Windows 10/Server 2016+)
#   2. Admin rights required to create scheduled tasks
#   3. MySQL/MariaDB installed with mysqldump available
#   4. BACKUP_ENCRYPTION_KEY environment variable set in System Properties
#
# USAGE:
#   Run as Administrator: .\setup-backup-scheduler.ps1
# ============================================================================

param(
    [string]$BackupScript = "$PSScriptRoot\backup.ps1",
    [string]$TaskName = "CRM-Encrypted-Backup",
    [string]$TaskFolder = "\CRM\",
    [string]$Schedule = "Daily",
    [int]$Hour = 2,
    [int]$Minute = 0,
    [string]$EncryptionKey = $null,
    [switch]$Remove = $false
)

# ============================================================================
# FUNCTIONS
# ============================================================================

function Test-Administrator {
    $currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object System.Security.Principal.WindowsPrincipal($currentUser)
    return $principal.IsInRole([System.Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Confirm-BackupScript {
    if (-not (Test-Path $BackupScript -PathType Leaf)) {
        Write-Host "❌ ERROR: Backup script not found at: $BackupScript" -ForegroundColor Red
        exit 1
    }
    Write-Host "✓ Backup script found: $BackupScript" -ForegroundColor Green
}

function Confirm-EncryptionKey {
    $envKey = [System.Environment]::GetEnvironmentVariable('BACKUP_ENCRYPTION_KEY', 'Machine')
    
    if (-not $envKey) {
        Write-Host ""
        Write-Host "⚠️  BACKUP_ENCRYPTION_KEY not found in System Environment Variables" -ForegroundColor Yellow
        Write-Host ""
        Write-Host "Would you like to set it now? (Required for encrypted backups)" -ForegroundColor Cyan
        $response = Read-Host "Enter encryption key (leave blank to skip) or generate random"
        
        if ($response -eq "") {
            # Generate a random 32-character key
            $key = -join ((65..90) + (97..122) + (48..57) | Get-Random -Count 32 | % {[char]$_})
            Write-Host "Generated key: $key" -ForegroundColor Cyan
            $response = $key
        }
        
        if ($response) {
            [System.Environment]::SetEnvironmentVariable('BACKUP_ENCRYPTION_KEY', $response, 'Machine')
            Write-Host "✓ BACKUP_ENCRYPTION_KEY set in System Environment Variables" -ForegroundColor Green
        }
    } else {
        Write-Host "✓ BACKUP_ENCRYPTION_KEY already configured" -ForegroundColor Green
    }
}

function Create-ScheduledTask {
    param(
        [string]$TaskName,
        [string]$TaskFolder,
        [string]$ScriptPath,
        [string]$Schedule,
        [int]$Hour,
        [int]$Minute
    )
    
    # Remove existing task if it exists
    try {
        Get-ScheduledTask -TaskName $TaskName -TaskPath $TaskFolder -ErrorAction Stop | Out-Null
        Write-Host "⚠️  Task already exists. Removing old task..."
        Unregister-ScheduledTask -TaskName $TaskName -TaskPath $TaskFolder -Confirm:$false
    } catch {
        # Task doesn't exist, that's fine
    }
    
    # Create task trigger
    $trigger = New-ScheduledTaskTrigger `
        -At "$($Hour.ToString('00')):$($Minute.ToString('00'))" `
        -$Schedule `
        -ErrorAction Stop
    
    # Create action
    $action = New-ScheduledTaskAction `
        -Execute "powershell.exe" `
        -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$ScriptPath`"" `
        -ErrorAction Stop
    
    # Create settings
    $settings = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries:$false `
        -DontStopIfGoingOnBatteries:$false `
        -Compatibility Win8 `
        -StartWhenAvailable:$true `
        -ErrorAction Stop
    
    # Create principal (run as SYSTEM for reliability)
    $principal = New-ScheduledTaskPrincipal `
        -UserId "NT AUTHORITY\SYSTEM" `
        -LogonType ServiceAccount `
        -RunLevel Highest
    
    # Create the task
    Register-ScheduledTask `
        -TaskName $TaskName `
        -TaskPath $TaskFolder `
        -Trigger $trigger `
        -Action $action `
        -Settings $settings `
        -Principal $principal `
        -Description "Automated encrypted backup of CRM database and critical files" `
        -Force `
        -ErrorAction Stop
    
    Write-Host "✓ Scheduled task created: $TaskFolder$TaskName" -ForegroundColor Green
    Write-Host "  Schedule: $Schedule at $($Hour.ToString('00')):$($Minute.ToString('00'))" -ForegroundColor Gray
}

function Remove-ScheduledTask {
    param(
        [string]$TaskName,
        [string]$TaskFolder
    )
    
    try {
        Get-ScheduledTask -TaskName $TaskName -TaskPath $TaskFolder -ErrorAction Stop | Out-Null
        Unregister-ScheduledTask -TaskName $TaskName -TaskPath $TaskFolder -Confirm:$false -ErrorAction Stop
        Write-Host "✓ Scheduled task removed: $TaskFolder$TaskName" -ForegroundColor Green
    } catch {
        Write-Host "⚠️  Task not found: $TaskFolder$TaskName" -ForegroundColor Yellow
    }
}

function Test-ScheduledTask {
    param(
        [string]$TaskName,
        [string]$TaskFolder
    )
    
    try {
        $task = Get-ScheduledTask -TaskName $TaskName -TaskPath $TaskFolder -ErrorAction Stop
        Write-Host ""
        Write-Host "Scheduled Task Details:" -ForegroundColor Cyan
        Write-Host "  Name: $($task.TaskName)" -ForegroundColor Gray
        Write-Host "  Path: $($task.TaskPath)" -ForegroundColor Gray
        Write-Host "  State: $($task.State)" -ForegroundColor Gray
        Write-Host "  Enabled: $($task.Enabled)" -ForegroundColor Gray
        Write-Host "  Last Result: $($task.LastTaskResult)" -ForegroundColor Gray
        
        if ($task.Triggers) {
            Write-Host "  Next Run: $($task.Triggers[0].StartBoundary)" -ForegroundColor Gray
        }
        
        return $true
    } catch {
        return $false
    }
}

# ============================================================================
# MAIN SCRIPT
# ============================================================================

Write-Host ""
Write-Host "╔═══════════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║        CRM Backup Scheduler Setup (Windows Task Scheduler)     ║" -ForegroundColor Cyan
Write-Host "╚═══════════════════════════════════════════════════════════════╝" -ForegroundColor Cyan
Write-Host ""

# Check admin rights
if (-not (Test-Administrator)) {
    Write-Host "❌ ERROR: This script must be run as Administrator" -ForegroundColor Red
    Write-Host ""
    Write-Host "To run as Administrator:" -ForegroundColor Yellow
    Write-Host "  1. Right-click PowerShell"
    Write-Host "  2. Select 'Run as administrator'"
    Write-Host "  3. Run: .\setup-backup-scheduler.ps1"
    Write-Host ""
    exit 1
}

Write-Host "✓ Running with Administrator privileges" -ForegroundColor Green
Write-Host ""

# Normalize script path
$BackupScript = Resolve-Path $BackupScript

# Remove task if requested
if ($Remove) {
    Write-Host "Removing scheduled task..."
    Remove-ScheduledTask -TaskName $TaskName -TaskFolder $TaskFolder
    exit 0
}

# Validate backup script
Confirm-BackupScript

# Setup encryption key
Write-Host ""
Confirm-EncryptionKey

# Create scheduled task
Write-Host ""
Write-Host "Creating scheduled task..."
try {
    Create-ScheduledTask `
        -TaskName $TaskName `
        -TaskFolder $TaskFolder `
        -ScriptPath $BackupScript `
        -Schedule $Schedule `
        -Hour $Hour `
        -Minute $Minute
} catch {
    Write-Host "❌ ERROR: Failed to create scheduled task" -ForegroundColor Red
    Write-Host "  Details: $_" -ForegroundColor Red
    exit 1
}

# Test the task
Write-Host ""
Test-ScheduledTask -TaskName $TaskName -TaskFolder $TaskFolder

Write-Host ""
Write-Host "╔═══════════════════════════════════════════════════════════════╗" -ForegroundColor Green
Write-Host "║                    Setup Complete! ✓                         ║" -ForegroundColor Green
Write-Host "╚═══════════════════════════════════════════════════════════════╝" -ForegroundColor Green
Write-Host ""

Write-Host "Summary:" -ForegroundColor Cyan
Write-Host "  • Backup script: $BackupScript" -ForegroundColor Gray
Write-Host "  • Scheduled task: $TaskFolder$TaskName" -ForegroundColor Gray
Write-Host "  • Schedule: $Schedule at $($Hour.ToString('00')):$($Minute.ToString('00'))" -ForegroundColor Gray
Write-Host ""

Write-Host "To test the backup manually:" -ForegroundColor Yellow
Write-Host "  powershell -NoProfile -ExecutionPolicy Bypass -File ""$BackupScript""" -ForegroundColor Gray
Write-Host ""

Write-Host "To view scheduled tasks:" -ForegroundColor Yellow
Write-Host "  Get-ScheduledTask -TaskPath '\CRM\'" -ForegroundColor Gray
Write-Host ""

Write-Host "To remove the scheduled task:" -ForegroundColor Yellow
Write-Host "  .\setup-backup-scheduler.ps1 -Remove" -ForegroundColor Gray
Write-Host ""
