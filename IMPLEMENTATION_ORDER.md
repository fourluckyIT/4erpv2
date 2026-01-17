# Implementation Order: Legacy Logic Reuse
**Generated:** 2026-01-17 10:52 (UTC+7)

## Execute in exact order (1 -> 6).

### 1. Database Schema (The Foundation)
**Goal:** Ensure all tables exist before any PHP code runs.
- [ ] **Backup:** Run `mysqldump` to snapshot current v2 DB.
- [ ] **Phase 5 Schema:** Apply `sql/schema_phase5_v2.sql` (if not fully applied).
- [ ] **Audit/Events:** Verify `audit_logs`, `domain_events` tables exist.
- [ ] **Accounting:** Create tables `ar_invoices`, `payments` (use `ON DELETE RESTRICT` for FKs).
- [ ] **Rollback Plan:** `mysql < backup_v2.sql`.

### 2. Core Utilities (The Glue)
**Goal:** Common libraries used by all modules.
- [ ] **Copy:** `includes/booking_conflicts.php` (from legacy audit).
- [ ] **Refactor:** Update `includes/StatusMachine.php` to support legacy transition logic (Job/PR/PO).
- [ ] **Refactor:** Ensure `DocumentNumber.php` reads from DB config (if adopting legacy pattern).

### 3. M1 & M2: Planning & Procurement Layers
**Goal:** Resource booking and material acquisition.
- [ ] **Service:** Update `PlanningService` to use `booking_conflicts.php`.
- [ ] **Verify:** Run concurrency test (simulate double booking).
- [ ] **Service:** Port `PurchaseAgent` methods to `ProcurementService`.
- [ ] **Verify:** Create PR -> PO -> GR flow via API/Script.

### 4. M3 & M4: Operations & Logistics Layers
**Goal:** Physical movement and evidence.
- [ ] **Service:** Update `RouteService` to enforce 4 photos at transitions (DISPATCH, RECEIVE).
- [ ] **Service:** Port `TransportAgent`/`WarehouseAgent` logic for Returns.
- [ ] **Verify:** Complete a route lifecycle with photo uploads.

### 5. M5: Accounting Layer
**Goal:** Financials.
- [ ] **Service:** Port `AccountingAgent` logic for Invoice generation.
- [ ] **Verify:** Convert extensive Job to Invoice.

### 6. End-to-End Validation
- [ ] **Browser Test:** Execute one full "Happy Path":
   Job -> Plan -> Route -> Photo(Dispatch) -> Photo(Receive) -> Return -> WH Receive -> POS Check -> Invoice -> Pay.

---

## 🛑 Important Rules
1. **No Deletes:** Never run `DELETE` on financial or inventory tables. Use Soft Delete (`status='CANCELLED'`).
2. **Audit Always:** Every write operation MUST populate `audit_logs`.
3. **UTC+7:** All display times must use Thai context; Storage can be UTC or Local (be consistent).
4. **Backup First:** Always dump DB before applying schema changes.
