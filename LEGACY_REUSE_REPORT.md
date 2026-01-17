# 🔍 Legacy Reuse Report: ERPv3-Legacy → ERPv2

**Generated:** 2026-01-17 10:10 (UTC+7)  
**Source Repo:** https://github.com/fourluckyIT/4erpv3-legacy  
**Target Repo:** https://github.com/fourluckyIT/4erpv2

---

## 1. Quick Inventory

### Module Structure
```
4erpv3-legacy/
├── api/                    # 2 files (check_availability, save_supplier)
├── assets/                 # CSS/JS (4 files)
├── config/                 # 3 files (constants, database, demo_mode)
├── dashboard_views/        # 10 role-based dashboards
├── database/               # SQLite + migrations (7) + seeds (2)
├── includes/
│   ├── classes/            # 16 Agent classes (core business logic)
│   ├── components/         # 2 reusable UI components
│   └── rbac/               # 3 files (config, policy, rbac.php)
├── modules/
│   ├── accounting/         # 6 files (invoices, payments)
│   ├── calendar/          # 3 files
│   ├── documents/         # 2 files
│   ├── hrm/               # 7 files (employees, certifications)
│   ├── jobs/              # 7 files
│   ├── maintenance/       # 12 files
│   ├── masterdata/        # 4 files
│   ├── planning/          # 13 files ⭐ (biggest module)
│   ├── pricing/           # 5 files
│   ├── purchase/          # 24 files (PR/PO/GR flow)
│   ├── transport/         # 11 files
│   └── users/             # 5 files
└── *.php                   # Entry points (index, login, logout)
```

### Core Libraries (includes/classes/)
| Class | Lines | Purpose |
|-------|-------|---------|
| `BaseAgent` | 39 | Base class with audit/event logging |
| `WorkflowEngine` | 100 | State machine for 6 entity types |
| `DocumentNumbering` | 211 | Configurable doc number generation |
| `LoggingAgent` | 25 | System logs wrapper |
| `JobAgent` | 126 | Job CRUD + status transitions |
| `PlanningAgent` | 138 | Resource booking + materials |
| `PurchaseAgent` | 187 | PR→PO→GR flow |
| `TransportAgent` | 360 | Shipments, POD, expenses |
| `AccountingAgent` | 117 | AR invoices, payments, credit |
| `WarehouseAgent` | ~150 | Stock movements (GI/GR) |
| `MaintenanceAgent` | ~100 | Equipment maintenance workflow |
| `MasterDataAgent` | ~80 | Customer/Supplier/Item CRUD |
| `HRMAgent` | ~60 | Employee + certifications |
| `CalendarAgent` | ~50 | Events for dashboard |
| `PricingAgent` | ~80 | Quotation pricing |
| `EventAgent` | ~40 | Domain event dispatch |

### Key Patterns Identified

#### 🔐 Logging/Audit Pattern
```php
// BaseAgent.php - All agents inherit this
$this->logEvent('entity.action', ['payload' => $data]);  // → domain_events
$this->logAudit('ACTION', 'ENTITY_TYPE', $id, $details); // → audit_logs
```

#### 🔄 Status Machine Pattern
```php
// WorkflowEngine.php - Enforces valid transitions
$flows = [
    'job' => ['DRAFT' => ['PENDING_APPROVAL'], ...],
    'pr'  => ['DRAFT' => ['PENDING_APPROVAL'], ...],
    'po'  => ['DRAFT' => ['ISSUED'], ...],
    'maintenance' => ['OPEN' => ['DIAGNOSIS'], ...],
    'invoice' => ['DRAFT' => ['POSTED', 'VOID'], ...]
];
```

#### 📝 Document Numbering Pattern
```php
// DocumentNumbering.php - Configurable per doc type
// Format: PREFIX-YYMM-NNN or PREFIX-YYYY-NNN
// Supports: yearly/monthly reset, custom separator, digit length
$docNum->generateNumber('JOB');  // → JOB-2601-001
```

---

## 2. Reuse Candidates (Ranked)

### Tier A: High Priority (Copy with minimal changes)

| # | Location | What It Does | Why Useful for v2 | Mode | Risk |
|---|----------|--------------|-------------------|------|------|
| 1 | `includes/classes/WorkflowEngine.php` | State machine for job/pr/po/invoice/maintenance | v2 already has StatusMachine.php but this has more flows | **refactor then port** | Low |
| 2 | `includes/classes/DocumentNumbering.php` | Configurable doc numbering with DB config | v2's DocumentNumber.php is simpler; this adds config table | **refactor then port** | Low |
| 3 | `includes/rbac/` | 3-file RBAC with tier-based enforcement | v2 has RBAC.php; legacy adds LOG_ONLY mode & file logging | **reference only** | Low |
| 4 | `includes/booking_conflicts.php` | Double-booking prevention with row locks | Critical for Phase 5 planning | **copy as-is** | Low |
| 5 | `includes/classes/BaseAgent.php` | Audit + event logging base class | Standard pattern to adopt | **copy as-is** | Low |

