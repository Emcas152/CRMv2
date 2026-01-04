# ============================================
# BACKUP SCRIPT - CRM SPA MÉDICO
# Descripción: Backups automáticos cifrados (BD + archivos)
# Autor: Sistema de Seguridad CRM
# Fecha: Enero 2026
# ============================================

param(
    [string]$BackupDir = "C:\backups\crm",
    [string]$MySQLUser = "root",
    [string]$MySQLPassword = $env:MYSQL_ROOT_PASSWORD,
    [string]$MySQLHost = "localhost",
    [string]$Database = "crm_spa_medico",
    [string]$SourceDir = "C:\xampp\htdocs\crm",
    [int]$RetentionDays = 90,
    [switch]$Compress = $true
)

# ============================================
# Configuración
# ============================================
$ErrorActionPreference = "Stop"
$LogFile = Join-Path $BackupDir "backup.log"
$EncryptionKey = $env:BACKUP_ENCRYPTION_KEY
$DateStamp = Get-Date -Format "yyyyMMdd_HHmmss"

# ============================================
# Funciones
# ============================================

function Write-Log {
    param([string]$Message, [string]$Level = "INFO")
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $logMessage = "[$timestamp] [$Level] $Message"
    Write-Host $logMessage
    Add-Content -Path $LogFile -Value $logMessage
}

function Test-MySQLConnection {
    param([string]$Host, [string]$User, [string]$Password)
    
    try {
        $conn = New-Object System.Data.SqlClient.SqlConnection
        Write-Log "Verificando conexión a MySQL..."
        
        # Usar mysqladmin para verificar
        $output = & "C:\xampp\mysql\bin\mysqladmin.exe" -h $Host -u $User -p$Password ping 2>&1
        
        if ($LASTEXITCODE -eq 0) {
            Write-Log "✓ Conexión MySQL OK" "SUCCESS"
            return $true
        } else {
            Write-Log "✗ No se pudo conectar a MySQL" "ERROR"
            return $false
        }
    } catch {
        Write-Log "Error en prueba de conexión: $_" "ERROR"
        return $false
    }
}

function Backup-Database {
    param([string]$OutputFile)
    
    try {
        Write-Log "Iniciando backup de base de datos: $Database..."
        
        $cmd = "C:\xampp\mysql\bin\mysqldump.exe"
        $args = @(
            "-h", $MySQLHost,
            "-u", $MySQLUser,
            "-p$MySQLPassword",
            "--single-transaction",
            "--routines",
            "--triggers",
            "--events",
            $Database
        )
        
        & $cmd @args | Out-File -FilePath $OutputFile -Encoding UTF8
        
        if ($LASTEXITCODE -eq 0) {
            $size = (Get-Item $OutputFile).Length / 1MB
            Write-Log "✓ Backup BD completado: $($size)MB" "SUCCESS"
            return $true
        } else {
            Write-Log "✗ Error en mysqldump" "ERROR"
            return $false
        }
    } catch {
        Write-Log "Error en backup BD: $_" "ERROR"
        return $false
    }
}

function Backup-Files {
    param([string]$OutputFile)
    
    try {
        Write-Log "Iniciando backup de archivos desde: $SourceDir..."
        
        # Carpetas a respaldar
        $DirsToBackup = @(
            "backend/uploads",
            "backend/config",
            "public",
            "src"
        )
        
        $backupItems = @()
        foreach ($dir in $DirsToBackup) {
            $fullPath = Join-Path $SourceDir $dir
            if (Test-Path $fullPath) {
                $backupItems += $fullPath
            }
        }
        
        if ($Compress) {
            # Crear ZIP comprimido
            Add-Type -AssemblyName System.IO.Compression.FileSystem
            
            if (Test-Path $OutputFile) {
                Remove-Item $OutputFile -Force
            }
            
            [System.IO.Compression.ZipFile]::CreateFromDirectory(
                (Split-Path $SourceDir),
                $OutputFile,
                [System.IO.Compression.CompressionLevel]::Optimal,
                $false
            )
        } else {
            # Copiar directorios como está
            foreach ($item in $backupItems) {
                Copy-Item -Path $item -Destination $OutputFile -Recurse -Force
            }
        }
        
        $size = (Get-Item $OutputFile).Length / 1MB
        Write-Log "✓ Backup archivos completado: $($size)MB" "SUCCESS"
        return $true
    } catch {
        Write-Log "Error en backup archivos: $_" "ERROR"
        return $false
    }
}

