# PHASE 1 - ARCHITECTURE OVERVIEW

## System Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                        CRM APPLICATION                          │
└─────────────────────────────────────────────────────────────────┘
                              │
                  ┌───────────┴───────────┐
                  │                       │
        ┌─────────▼──────────┐   ┌───────▼──────────┐
        │  BACKUP SYSTEM     │   │  EXPORT CONTROL  │
        │  (Phase 1-A)       │   │  (Phase 1-B)     │
        └────────┬───────────┘   └───────┬──────────┘
                 │                       │
      ┌──────────▼──────────┐   ┌────────▼─────────┐
      │   backup.ps1        │   │ ReportController │
      │  (Automated daily)  │   │   (API endpoints)│
      └──────────┬──────────┘   └────────┬─────────┘
                 │                       │
      ┌──────────▼──────────────────────▼────────┐
      │   Windows Task Scheduler               │
      │   - Daily 2:00 AM execution             │
      │   - Logging to backup.log              │
      │   - Email notifications (optional)     │
      └──────────┬──────────┬───────────────────┘
                 │          │
      ┌──────────▼──┐  ┌────▼──────────┐
      │  Database   │  │  JWT Request  │
      │  Backup     │  │  (with token) │
      │  (SQL +     │  │               │
      │   Zipped)   │  └────┬──────────┘
      └──────────┬──┘       │
                 │          │
      ┌──────────▼──────────▼──────────┐
      │  AES-256-CBC Encryption        │
      │  (PBKDF2 + Random Salt)        │
      └──────────┬────────────────────┘
                 │
      ┌──────────▼──────────────────────┐
      │  C:\backups\crm\               │
      │  ├── 2024-01-15_database.enc   │
      │  ├── 2024-01-15_files.enc      │
      │  ├── 2024-01-15.log            │
      │  └── manifest.json             │
      └───────────────────────────────┘

      ┌──────────────────────────────────────────┐
      │  ReportController Endpoints              │
      ├──────────────────────────────────────────┤
      │  GET /api/v1/reports/patients            │
      │  GET /api/v1/reports/sales               │
      │  GET /api/v1/reports/appointments        │
      │  GET /api/v1/reports/products            │
      │  ?format=pdf|csv                         │
      └──────────┬──────────────────────────────┘
                 │
      ┌──────────▼──────────────────────┐
      │  Authorization Check (RBAC)    │
      │  ✓ superadmin, admin, doctor   │
      │  ✓ staff                       │
      │  ✗ patient (403 Forbidden)     │
      └──────────┬────────────────────┘
                 │
      ┌──────────▼──────────────────────┐
      │  Audit Logging                 │
      │  - User ID                     │
      │  - Format (pdf/csv)            │
      │  - Email                       │
      │  - Role                        │
      │  - Timestamp                   │
      └──────────┬────────────────────┘
                 │
      ┌──────────▼──────────────────────┐
      │  Database Query                │
      │  (patients/sales/appointments/ │
      │   products with filtering)     │
      └──────────┬────────────────────┘
                 │
      ┌──────────▼──────────────────────┐
      │  Format Generation             │
      │  - HTML with watermark (PDF)   │
      │  - CSV with BOM (Excel)        │
      └──────────┬────────────────────┘
                 │
      ┌──────────▼──────────────────────┐
      │  Response to Client            │
      │  Content-Type: application/pdf │
      │   or text/csv                  │
      │  Content-Disposition: attach.  │
      └───────────────────────────────┘
```

## Data Flow

### Backup Flow (Daily at 2:00 AM)
```
Windows Task Scheduler
         │
         ▼
backup.ps1 (PowerShell)
         │
    ┌────┴────┐
    │          │
    ▼          ▼
  MySQL      Files
  ├─ Data    ├─ backend/uploads
  ├─ SPs     ├─ src/
  ├─ Events  └─ config/
  └─ Triggers
    │          │
    ▼          ▼
  ZIP      ZIP Archive
  Compress   Compression
    │          │
    └────┬─────┘
         │
         ▼
  Encrypt File-by-File
    ├─ PBKDF2 Key Derivation
    ├─ Random Salt (16 bytes)
    └─ AES-256-CBC
         │
         ▼
  C:\backups\crm\
  ├─ *.sql.zip.enc
  ├─ *.files.zip.enc
  ├─ *.log
  └─ manifest.json