### Tier B: Medium Priority (Port specific methods)

| # | Location | What It Does | Why Useful | Mode | Risk |
|---|----------|--------------|------------|------|------|
| 6 | `includes/classes/PurchaseAgent.php` | Complete PR→PO→GR with workflow validation | Phase 4 procurement | **refactor then port** | Med |
| 7 | `includes/classes/TransportAgent.php` | Shipments, POD, expense management | Phase 5+ transport | **refactor then port** | Med |
| 8 | `includes/classes/AccountingAgent.php` | AR invoices with credit limit check | Phase 9 accounting | **refactor then port** | Med |
| 9 | `includes/classes/PlanningAgent.php` | Resource booking with conflict detection | Phase 5 planning | **refactor then port** | Med |
| 10 | `modules/planning/return_trip.php` | 40KB complex return workflow UI | Phase 6 return | **reference only** | High |

### Tier C: Reference Only

| # | Location | What It Does | Why Useful | Mode | Risk |
|---|----------|--------------|------------|------|------|
| 11 | `modules/purchase/` | 24 files for complete purchase module UI | Phase 4 UI reference | **reference only** | Med |
| 12 | `dashboard_views/` | 10 role-based dashboard layouts | UI patterns | **reference only** | Low |
| 13 | `GLOSSARY.md` | Thai+English terminology guide | Documentation | **copy as-is** | Low |
| 14 | `database/seeds/master_data_seed.sql` | Sample Thai master data | Testing | **copy as-is** | Low |
| 15 | `includes/classes/MaintenanceAgent.php` | Equipment maintenance workflow | Phase 7 | **reference only** | Med |

---

## 3. Flow Coverage Mapping

### v3 Features → v2 Roadmap Phases

| Phase | v2 Status | v3 Coverage | Accelerators |
|-------|-----------|-------------|--------------|
| **Phase 1** (Auth/RBAC/Audit/Numbering) | ✅ Done | ✅ Has | `rbac/` for LOG_ONLY mode, `BaseAgent` for audit pattern |
| **Phase 2** (Job Statuses/Lockpoints) | ✅ Done | ✅ Has | `WorkflowEngine` for complete job flow + `job_status_history` table |
| **Phase 3** (Master Data) | ✅ Done | ✅ Has | `MasterDataAgent`, `master_data_seed.sql` for reference data |
| **Phase 4** (Procurement PR/PO/GR) | 🔄 In Progress | ✅ Complete | `PurchaseAgent` (187 lines), `modules/purchase/` (24 files) |
| **Phase 5** (Planning/Routes/Photos) | 🔄 In Progress | ✅ Complete | `PlanningAgent`, `booking_conflicts.php`, `select_equipment.php` |
| **Phase 6** (Return/WH) | ⏳ Planned | ✅ Has | `WarehouseAgent`, `modules/transport/return_trip.php` |
| **Phase 7** (POS/Maintenance) | ⏳ Planned | ✅ Complete | `MaintenanceAgent`, `modules/maintenance/` (12 files) |
| **Phase 8** (Accounting) | ⏳ Planned | ✅ Has | `AccountingAgent` (credit check), `modules/accounting/` |
| **Phase 9** (Line Notify) | ⏳ Planned | ⚠️ Partial | `logEvent()` pattern ready for webhook dispatch |

### Key Accelerators by Phase

**Phase 4 (Procurement):**
- Copy: `PurchaseAgent::createPR()`, `createPOFromPR()`, `recordGoodsReceipt()`
- Reference: Status flow DRAFT→PENDING→APPROVED→CONVERTED_TO_PO

**Phase 5 (Planning):**
- Copy: `booking_conflicts.php` (anti-double-booking with MySQL locks)
- Copy: `PlanningAgent::bookResource()` with conflict assertion
- Reference: `modules/planning/assign.php` (42KB complex assignment UI)

**Phase 6 (Return):**
- Reference: `modules/planning/return_trip.php` (40KB return workflow)
- Copy: `TransportAgent::updateShipmentStatus()` for delivery status

**Phase 8 (Accounting):**
- Copy: `AccountingAgent::getCustomerExposure()` for credit check
- Reference: Invoice workflow DRAFT→POSTED→PAID

---

## 4. DB Schema Insights

### Tables by Domain (inferred from agents)

