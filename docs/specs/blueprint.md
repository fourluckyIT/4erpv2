# blueprint.md
# 4ERP v2 — Blueprint (Pure Requirements)
Version: 1.0
Date: 2026-01-24 (UTC+7)
Owner: JT

## 0) Executive Summary
4ERP v2 คือ ERP สำหรับธุรกิจงานบริการ/ไซต์งาน ที่ต้องคุม “งาน-คน-ของ-เวลา-เงิน” แบบ Job-centric
เป้าหมาย: ลด chaos, กันจองซ้ำ, ทำ traceability ได้จริง, ปิดบัญชี/ดู margin ได้, และมี audit ที่เชื่อถือได้

## 1) Scope
### 1.1 In-Scope (MVP→Scale)
- Job lifecycle + Planning (manpower/assets/consumables)
- Booking conflict prevention (double-booking guard)
- Procurement (PR/PO/GR) + Receiving (serial enforcement)
- Inventory ledger (multi-location) + Reservation + Packing
- Dispatch (Trip/Route) own/outsource + gates
- Site receiving/return + damage/loss handling
- Timesheet/Attendance workflow (Supervisor→HR→Payroll)
- Basic Finance: invoice/AR + job costing/margin (minimum viable)
- Reporting/KPI dashboards
- Audit logs, status history, append-only ledgers
- Role-based access + approval matrix

### 1.2 Out-of-Scope (initial)
- Full GL accounting (optional later)
- Advanced CRM, marketing automation
- Complex payroll engine (ใช้ export ได้ในช่วงแรก)

## 2) Core Concepts & Entities
### 2.1 Entities (หลัก)
- Customer, Site, Contract (optional)
- Job (type: LUMPSUM / DAYRENT / MANPOWER)
- Planning (time window + requirements)
- Resource: Employee, Team, Skill, Certification
- Item: Consumable / Non-Consumable / Device(Serialized)
- Warehouse/Location: MAIN, IN_TRANSIT, VEHICLE, SITE, CONSUME, QUARANTINE(optional)
- Documents: PR, PO, GR, StockMove, Reservation, Package, Trip/Route, DeliveryProof, ReturnNote, Timesheet, Invoice, CreditNote, Payment
- History: audit_logs, status_history, stock_ledger, financial_ledger (minimum), approval_logs

### 2.2 Universal Fields (ต้องมีเกือบทุก entity)
- id, doc_no, status
- created_by/at, updated_by/at (updated_at อาจเป็น last_modified)
- reference links (job_id/site_id/etc)
- attachments (0..n)
- voided_by/at/reason (ถ้า VOID)
- correlation_id (เชื่อมเหตุการณ์หลายเอกสารใน flow เดียว)

## 3) Time & Immutability Rules
### 3.1 Timezone
- แสดงผลทั้งหมดใช้ UTC+7
- storage ต้อง consistent ทั้งระบบ (เลือกแนวทางเดียว)

### 3.2 Immutability & Reversal
- DRAFT: edit freely
- CONFIRMED/SUBMITTED: immutable
- แก้ไขด้วย:
  - VOID (ยกเลิกเอกสารทั้งฉบับ)
  - CORRECTION (เอกสารแก้ไขที่อ้างอิงของเดิม)
  - REVERSAL (กลับรายการ ledger เช่น stock/financial)

## 4) Job Lifecycle & Workflow
### 4.1 Job Status (ขั้นต่ำ)
DRAFT → PLANNED → IN_PROGRESS → DONE → INVOICED → CLOSED
+ CANCELLED/VOID

### 4.2 Job Types (rules)
- LUMPSUM: ราคาคงที่, อาจมี milestones
- DAYRENT: คิดตามวัน/ช่วงวัน, มีขั้นต่ำ
- MANPOWER: คิดตามชั่วโมง/วัน/OT ตามเรท

### 4.3 Quotation
- ไม่ต้อง generate quotation ในระบบ
- ต้องมีช่อง `quotation_no` + แนบไฟล์ quotation PDF/JPG

## 5) Planning & Booking Conflicts
### 5.1 Planning
- กำหนด time window (start/end)
- วางแผน:
  - manpower assignment (employee/team)
  - assets (serialized device)
  - consumable requirements (qty + UOM)
- ต้องมี “plan version” หรือ history เพื่อ track การเปลี่ยนแผน

### 5.2 Booking Conflict Guard
- ห้ามจอง resource ซ้ำในช่วงเวลาเดียวกัน (ตาม policy)
- ต้อง detect conflict ก่อน confirm
- override ได้เฉพาะ role ที่กำหนด + approval log

## 6) Compliance Gate (Site Requirements)
- Site/Job สามารถกำหนด requirement:
  - cert/training type, min level, expiry date
- เมื่อ assign manpower หรือ confirm dispatch:
  - ถ้าไม่ผ่าน → BLOCK
  - หรือ OVERRIDE ผ่าน approval matrix พร้อมเหตุผล

## 7) Procurement (PR/PO/GR) & Receiving
### 7.1 PR (Purchase Requisition)
- สร้างจาก planning requirement หรือ manual
- ต้องระบุ:
  - job_id (ถ้าเพื่อ job) หรือ replenishment flag
  - item/qty/uom/needed_date
  - reason/notes

### 7.2 PO (Purchase Order)
- vendor, price, lead time, terms
- approval ตามวงเงิน

### 7.3 GR (Goods Receipt)
- รองรับ partial receiving/backorder
- quality check: accepted/rejected/damaged
- serialized device:
  - ต้องกรอก serial list
  - serial uniqueness enforcement

## 8) Inventory & Stock Ledger
### 8.1 Ledger
- stock_ledger เป็น append-only
- on_hand คำนวณจาก ledger หรือมี materialized view ที่ rebuild ได้
- movement types:
  - RECEIVE, ISSUE, TRANSFER, RETURN, ADJUST, CONSUME, SCRAP