```

### Export Flow (On-Demand)
```
Client Request
   │
   ├─ HTTP GET /api/v1/reports/{action}?format={format}
   │
   ▼
JWT Authentication
   │
   ├─ Extract user_id, role from token
   │
   ▼
Role Validation (RBAC)
   │
   ├─ Check if role in [superadmin, admin, doctor, staff]
   ├─ If patient → 403 Forbidden
   │
   ▼
Query Database
   │
   ├─ SELECT FROM {table} (with filtering if needed)
   ├─ Apply role-specific masking (e.g., hide price from staff)
   │
   ▼
Format Data
   ├─ PDF: HTML table + watermark + metadata header
   ├─ CSV: UTF-8 BOM + metadata rows + headers + data
   │
   ▼
Log Audit Trail
   │
   ├─ INSERT INTO audit_logs
   │   (user_id, action='EXPORT_DATA', resource_type={action},
   │    meta={'format': 'pdf', 'exported_by': user_name, ...})
   │
   ▼
Return Response
   │
   ├─ Content-Type: application/pdf or text/csv
   ├─ Content-Disposition: attachment; filename="..."
   ├─ Body: PDF/CSV data
```

## Security Layers

```
LAYER 1: Authentication
├─ JWT Bearer Token required
├─ Extracted from Authorization header
└─ Validated in Auth::requireAuth()

LAYER 2: Authorization (RBAC)
├─ Role checked against whitelist
├─ superadmin/admin/doctor/staff ✓
├─ patient, other roles ✗
└─ Returns 403 if unauthorized

LAYER 3: Auditing
├─ Every export logged
├─ User ID captured
├─ Format recorded
├─ Email logged
└─ Timestamp recorded

LAYER 4: Data Masking
├─ Sensitive fields hidden per role
├─ Price column hidden from staff (products)
└─ Extendable per endpoint

LAYER 5: Watermarking
├─ PDF gets "DOCUMENTO CONFIDENCIAL - {USER}"
├─ CSV gets metadata header with user info
└─ Identifies document source

LAYER 6: Encryption (Backups)
├─ AES-256-CBC per file
├─ PBKDF2 key derivation (60k iterations)
├─ Random salt per file
└─ Secure deletion after 90 days
```

## Component Relationships

```
┌────────────────────────────────────────┐
│        backend/routes/api.php          │
│  (Router - dispatches requests)        │
└────────────┬─────────────────────────┘
             │
      ┌──────┴───────────┐
      │                  │
      ▼                  ▼
ReportController    Other Controllers
      │
      ├─ handle($action, $format)
      ├─ exportPatients($user, $format)
      ├─ exportSales($user, $format)
      ├─ exportAppointments($user, $format)
      ├─ exportProducts($user, $format)
      ├─ generatePDFReport($title, $data, $user)
      └─ generateCSVReport($title, $data, $user)
             │
      ┌──────┴──────┬──────────┬──────────┐
      │             │          │          │
      ▼             ▼          ▼          ▼
    Auth          Database   Audit      Response
  (validation)   (queries)  (logging)   (HTTP)


┌────────────────────────────────────────┐
│    tools/ (Backup & Maintenance)       │
└────────────┬─────────────────────────┘
             │
      ┌──────┴──────────┐
      │                 │
      ▼                 ▼
 backup.ps1      setup-backup-scheduler.ps1
      │                 │
      ├─ mysqldump      └─ Creates Windows Task
      ├─ ZIP files         Configures encryption
      ├─ Encrypt           Validates setup
      ├─ Rotate            Tests scheduler
      └─ Log results
             │
             ▼
      C:\backups\crm\
   (Encrypted storage)
```

## API Endpoint Details

### ReportController::handle()
```php
Route Pattern: /api/v1/reports/{action}/{format}
                           │         │
                           │         └─ pdf, csv
                           └─ patients, sales, appointments, products

Request: GET /api/v1/reports/patients?format=pdf
         ↓
Response Headers:
  Authorization: Bearer eyJ...
  
Response (PDF):
  Content-Type: application/pdf
  Content-Disposition: attachment; filename="patients-2024-01-15.pdf"
  Body: [PDF HTML with watermark]

Response (CSV):
  Content-Type: text/csv
  Content-Disposition: attachment; filename="patients-2024-01-15.csv"
  Body: [CSV with BOM and metadata]

