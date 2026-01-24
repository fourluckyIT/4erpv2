# Salary & OT Management System
**ERP v2 - HR Module**

## Overview
ระบบจัดการเงินเดือน ค่าแรง และ OT ที่ให้ HR สามารถปรับเปลี่ยนได้ภายหลัง รองรับการคำนวณ OT และ Payroll

---

## 1. Database Schema

### 1.1 people_salary_history (Append-Only)
ประวัติการเปลี่ยนแปลงเงินเดือน/ค่าแรง

**Key Fields:**
- `salary_type`: Monthly, Daily, Hourly
- `base_salary`: เงินเดือนฐาน (รายเดือน)
- `daily_rate`: ค่าแรงรายวัน
- `hourly_rate`: ค่าแรงรายชั่วโมง
- `ot_rate_multiplier`: ตัวคูณ OT (default 1.5x)
- `holiday_rate_multiplier`: ตัวคูณวันหยุด (default 2.0x)
- `standard_hours_per_day`: ชั่วโมงทำงานมาตรฐาน (default 8)
- `standard_days_per_month`: วันทำงานต่อเดือน (default 22)

**Allowances:**
- Position, Transport, Meal, Housing, Other

**Payment Info:**
- Bank account details
- Payment frequency

**Trigger:**
- Auto-deactivate previous record when new salary is added

### 1.2 people_employment_terms
เงื่อนไขการจ้างงาน

**Key Fields:**
- `employment_type`: Permanent, Contract, Daily, Probation, Freelance
- `contract_start_date`, `contract_end_date`
- `work_schedule_type`: Fixed, Shift, Flexible, Project
- `work_start_time`, `work_end_time`
- `annual_leave_days`, `sick_leave_days`
- `social_security_number`, `social_security_rate_employer/employee`
- `tax_id`, `withholding_tax_rate`

### 1.3 people_overtime
บันทึก OT สำหรับคำนวณ

**Key Fields:**
- `work_date`, `ot_type` (Weekday/Weekend/Holiday)
- `start_time`, `end_time`, `total_hours`
- `base_rate`, `multiplier`, `ot_amount`
- `job_id` (optional - link to specific job)
- `timesheet_id` (link to timesheet)
- `status`: Pending → Approved → Paid

**Approval Workflow:**
- Site Lead/HR submits OT
- Manager approves
- Accounting processes payment

### 1.4 people_payroll
รายการเงินเดือนรายเดือน

**Income:**
- Base salary + Allowances + OT + Bonus + Other

**Deductions:**
- Social Security (employee)
- Withholding Tax
- Advance deduction
- Other

**Net Salary:**
- Gross Income - Total Deduction

**Employer Costs:**
- Social Security (employer)
- Provident Fund

**Status Flow:**
- Draft → Calculated → Approved → Paid

---

## 2. Calculation Logic

### 2.1 Hourly Rate Calculation

**From Monthly Salary:**
```
hourly_rate = base_salary / (standard_days_per_month × standard_hours_per_day)
Example: 30,000 / (22 × 8) = 170.45 บาท/ชม.
```

**From Daily Rate:**
```
hourly_rate = daily_rate / standard_hours_per_day
Example: 500 / 8 = 62.50 บาท/ชม.
```

### 2.2 OT Calculation

**Weekday OT (after 8 hours):**
```
ot_amount = hourly_rate × ot_hours × 1.5
Example: 170.45 × 2 × 1.5 = 511.35 บาท
```

**Weekend OT:**
```
ot_amount = hourly_rate × ot_hours × 1.5
```

**Holiday OT:**
```
ot_amount = hourly_rate × ot_hours × 2.0
Example: 170.45 × 8 × 2.0 = 2,727.20 บาท
```

### 2.3 Social Security Calculation

**Employee Contribution (5%):**
```
ss_employee = MIN(base_salary, 15000) × 0.05
Example: MIN(30000, 15000) × 0.05 = 750 บาท
```

