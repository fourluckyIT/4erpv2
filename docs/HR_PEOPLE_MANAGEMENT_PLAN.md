# HR & People Management System - Analysis & Plan
**ERP v2 - Phase HR/People**

## 1. Current State Analysis

### 1.1 Existing Tables (from schema_m11_compliance.sql)
- ✅ `people` - Basic people records
- ✅ `people_certificates` - Certificate tracking
- ✅ `compliance_requirements` - Site-specific requirements
- ✅ `compliance_checks` - Pre-dispatch compliance verification
- ✅ `serial_certificates` - Equipment/device certificates

### 1.2 Existing Modules
- ✅ `modules/procurement/pr/manpower.php` - PR for manpower requests
- ✅ `modules/timesheet/` - Timesheet management
- ⚠️ Missing: People Master CRUD
- ⚠️ Missing: Certificate management UI
- ⚠️ Missing: Social security tracking
- ⚠️ Missing: Salary/payroll tracking
- ⚠️ Missing: Training records

---

## 2. User Requirements

### 2.1 PR Manpower Use Cases
1. **Job-specific**: Request manpower for a specific job (existing)
2. **General HR**: Hire people for company operations (NEW - now supported with optional Job)

### 2.2 People Management Needs
- Hire/onboard employees
- Track certificates (safety, licenses, training)
- Monitor expiry dates and renewals
- Social security registration
- Salary/payroll tracking
- Timesheet approval workflow
- Training records

---

## 3. Database Schema Status

### 3.1 Core Tables (Existing)
```sql
people
├── id, name, national_id, phone, email
├── position, department
├── employment_type (Permanent/Contract/Daily)
├── hire_date, termination_date
├── status (Active/Inactive/Terminated)
└── created_by, created_at

people_certificates
├── people_id (FK)
├── certificate_type (Safety Training, First Aid, etc.)
├── certificate_number, issuer
├── issue_date, expiry_date
├── file_path (PDF/image)
├── status (Valid/Expired/Revoked)
└── verified_by, verified_at
```

### 3.2 Missing Tables (Need to Create)
```sql
-- Social Security Tracking
people_social_security
├── people_id (FK)
├── ss_number (เลขประกันสังคม)
├── registration_date
├── employer_contribution, employee_contribution
├── status (Active/Suspended/Terminated)
└── notes

-- Salary/Payroll
people_salary
├── people_id (FK)
├── base_salary, allowances
├── effective_date
├── payment_frequency (Monthly/Daily)
├── bank_account, bank_name
└── notes

-- Training Records
people_training
├── people_id (FK)
├── training_name, training_type
├── training_date, duration_hours
├── trainer, location
├── certificate_issued (Yes/No)
├── expiry_date (if renewable)
├── file_path
└── notes
```

---

## 4. Module Structure Plan

### 4.1 People Master Module
**Location**: `modules/hrm/people/`

Files needed:
- `index.php` - List all people with filters
- `create.php` - Add new person
- `view.php` - View person details (tabs: Info, Certificates, Training, Salary, Timesheet)
- `edit.php` - Edit person info
- `api/` - CRUD APIs

### 4.2 Certificate Management
**Location**: `modules/hrm/certificates/`

Files needed:
- `index.php` - List all certificates with expiry alerts
- `add.php` - Add certificate to person
- `renew.php` - Renew expiring certificate
- `dashboard.php` - Certificate expiry dashboard

### 4.3 Social Security
**Location**: `modules/hrm/social_security/`

Files needed:
- `index.php` - List all SS registrations
- `register.php` - Register new SS
- `contributions.php` - Track monthly contributions

### 4.4 Training
**Location**: `modules/hrm/training/`

Files needed:
- `index.php` - List all training records
- `schedule.php` - Schedule training
- `attendance.php` - Record attendance

---

## 5. Integration Points

### 5.1 PR Manpower → People
- When PR approved → Create PO
- When PO GR → Register people (name, national_id, phone)
- Link to `people` table for full HR tracking

### 5.2 Planning → Compliance Check
- Before dispatch, check:
  - Required certificates valid?
  - Training up to date?
  - Social security active?

### 5.3 Timesheet → Salary
- Daily/monthly timesheet approval
- Calculate salary based on:
  - Base salary (monthly)
  - Daily rate × days worked (daily workers)
  - Overtime, allowances

---

## 6. Implementation Phases

### Phase 1: Fix & Enhance PR Manpower ✅
- [x] Make Job optional in PR Manpower
- [x] Fix save error
- [x] Update UI text

### Phase 2: People Master CRUD (Priority: HIGH)
- [ ] Create `modules/hrm/people/` folder structure
- [ ] Build people list page with filters
- [ ] Build people create/edit forms
- [ ] Build people view page with tabs
- [ ] Add navigation menu item

### Phase 3: Certificate Management (Priority: HIGH)
- [ ] Create SQL patch for missing tables
- [ ] Build certificate CRUD UI
- [ ] Build expiry alert dashboard
- [ ] Email/LINE notifications for expiring certs

### Phase 4: Social Security & Salary (Priority: MEDIUM)
- [ ] Create SQL schema for SS and Salary
- [ ] Build SS registration UI
- [ ] Build salary management UI
- [ ] Monthly contribution tracking

### Phase 5: Training Records (Priority: MEDIUM)
- [ ] Create SQL schema for training
- [ ] Build training schedule UI
- [ ] Build attendance tracking
- [ ] Link to certificates

### Phase 6: Integration & Compliance (Priority: HIGH)
- [ ] Link PR → PO → GR → People registration
- [ ] Build compliance gate before dispatch
- [ ] Certificate validation in Planning
- [ ] Manager override workflow

---

## 7. Quick Wins (Can Do Now)

1. ✅ **PR Manpower Job Optional** - DONE
2. **People List Page** - Show existing people from `people` table
3. **Certificate Expiry Dashboard** - Query `people_certificates` for expiring certs
4. **Add People to Navigation** - Link to HRM module

---

## 8. Files to Create/Modify

### Immediate (Phase 2)
```
modules/hrm/
├── people/
│   ├── index.php (list)
│   ├── create.php
│   ├── view.php
│   ├── edit.php
│   └── api/
│       ├── create.php
│       ├── update.php
│       └── delete.php
├── certificates/
│   ├── index.php
│   ├── add.php
│   └── dashboard.php
└── dashboard.php (HRM overview)
```

### SQL Patches
```
sql/
├── patch_people_social_security.sql
├── patch_people_salary.sql
└── patch_people_training.sql
```

---

## 9. RBAC Permissions

| Module | View | Create | Edit | Delete | Approve |
|--------|------|--------|------|--------|---------|
| People Master | HR, ADM, MGR | HR, ADM | HR, ADM | ADM | - |
| Certificates | HR, ADM, MGR | HR, ADM | HR, ADM | ADM | MGR |
| Social Security | HR, ADM, MGR | HR, ADM | HR, ADM | ADM | - |
| Salary | HR, ADM, MGR | ADM | ADM | ADM | MGR |
| Training | HR, ADM, MGR | HR, ADM | HR, ADM | - | - |
| Timesheet | ALL | Site Lead | HR, MGR | - | HR→MGR |

---

## 10. Next Steps

1. **Confirm with user**: Is this plan aligned with your vision?
2. **Start Phase 2**: Build People Master CRUD
3. **Create SQL patches**: For missing tables
4. **Build Certificate Dashboard**: Show expiring certificates

---

## Notes
- PR Manpower now supports both Job-specific and general HR hiring
- Existing `people` and `people_certificates` tables are ready to use
- Need to create UI for managing these records
- Integration with Planning/Dispatch compliance gate is critical