#### 📋 Core/Audit (append-only)
| Table | Type | Notes |
|-------|------|-------|
| `audit_logs` | Append-only | user_id, action, entity_type, entity_id, details, ip_address |
| `domain_events` | Append-only | event_name, payload JSON |
| `system_logs` | Append-only | level, message, context JSON |
| `job_status_history` | Append-only | job_id, from_status, to_status, changed_by |

#### 👥 Master Data
| Table | Notes |
|-------|-------|
| `users` | Has role enum, password_hash |
| `customers` | credit_days, credit_limit for AR |
| `suppliers` | type: MATERIAL/EQUIPMENT/LABOR/LOGISTICS |
| `employees` | type: INTERNAL/EXTERNAL, position, status |
| `vehicles` | plate_number, type, status, driver_id |
| `items` | sku, type (DEVICE/EQUIPMENT/CONSUMABLE), current_stock |

#### 📦 Procurement
| Table | Notes |
|-------|-------|
| `purchase_requests` | pr_number, status, priority |
| `purchase_request_items` | pr_id, item_id, quantity, estimated_price |
| `purchase_orders` | po_number, pr_id, supplier_id, total_amount |
| `purchase_order_items` | po_id, item_id, unit_price |
| `goods_receipts` | gr_number, po_id, status (PARTIAL/FULL) |

#### 📅 Planning/Transport
| Table | Notes |
|-------|-------|
| `jobs` | job_number, customer_id, status, location, dates |
| `resource_bookings` | resource_type, resource_id, start_time, end_time, status |
| `job_material_requirements` | job_id, item_id, quantity |
| `shipments` | job_id, direction, vehicle_id, driver_id, pod_path |
| `shipment_items` | shipment_id, resource_booking_id |
| `transport_expenses` | job_id, vehicle_id, type, amount, status |

#### 💰 Accounting
| Table | Notes |
|-------|-------|
| `ar_invoices` | invoice_number, job_id, customer_id, status, due_date |
| `payments` | invoice_id, amount, payment_date, method |
| `document_numbering` | Configurable per document type |

### ✅ Good Patterns to Copy
1. **Append-only audit/events** - Never DELETE from `audit_logs`/`domain_events`
2. **Status history tables** - `job_status_history` pattern for all entities
3. **Soft deletes** - `status = 'CANCELLED'` instead of DELETE
4. **Credit exposure query** - `AccountingAgent::getCustomerExposure()`
5. **Booking conflict indexes** - `idx_conflict_check (resource_type, resource_id, start_time, end_time)`

### ⚠️ Dangerous Patterns to Avoid
1. **SQLite in production** - v3 uses SQLite; v2 correctly uses MySQL
2. **Inline sequence generation** - `generateSequence()` method has race conditions; use `document_numbering` table with `FOR UPDATE`
3. **Missing FK constraints** - Many v3 tables lack foreign keys (commented out in migrations)
4. **Inconsistent column naming** - `start_time`/`end_time` vs `start_date`/`end_date`
5. **No soft delete on employees/vehicles** - Direct status change without history

---

## 5. UI/UX Assets

### Complex Workflow Screens Worth Referencing

| File | Size | Description |
|------|------|-------------|
| `modules/planning/assign.php` | 42KB | Full resource assignment UI with drag-drop |
| `modules/planning/return_trip.php` | 40KB | Complex return workflow with checkpoints |
| `modules/planning/index.php` | 25KB | Planning dashboard with calendar |
| `modules/planning/book_resources_staging.php` | 16KB | Staging area before commit |
| `modules/planning/select_equipment.php` | 13KB | Equipment picker with availability |
| `modules/purchase/` | 24 files | Complete PR/PO/GR workflow screens |
| `modules/maintenance/` | 12 files | Maintenance request workflow |
| `dashboard_views/` | 10 files | Role-specific dashboards |

### UI Components to Port
| Component | Location | Purpose |
|-----------|----------|---------|
| Searchable Combo | `assets/js/searchable-combo.js` | Autocomplete dropdown |
| Toast Notifications | `assets/js/toast.js` | User feedback |
| Enterprise CSS | `assets/css/enterprise-design-system.css` | Consistent styling |

---

## Summary

### Immediate Actions (This Sprint)
1. ✅ Copy `booking_conflicts.php` for Phase 5 planning
2. ✅ Copy `GLOSSARY.md` for team reference
3. ✅ Reference `WorkflowEngine.php` to extend v2's `StatusMachine.php`

### Near-term (Phase 4-5)
4. Port `PurchaseAgent` methods for PR/PO/GR
5. Port `PlanningAgent::bookResource()` with conflict detection
6. Reference `modules/planning/assign.php` for UI patterns

### Later Phases (6-9)
7. Port `TransportAgent` for Phase 6 shipments
8. Port `AccountingAgent` credit checks for Phase 8
9. Reference `MaintenanceAgent` for Phase 7

---

**Report End**