function Encrypt-File {
    param([string]$InputFile, [string]$OutputFile)
    
    try {
        Write-Log "Cifrando archivo: $(Split-Path $InputFile -Leaf)..."
        
        if (-not $EncryptionKey) {
            Write-Log "⚠ Variable BACKUP_ENCRYPTION_KEY no configurada. Saltando cifrado." "WARNING"
            Copy-Item -Path $InputFile -Destination $OutputFile -Force
            return $true
        }
        
        # Usar DPAPI (Data Protection API) de Windows
        # Es más simple que OpenSSL en PowerShell
        $plaintext = Get-Content -Path $InputFile -AsByteStream
        
        # Generar salt aleatorio (16 bytes)
        $salt = New-Object byte[] 16
        [System.Security.Cryptography.RNGCryptoServiceProvider]::new().GetBytes($salt)
        
        # Derivar clave usando PBKDF2
        $pbkdf2 = New-Object System.Security.Cryptography.Rfc2898DeriveBytes(
            $EncryptionKey,
            $salt,
            10000,
            [System.Security.Cryptography.HashAlgorithmName]::SHA256
        )
        $key = $pbkdf2.GetBytes(32) # 256 bits
        $iv = $pbkdf2.GetBytes(16)  # 128 bits
        
        # Cifrar con AES-256-CBC
        $aes = New-Object System.Security.Cryptography.Aes
        $aes.Key = $key
        $aes.IV = $iv
        
        $encryptor = $aes.CreateEncryptor()
        $ciphertext = $encryptor.TransformFinalBlock($plaintext, 0, $plaintext.Length)
        
        # Escribir: salt (16) + IV (16) + ciphertext
        $output = New-Object System.IO.FileStream($OutputFile, [System.IO.FileMode]::Create)
        $output.Write($salt, 0, $salt.Length)
        $output.Write($aes.IV, 0, $aes.IV.Length)
        $output.Write($ciphertext, 0, $ciphertext.Length)
        $output.Close()
        
        Remove-Item $InputFile -Force
        Write-Log "✓ Archivo cifrado correctamente" "SUCCESS"
        return $true
    } catch {
        Write-Log "Error en cifrado: $_" "ERROR"
        return $false
    }
}

function Clean-OldBackups {
    param([int]$Days)
    
    try {
        Write-Log "Limpiando backups más antiguos que $Days días..."
        
        $cutoffDate = (Get-Date).AddDays(-$Days)
        $oldFiles = Get-ChildItem -Path $BackupDir -File | 
                    Where-Object { $_.LastWriteTime -lt $cutoffDate }
        
        if ($oldFiles.Count -gt 0) {
            foreach ($file in $oldFiles) {
                Remove-Item -Path $file.FullName -Force
                Write-Log "Eliminado: $($file.Name)" "INFO"
            }
            Write-Log "✓ Limpieza completada: $($oldFiles.Count) archivos eliminados" "SUCCESS"
        } else {
            Write-Log "No hay backups para limpiar" "INFO"
        }
    } catch {
        Write-Log "Error en limpieza: $_" "ERROR"
    }
}

function Send-BackupNotification {
    param([string]$Status, [string]$Message)
    
    # Opcional: Enviar email o webhook
    # Por ahora solo registrar en log
    Write-Log "Notificación [$Status]: $Message" "NOTIFY"
}

# ============================================
# EJECUCIÓN PRINCIPAL
# ============================================

try {
    # Crear directorio si no existe
    if (-not (Test-Path $BackupDir)) {
        New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null
        Write-Log "Directorio de backup creado: $BackupDir"
    }
    
    Write-Log "========== INICIO BACKUP ==========" "INFO"
    Write-Log "Database: $Database | Host: $MySQLHost" "INFO"
    Write-Log "Directorio: $BackupDir" "INFO"
    
    # Validar conexión MySQL
    if (-not (Test-MySQLConnection -Host $MySQLHost -User $MySQLUser -Password $MySQLPassword)) {
        throw "No se puede conectar a MySQL"
    }
    
    $success = $true
    
    # Backup base de datos
    $dbBackupFile = Join-Path $BackupDir "db_$DateStamp.sql"
    if (-not (Backup-Database -OutputFile $dbBackupFile)) {
        $success = $false
    } else {
        # Cifrar BD
        $dbBackupEncrypted = "$dbBackupFile.enc"
        if (-not (Encrypt-File -InputFile $dbBackupFile -OutputFile $dbBackupEncrypted)) {
            $success = $false
        }
    }
    
    # Backup archivos
    $filesBackupFile = Join-Path $BackupDir "files_$DateStamp.zip"
    if (-not (Backup-Files -OutputFile $filesBackupFile)) {
        $success = $false
    } else {
        # Cifrar archivos
        $filesBackupEncrypted = "$filesBackupFile.enc"
        if (-not (Encrypt-File -InputFile $filesBackupFile -OutputFile $filesBackupEncrypted)) {
            $success = $false
        }
    }
    
    # Limpiar backups antiguos
    Clean-OldBackups -Days $RetentionDays
    
    # Resumen
    if ($success) {
        Write-Log "✓ BACKUP COMPLETADO EXITOSAMENTE" "SUCCESS"
        Send-BackupNotification -Status "SUCCESS" -Message "Backup completado sin errores"
        exit 0
    } else {
        Write-Log "✗ BACKUP COMPLETADO CON ERRORES" "ERROR"
        Send-BackupNotification -Status "FAILURE" -Message "Revisa el log para detalles"
        exit 1
    }
    
} catch {
    Write-Log "Error crítico: $_" "FATAL"
    Send-BackupNotification -Status "FATAL" -Message $_
    exit 1
} finally {
    Write-Log "========== FIN BACKUP ==========" "INFO"
}
