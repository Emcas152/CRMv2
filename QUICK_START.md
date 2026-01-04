# PHASE 1 - QUICK REFERENCE

## ⚡ 5-MINUTE SETUP

```powershell
# 1. Run as Administrator
cd "c:\Users\edwin\Downloads\coreui-free-angular-admin-template-main\coreui-free-angular-admin-template-main\tools"

# 2. Execute setup wizard
.\setup-backup-scheduler.ps1

# 3. Answer prompts:
#    - Press Enter to accept defaults
#    - Enter encryption key or let it generate random
#    - Wait for Task Scheduler job to be created
#    - Done!
```

## 📊 WHAT'S RUNNING NOW

### Backups
```
Schedule:  Daily at 2:00 AM
Database:  mysqldump (with procedures, triggers, events)
Files:     ZIP archive of backend/uploads, src, config
Encrypt:   AES-256-CBC with PBKDF2
Location:  C:\backups\crm\
Retention: 90 days auto-cleanup
Logs:      C:\backups\crm\backup.log
```

### Report Exports
```
GET /api/v1/reports/patients?format=pdf|csv
GET /api/v1/reports/sales?format=pdf|csv
GET /api/v1/reports/appointments?format=pdf|csv
GET /api/v1/reports/products?format=pdf|csv

Access:    superadmin, admin, doctor, staff only
Audit:     Every export logged with user details
Watermark: "DOCUMENTO CONFIDENCIAL - USERNAME"
```

## 🧪 QUICK TEST

```bash
# Get admin token
TOKEN="your-jwt-token-here"

# Test reports work
curl -X GET "http://localhost/api/v1/reports/patients?format=csv" \
  -H "Authorization: Bearer $TOKEN"

# Should get CSV output
# If 403: check your role (patient role has no access)
# If 404: check api.php has ReportController routed
```

## 📂 KEY FILES

| File | Purpose | Location |
|------|---------|----------|
| backup.ps1 | Main backup script | tools/ |
| setup-backup-scheduler.ps1 | Configuration wizard | tools/ |
| ReportController.php | Export endpoints | backend/app/Controllers/ |
| api.php | Routes (modified) | backend/routes/ |

## 🔍 VERIFY IT'S WORKING

```powershell
# Check task is scheduled
Get-ScheduledTask -TaskName "CRM-Encrypted-Backup" -TaskPath "\CRM\"

# Check backup ran
Get-ChildItem "C:\backups\crm\" -Filter "*.enc" | Measure-Object

# Check latest log
Get-Content "C:\backups\crm\backup.log" -Tail 10

# Test report endpoint
Invoke-WebRequest -Uri "http://localhost/api/v1/reports/patients?format=csv" `
  -Headers @{"Authorization"="Bearer $TOKEN"}
```

## ⚠️ COMMON ISSUES

| Issue | Solution |
|-------|----------|
| "BACKUP_ENCRYPTION_KEY not found" | Run setup wizard again or set manually |
| "/api/v1/reports returns 404" | Check api.php has ReportController require (line 23) |
| "No tienes permiso" (403) | Check JWT role: must be superadmin/admin/doctor/staff, not patient |
| Backup very large | Edit backup.ps1: set $Compress = $false to skip compression |
| Task never runs | Check BACKUP_ENCRYPTION_KEY is set as Machine variable, not User |

## 📚 FULL DOCS

- `IMPLEMENTATION_LOG.md` - Technical details (600 lines)
- `DEPLOYMENT_GUIDE.md` - Step-by-step deployment (400 lines)
- `PHASE1_COMPLETE.md` - Summary and next steps (300 lines)

## 🚀 NEXT

After Phase 1 works in production, implement Phase 2:
1. **2FA** - Two-factor authentication
2. **Rate Limiting** - Prevent brute force
3. **Login Blocking** - 5 attempts = 15 min lockout

---

**Status:** ✅ READY FOR DEPLOYMENT
