# Financial Module Stabilization Plan

**สร้างระบบการเงินที่มั่นคง** โดยเพิ่ม AP (Accounts Payable) module เพื่อเชื่อมต่อ GR → Supplier Invoice → Payment และปรับปรุง AR ให้สมบูรณ์

---

## 📊 Gap Analysis (สถานะปัจจุบัน)

| Module | Status | หมายเหตุ |
|--------|--------|---------|
| **AR Invoices** | ✅ มี | Job → Invoice → Customer Payment |
| **AR Payments** | ✅ มี | รับชำระจากลูกค้า |
| **AR Credit Notes** | ✅ มี | Reversal pattern |
| **AP Invoices** | ❌ ไม่มี | **ขาด** - ไม่มีการบันทึกใบแจ้งหนี้จาก Supplier |
| **AP Payments** | ❌ ไม่มี | **ขาด** - ไม่มีการบันทึกจ่ายเจ้าหนี้ |
| **GR ↔ AP Link** | ❌ ไม่มี | **ขาด** - GR ไม่เชื่อมกับ AP |
| **Job Cost Integration** | ⚠️ บางส่วน | มี schema แต่ยังไม่ auto-calculate |

---

## 🎯 เป้าหมาย

1. **AP Module สมบูรณ์** - PO → GR → AP Invoice → AP Payment
2. **3-Way Matching** - PO/GR/Invoice matching ก่อนจ่าย
3. **Job Cost Auto-Update** - Cost จาก AP/Timesheet → Job
4. **Financial Dashboard** - AR Aging + AP Aging + Cash Flow

---

## 📋 Implementation Plan

### Phase 1: AP Schema & Core (2-3 วัน)
**Priority: 🔴 Critical**

```
Files to create:
├── sql/schema_m12_ap.sql
│   ├── ap_invoices (ใบแจ้งหนี้จาก Supplier)
│   ├── ap_invoice_lines
│   ├── ap_payments (จ่ายเจ้าหนี้)
│   └── ap_debit_notes (ลดหนี้)
├── core/APInvoice.php
└── core/APPayment.php
```

**Schema Design:**
- `ap_invoices`: supplier_id, po_id, gr_id, supplier_invoice_no, amount, due_date, status
- `ap_payments`: ap_invoice_id, amount, payment_method, reference_no, **status** (Pending/Approved/Posted/Reversed), **approved_by**
- Follow agents.md: **No DELETE**, reversal pattern only

### Phase 2: AP UI & Integration (2-3 วัน)
**Priority: 🔴 Critical**

```
Files to create:
├── modules/accounting/ap/
│   ├── index.php (AP Invoice list)
│   ├── create.php (สร้างจาก GR)
│   ├── view.php
│   └── payment.php (จ่ายเจ้าหนี้)
└── modules/accounting/ap-payments/
    ├── index.php
    └── create.php
```

**Integration Points:**
- GR View → Button "สร้าง AP Invoice"
- PO View → Show linked AP Invoices
- AP Invoice → Link to GR/PO

### Phase 3: 3-Way Matching (1-2 วัน)
**Priority: 🟡 High**

```
Files to update:
├── core/APInvoice.php (add matching logic)
└── modules/accounting/ap/create.php (matching UI)
```

**Matching Rules:**
1. **PO Amount** vs **GR Received** vs **AP Invoice**
2. Allow variance within threshold (configurable)
3. Block payment if mismatch without approval
4. Audit log for all overrides

### Phase 4: Job Cost Management (1-2 วัน)
**Priority: 🟡 High**

> **Note:** Backend (`Costing.php::addCostLine()`) รองรับ manual entry แล้ว แต่ยังไม่มี UI

```
Files to create/update:
├── modules/jobs/costing.php (NEW - UI สำหรับดู/เพิ่ม cost)
│   ├── View estimated vs actual
│   ├── Add manual cost entry (ACC/ADM/MGR only)
│   └── View cost breakdown by type
├── core/Costing.php (update: add AP integration)
└── Triggers/Events on:
    ├── AP Invoice → Material cost (auto)
    ├── Timesheet approved → Manpower cost (auto)
    └── Route completed → Transport cost (auto)
```