**Employer Contribution (5%):**
```
ss_employer = MIN(base_salary, 15000) × 0.05
Example: 750 บาท
```

### 2.4 Withholding Tax (Progressive)

Based on annual income:
- 0 - 150,000: 0%
- 150,001 - 300,000: 5%
- 300,001 - 500,000: 10%
- 500,001 - 750,000: 15%
- 750,001 - 1,000,000: 20%
- 1,000,001 - 2,000,000: 25%
- 2,000,001 - 5,000,000: 30%
- 5,000,001+: 35%

### 2.5 Monthly Payroll Calculation

```php
// Income
$gross_income = $base_salary + $allowances_total + $ot_amount + $bonus;

// Deductions
$ss_employee = min($base_salary, 15000) * 0.05;
$wht = calculateWithholdingTax($gross_income * 12) / 12; // Monthly portion
$total_deduction = $ss_employee + $wht + $advance + $other_deduction;

// Net
$net_salary = $gross_income - $total_deduction;

// Employer cost
$ss_employer = min($base_salary, 15000) * 0.05;
$total_employer_cost = $gross_income + $ss_employer + $provident_fund;
```

---

## 3. UI Modules

### 3.1 People Salary Management
**Location:** `modules/hrm/salary/`

**Files:**
- `index.php` - List all people with current salary
- `history.php?people_id=X` - Salary history for person
- `edit.php?people_id=X` - Add/edit salary record
- `bulk_adjust.php` - Bulk salary adjustment

**Features:**
- View current salary for all employees
- Add new salary record (auto-deactivates previous)
- View salary history timeline
- Calculate hourly/daily rate from monthly
- Set OT multipliers
- Manage allowances

### 3.2 Employment Terms
**Location:** `modules/hrm/employment/`

**Files:**
- `index.php` - List employment terms
- `edit.php?people_id=X` - Edit employment terms
- `contracts.php` - Contract expiry tracking

**Features:**
- Manage contract dates
- Set work schedule
- Configure leave entitlements
- Social security registration
- Tax ID management

### 3.3 OT Management
**Location:** `modules/hrm/overtime/`

**Files:**
- `index.php` - List OT records (pending approval)
- `submit.php` - Submit OT request
- `approve.php` - Manager approval
- `report.php` - OT summary report

**Features:**
- Submit OT with date, time, hours
- Auto-calculate OT amount based on salary
- Approval workflow (HR → Manager)
- Link to Job/Timesheet
- OT report by person/period

### 3.4 Payroll
**Location:** `modules/hrm/payroll/`

**Files:**
- `index.php` - Payroll dashboard
- `calculate.php` - Calculate monthly payroll
- `view.php?id=X` - View payroll slip
- `approve.php` - Approve payroll batch
- `export.php` - Export for bank transfer

**Features:**
- Auto-calculate monthly payroll from:
  - Current salary record
  - Approved OT
  - Timesheet attendance
  - Leave records
- Review and adjust before approval
- Generate payslip PDF
- Export bank transfer file
- Payroll history

---

## 4. Integration Points

### 4.1 PR Manpower → People → Salary
```
1. Create PR Manpower (with daily_rate estimate)
2. Approve PR → Create PO
3. PO GR → Register people in `people` table
4. HR sets actual salary in `people_salary_history`
5. HR sets employment terms in `people_employment_terms`
```

### 4.2 Timesheet → OT → Payroll
```
1. Daily timesheet check-in/out
2. If hours > 8, create OT record
3. HR/Manager approves OT
4. Monthly payroll calculation includes approved OT
5. Generate payslip
```

### 4.3 Job Planning → Timesheet → Salary
```
1. Plan assigns people to job
2. People check-in via timesheet
3. Timesheet approved (Site Lead → HR → Manager)
4. Approved hours used for payroll
5. If job-specific rate differs, record in OT notes
```

---

## 5. RBAC Permissions