Response (Unauthorized):
  HTTP 403
  Body: {"error": "No tienes permiso para exportar reportes"}

Response (Not Found):
  HTTP 404
  Body: {"error": "Endpoint no encontrado"}
```

## Backup Storage Format

```
File Structure on Disk:
C:\backups\crm\
├── 2024-01-15_crm-database.sql.zip.enc
│   └── [Binary encrypted data]
│       ├─ Salt (16 bytes)
│       └─ AES-256-CBC encrypted (mysqldump output + zip)
│
├── 2024-01-15_crm-files.zip.enc
│   └── [Binary encrypted data]
│       ├─ Salt (16 bytes)
│       └─ AES-256-CBC encrypted (backend/ + src/ + config/)
│
├── 2024-01-15.log
│   └── INFO: Backup started at 2024-01-15 02:00:00
│       INFO: Database dumped (125 MB)
│       INFO: Files compressed (340 MB)
│       INFO: Files encrypted
│       SUCCESS: Backup completed in 45 seconds
│       INFO: Cleaned old backups (2 files deleted)
│
└── manifest.json
    └── {
          "date": "2024-01-15",
          "time": "02:15:30",
          "database_size": "125 MB",
          "files_size": "340 MB",
          "compression": true,
          "encryption": "AES-256-CBC",
          "retention_days": 90,
          "status": "success"
        }
```

## Error Handling

```
Backup Errors:
├─ MySQL connection failed
│  └─ Log: ERROR, skip backup, send alert email
├─ Source files not found
│  └─ Log: WARNING, backup partial, notify admin
├─ Encryption failed
│  └─ Log: FATAL, delete partial file, stop execution
└─ Disk space insufficient
   └─ Log: FATAL, cleanup old backups, stop if still full

Export Errors:
├─ Invalid JWT token
│  └─ Response: 401 Unauthorized
├─ User not authenticated
│  └─ Response: 401 Unauthorized
├─ User has no permission (patient role)
│  └─ Response: 403 Forbidden
├─ Invalid format parameter
│  └─ Response: 400 Bad Request
├─ Database query failed
│  └─ Response: 500 Internal Server Error + log error
└─ Unknown action
   └─ Response: 404 Not Found
```

## Configuration Summary

```
BACKUP CONFIGURATION:
├─ Schedule: Daily at 2:00 AM
├─ Database: crm (all tables, stored procedures, triggers, events)
├─ Files: backend/uploads, src/, config/
├─ Retention: 90 days
├─ Encryption: AES-256-CBC with PBKDF2 derivation
├─ Compression: ZIP before encryption
├─ Destination: C:\backups\crm\
├─ Logging: backup.log with detailed info
└─ Notifications: Email (optional)

EXPORT CONFIGURATION:
├─ Authentication: JWT Bearer tokens
├─ Authorization: RBAC (role-based)
├─ Allowed Roles: superadmin, admin, doctor, staff
├─ Denied Roles: patient, any unauthenticated
├─ Auditing: Full audit trail in audit_logs
├─ Watermarking: "DOCUMENTO CONFIDENCIAL - {USERNAME}"
├─ Data Masking: Price hidden from non-admin on products
├─ Formats: PDF (HTML), CSV (UTF-8 BOM)
└─ Endpoints: 4 (patients, sales, appointments, products)
```

## Monitoring & Maintenance

```
BACKUP MONITORING:
├─ Check daily: Get-ChildItem C:\backups\crm\ -Filter *.enc
├─ Verify logs: Get-Content C:\backups\crm\backup.log -Tail 20
├─ Monitor task: Get-ScheduledTask -TaskName CRM-Encrypted-Backup
├─ Test restore: Decrypt and restore from most recent backup (quarterly)
└─ Rotate key: Change BACKUP_ENCRYPTION_KEY every 90 days

EXPORT MONITORING:
├─ View audit trail: SELECT * FROM audit_logs WHERE action='EXPORT_DATA'
├─ Track exports: Filter by user_id, date range
├─ Alert on suspicious: >10 exports per hour from single user
├─ Verify watermarks: All PDFs should have username watermark
└─ Test access control: Verify patient role gets 403 responses
```

---

**Architecture Version:** 1.0  
**Last Updated:** 2024  
**Status:** ✅ IMPLEMENTATION COMPLETE