**Cost Input Methods:**
| Method | Source | Auto/Manual |
|--------|--------|-------------|
| Material from AP | AP Invoice lines linked to Job | Auto |
| Manpower from Timesheet | Approved timesheet entries | Auto |
| Transport from Route | Route/PO costs | Auto |
| **Manual Entry** | ACC ใส่เอง (ค่าใช้จ่ายอื่นๆ) | **Manual** |

**UI Features for ACC:**
- ดู Estimated vs Actual comparison
- เพิ่ม cost line manual (Material/Manpower/Transport/Other)
- แก้ไข cost line (ถ้ายังไม่ lock)
- Void cost line (reversal pattern)

### Phase 5: Financial Dashboard (1 วัน)
**Priority: 🟢 Medium**

```
Files to update:
├── core/KPI.php (add AP KPIs)
├── index.php (dashboard widgets)
└── modules/accounting/dashboard.php (new)
```

**KPIs to add:**
- AP Aging (Current/30/60/90+ days)
- AP vs AR comparison
- Upcoming payments due
- Cash flow forecast (simple)

---

## 🗓️ Timeline

| Phase | Duration | Status |
|-------|----------|--------|
| Phase 1: AP Schema & Core | 2-3 วัน | Pending |
| Phase 2: AP UI & Integration | 2-3 วัน | Pending |
| Phase 3: 3-Way Matching | 1-2 วัน | Pending |
| Phase 4: Job Cost Auto | 1-2 วัน | Pending |
| Phase 5: Financial Dashboard | 1 วัน | Pending |

**Total: 7-11 วันทำงาน**

---

## ⚠️ agents.md Compliance

| Rule | Implementation |
|------|----------------|
| §1.1 No DELETE | AP tables use void/reversal only |
| §1.3 Reversal Pattern | AP Debit Note for corrections |
| §1.5 Audit Trail | All AP actions logged |
| §3.6 Multiple invoices | 1 PO can have multiple GR, multiple AP invoices |

---

## 🔐 RBAC Permissions (ต้องเพิ่ม)

| Permission | Roles |
|------------|-------|
| AP_VIEW | ACC, ADM, MGR |
| AP_CREATE | ACC, ADM |
| AP_APPROVE | MGR, ADM |
| AP_PAYMENT | ACC, ADM |
| AP_VOID | ADM, MGR |

---

## 📁 Deliverables Summary

**New Files (14):**
- `sql/schema_m12_ap.sql`
- `core/APInvoice.php`
- `core/APPayment.php`
- `modules/accounting/ap/index.php`
- `modules/accounting/ap/create.php`
- `modules/accounting/ap/view.php`
- `modules/accounting/ap/payment.php`
- `modules/accounting/ap-payments/index.php`
- `modules/accounting/ap-payments/create.php`
- `modules/accounting/dashboard.php`
- `modules/jobs/costing.php`

**Updated Files (5):**
- `core/Costing.php`
- `core/KPI.php`
- `modules/procurement/gr/view.php` (add AP link)
- `modules/procurement/po/view.php` (show AP status)
- `index.php` (dashboard widgets)

---

## ✅ Definition of Done

- [ ] AP Invoice CRUD ทำงานได้
- [ ] AP Payment ทำงานได้พร้อม reversal
- [ ] GR → AP Invoice flow สมบูรณ์
- [ ] 3-Way matching มี warning/block
- [ ] Job cost auto-update จาก AP
- [ ] AP Aging report ใช้งานได้
- [ ] Audit log ครบทุก action
- [ ] RBAC enforce ทุก page

---

## ✅ Confirmed Requirements

| Requirement | Decision |
|-------------|----------|
| AP Invoice Number | `API-YYYYNNNN` (e.g., API2026-0001) |
| Payment Approval | ✅ ต้องมี approval ก่อนจ่าย |
| Partial Payment | ✅ รองรับจ่ายบางส่วน |
| Multi-currency | ⏸️ เลื่อนไปก่อน (Future Phase) |