| Module | View | Create | Edit | Approve | Delete |
|--------|------|--------|------|---------|--------|
| Salary | HR, ADM, MGR | HR, ADM | HR, ADM | MGR | ADM |
| Employment Terms | HR, ADM, MGR | HR, ADM | HR, ADM | - | ADM |
| OT Submit | Site Lead, HR | Site Lead, HR | HR | - | - |
| OT Approve | HR, MGR | - | - | MGR | - |
| Payroll Calculate | HR, ADM | HR, ADM | HR, ADM | - | - |
| Payroll Approve | MGR, ADM | - | - | MGR | - |
| Payroll View | HR, ADM, MGR, Self | - | - | - | - |

**Self Access:**
- People can view their own salary history
- People can view their own payslips
- People can submit OT requests

---

## 6. Implementation Phases

### Phase 1: Database & Core Logic ✅
- [x] Create SQL schema (`patch_people_salary_employment.sql`)
- [ ] Run SQL patch
- [ ] Create PHP helper classes:
  - `SalaryCalculator.php`
  - `OvertimeCalculator.php`
  - `PayrollCalculator.php`

### Phase 2: Salary Management (Priority: HIGH)
- [ ] Build salary list page
- [ ] Build salary edit form
- [ ] Build salary history view
- [ ] Auto-calculate hourly/daily rates

### Phase 3: OT Management (Priority: HIGH)
- [ ] Build OT submission form
- [ ] Build OT approval workflow
- [ ] Auto-calculate OT amount
- [ ] Link to Timesheet

### Phase 4: Payroll (Priority: MEDIUM)
- [ ] Build payroll calculation engine
- [ ] Build payroll review UI
- [ ] Generate payslip PDF
- [ ] Export bank transfer file

### Phase 5: Employment Terms (Priority: MEDIUM)
- [ ] Build employment terms form
- [ ] Contract expiry alerts
- [ ] Leave balance tracking

---

## 7. Sample Scenarios

### Scenario 1: Monthly Employee with OT
**Person:** John (Software Engineer)
- Base Salary: 30,000 บาท/เดือน
- Standard: 22 days/month, 8 hours/day
- Hourly Rate: 30,000 / (22 × 8) = 170.45 บาท/ชม.

**Month Work:**
- Regular: 22 days × 8 hours = 176 hours
- OT Weekday: 10 hours × 1.5 = 2,556.75 บาท
- OT Holiday: 8 hours × 2.0 = 2,727.20 บาท

**Payroll:**
- Base: 30,000
- OT: 5,283.95
- Gross: 35,283.95
- SS: -750
- WHT: -1,500 (estimate)
- Net: 33,033.95

### Scenario 2: Daily Worker
**Person:** Mike (Technician)
- Daily Rate: 500 บาท/วัน
- Hourly Rate: 500 / 8 = 62.50 บาท/ชม.

**Month Work:**
- Days worked: 25 days
- Regular pay: 25 × 500 = 12,500 บาท
- OT: 5 hours × 62.50 × 1.5 = 468.75 บาท

**Payroll:**
- Base: 12,500
- OT: 468.75
- Gross: 12,968.75
- SS: -648.44
- Net: 12,320.31

---

## 8. Next Steps

1. **Run SQL Patch:**
   ```bash
   mysql -u root -p 4erpv2 < sql/patch_people_salary_employment.sql
   ```

2. **Create Helper Classes:**
   - `core/SalaryCalculator.php`
   - `core/OvertimeCalculator.php`
   - `core/PayrollCalculator.php`

3. **Build Salary Management UI:**
   - Start with `modules/hrm/salary/index.php`
   - Then `modules/hrm/salary/edit.php`

4. **Test with Sample Data:**
   - Add salary records for existing people
   - Calculate hourly rates
   - Test OT calculation

---

## Notes
- All salary changes are append-only (audit trail)
- Triggers auto-deactivate previous records
- OT approval workflow prevents unauthorized OT pay
- Payroll calculation is reversible (Draft → Calculated → Approved)
- Support both monthly and daily workers
- Social security capped at 15,000 บาท