### 8.2 Multi-location
- MAIN, IN_TRANSIT, VEHICLE, SITE, CONSUME (+QUARANTINE optional)
- ทุก movement ต้องมี from/to location

### 8.3 Serial Traceability
- serial ต้อง trace ไป:
  - GR → package → trip → site receiving → return/consume/scrap

## 9) Reservation & Packing
### 9.1 Reservation
- สร้างเมื่อ package/trip allocation
- สูตร: Available = OnHand - Reserved
- serial reservation: 1 serial ต่อ 1 allocation เท่านั้น
- ต้องกัน concurrent allocation (transaction/locking)

### 9.2 Package (Packing)
- package มี status:
  - DRAFT → PACKED → CONFIRMED → LOADED → DISPATCHED → DELIVERED → RETURNED(optional) → VOID
- package lines: item/qty/uom + serial list (ถ้ามี)
- confirmation แล้วแก้ด้วย correction/reversal

## 10) Dispatch: Trip/Route
### 10.1 Trip Types
- OWN (รถของบริษัท)
- OUTSOURCE (vendor transport)

### 10.2 Dispatch Gates (ก่อน confirm)
- Reservation OK หรือมี shortage approval
- Compliance OK หรือมี override approval
- เอกสาร required ครบ (policy-based)
- package confirmed

### 10.3 Events
- dispatch, arrival, delivered, return pickup
- proof of delivery (signature/photo)

## 11) Site Receiving / Return / Exceptions
- Site receiving ยืนยันสิ่งที่ได้รับจริง (qty/serial)
- Return note สำหรับคืนของกลับคลัง/รถ
- Damage/Loss report:
  - ระบุเหตุการณ์, ผู้รับผิดชอบ, รูป, action (adjust/scrap/claim)
- partial delivery/partial return ต้องรองรับ

## 12) Timesheet & Attendance (Supervisor from Planned Manpower)
### 12.1 Timesheet Sheet (รายวัน)
Timesheet เป็นเอกสาร “รายวันต่อ job/site/team” ที่:
- ดึงรายชื่อ default จาก **manpower assignment ของ job ในวันนั้น**
- supervisor สามารถ:
  - mark present/absent
  - check-in/out time
  - breaks, total hours
  - OT hours + reason
  - exceptions (late, missing checkout, safety incident note)
  - attachments (ภาพกระดาษ/รูปไซต์)

### 12.2 Workflow
- DRAFT (สร้างอัตโนมัติหรือ supervisor สร้าง)
- CONFIRMED_BY_SUPERVISOR (ล็อก)
- SUBMITTED_TO_HR (auto)
- HR_QUEUE:
  - payroll_ready
  - returned (ให้แก้ด้วย correction)
  - void

### 12.3 Rules
- หลัง confirm ห้ามแก้ทับ ต้องใช้ correction sheet
- anomaly detection:
  - OT > threshold, missing checkout, times overlap, non-planned worker added (requires reason)

## 13) Finance (Minimum Viable)
### 13.1 Pricing / Rate Engine (ขั้นต่ำ)
- rate card ต่อ job type:
  - lumpsum fixed
  - dayrent per day/half-day + minimum
  - manpower per hour/day + OT rules
- discount/surcharge (policy-based)
- tax flags (VAT, withholding) ตามการใช้งานจริง

### 13.2 Invoicing & AR
- invoice สร้างจาก job deliverables หรือ manual line items
- credit note สำหรับแก้ไข invoice
- payment receipt: partial/full
- AR aging report (ขั้นต่ำ)

### 13.3 Job Cost & Margin
- cost sources:
  - material consumed/issued
  - manpower hours (timesheet)
  - outsource vendor costs
  - transport cost (optional)
- ต้องแสดง job margin (estimated vs actual)

## 14) Approvals
- approval_logs append-only
- policy:
  - purchase threshold
  - stock adjust
  - compliance override
  - shortage override
  - timesheet exceptions

## 15) Reporting & KPI (ขั้นต่ำ)
- Operational:
  - upcoming dispatch, packing queue, GR pending, PO overdue
  - booking conflicts count
  - stockout risk / reserved vs onhand
- Management:
  - job profitability, cost breakdown
  - utilization (manpower/assets)
  - on-time delivery rate
  - AR aging
  - timesheet anomalies rate

## 16) Security & Permissions
- role-based access
- sensitive data: wage/HR/finance จำกัดสิทธิ์
- audit on critical view/action
- attachments access control

## 17) Non-Functional Requirements
- Reliability: ledger rebuildable, idempotent operations for critical writes
- Performance: common pages load within acceptable time on mid hardware
- Concurrency: reservation/serial allocation safe under concurrent users
- Backup/Restore: documented runbook + retention policy
- Data retention: audit/ledger retention policy

## 18) Document Numbering
- JOB-YYYYNNNN, PR-YYYYNNNN, PO-YYYYNNNN, GR-YYYYNNNN
- PKG-YYYYNNNN, TRIP-YYYYNNNN, TS-YYYYMMDD-NNN, INV-YYYYNNNN, CN-YYYYNNNN, PAY-YYYYNNNN
(รูปแบบปรับได้ แต่ต้องสม่ำเสมอและไม่ซ้ำ)

## 19) Definition of Done (System-level)
- Immutable enforcement ทำได้จริง
- Reservation concurrency test ผ่าน
- Traceability เปิดย้อน ledger → เอกสารต้นทางได้
- Timesheet auto-populate จาก planned manpower
- Approval logs ครบทุก override
- KPI หน้าหลักอย่างน้อย 8 ตัวใช้งานได้จริง
