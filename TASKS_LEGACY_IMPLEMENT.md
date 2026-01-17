# Legacy Implementation Tasks & Milestones
**Generated:** 2026-01-17 10:52 (UTC+7)
**Branch:** `legacy-implement`
**Goal:** ERP usable end-to-end ("ครบ Process") for real operations.

---

## 1. Actionable Legacy Reuse Summary (Tier A & Key B)

| Tier | Asset | Reuse Strategy | Key Phase |
|---|---|---|---|
| **A** | `booking_conflicts.php` | **Copy as-is** to `includes/`. Critical for preventing double-booking in M1. | Phase 5 |
| **A** | `WorkflowEngine.php` | **Refactor** logic into v2 `StatusMachine`. Merge job/pr/po/invoice transitions. | All |
| **A** | `BaseAgent.php` | **Adopt Pattern**. Implement `logEvent` (domain) and `logAudit` (tech) in v2 BaseService. | All |
| **A** | `DocumentNumbering.php` | **Refactor** to support configurable formats (e.g. `JOB-2601-001`) via DB. | Phase 1/All |
| **B** | `PurchaseAgent.php` | **Port** `createPR`, `createPO`, `recordGR` logic to `ProcurementService`. | Phase 4 (M2) |
| **B** | `PlanningAgent.php` | **Port** `bookResource` with conflict checks. | Phase 5 (M1) |
| **B** | `TransportAgent.php` | **Port** logic for shipments/POD/Expenses. | Phase 6 (M4) |
| **B** | `WarehouseAgent.php` | **Port** stock movement logic (GI/GR). | Phase 6 (M4) |
| **B** | `AccountingAgent.php` | **Port** credit limit checks & invoice generation. | Phase 8 (M5) |

---

## 2. Milestones (End-to-End Flow)

### M1: Core Foundation & Planning (Phase 5)
**Scope:** Job Creation → Planning (Manpower/Serialized/Consumable).
**Goal:** Reliable booking with conflict resolution.
- **Inputs:** `booking_conflicts.php`, `PlanningAgent.php`.
- **DB Changes:**
  - Add `resource_bookings` table (if missing) with composite index for conflict checks.
  - Add `job_material_requirements`.
- **Files:**
  - `includes/booking_conflicts.php` (New)
  - `modules/planning/PlanningService.php` (Port logic)
- **Tests:**
  - [Auto] Attempt double-booking same resource/time -> Expect Failure.
  - [Manual] Create Job -> Plan valid resources -> Verify Status 'PLANNED'.
- **Risks:** Locking mechanisms might behave differently on MySQL if not testing concurrency correctly.

### M2: Procurement & Inventory (Phase 4)
**Scope:** Consumable shortage → PR → PO → GR → Stock increase.
**Goal:** Ensure materials are available for jobs.
- **Inputs:** `PurchaseAgent.php`.
- **DB Changes:**
  - `purchase_requests`, `purchase_orders`, `goods_receipts` (verify/add).
  - Enforce `ON DELETE RESTRICT` on inventory tables.
- **Files:**
  - `modules/procurement/ProcurementService.php`
- **Tests:**
  - [Auto] PR -> PO -> GR workflow.
  - [Manual] Verify stock level increases after GR.
- **Risks:** v2 `purchase` module path collision with legacy structure.

### M3: Execution & Evidence (Phase 5)
**Scope:** Route → Dispatch → Deliver/Install → In Progress.
**Goal:** Operations with enforced photo evidence (4 photos).
- **Inputs:** `WorkflowEngine.php` (Transitions).
- **DB Changes:** `route_status_history`, `evidence_photos`.
- **Files:**
  - `modules/routes/RouteService.php`
  - `includes/StatusMachine.php` (Update)
- **Tests:**
  - [Auto] Try transition to DISPATCH without photos -> Fail.
  - [Manual] Upload 4 photos -> Transition -> Verify timestamps.
- **Risks:** Photo upload size limits/timeouts.

### M4: Returns & Warehouse (Phase 6)
**Scope:** Return Trip (4 photos) → WH Received -> Stock Return.
**Goal:** Close the loop on inventory.
- **Inputs:** `TransportAgent.php`, `WarehouseAgent.php`.
- **DB Changes:** `shipments` (return type).
- **Files:**
  - `modules/warehouse/WarehouseService.php`
- **Tests:**
  - [Manual] Complete Return -> Verify item stock added back to WH.
- **Risks:** Handling damaged goods vs active stock.

### M5: Financial Closure (Phase 7/8)
**Scope:** Job Closed -> POS Check (4 photos) -> Invoice -> Payment.
**Goal:** Revenue realization.
- **Inputs:** `AccountingAgent.php`.
- **DB Changes:** `ar_invoices`, `payments`.
- **Files:**
  - `modules/accounting/AccountingService.php`
- **Tests:**
  - [Auto] Invoice generation from Job.
  - [Manual] Partial Payment -> Verify Balance Remaining.
- **Risks:** Rounding errors in partial payments.

### M6: End-to-End Integration
**Scope:** Full flow validation.
**Goal:** "Green" light for operations.
- **Tasks:**
  - Full regression test.
  - Performance check on query heavy dashboards.

---

## 3. Prioritized Task List

### Setup & Schema
- [ ] **[M1]** Import `booking_conflicts.php` to `includes/`.
- [ ] **[M1]** Apply Schema Schema Phase 5 (Planning/Routes).
- [ ] **[M2]** Apply Schema Phase 4 (Procurement).
- [ ] **[M5]** Create/Verify Accounting Tables (`ar_invoices`, `payments`).

### Backend Implementation
- [ ] **[M1]** Integrate `booking_conflicts` into `PlanningService`.
- [ ] **[M3]** Enhance `StatusMachine.php` with `WorkflowEngine` logic for mandatory photo checks.
- [ ] **[M2]** Implement `ProcurementService` (PR->PO->GR).
- [ ] **[M4]** Implement `WarehouseService` (Stock Movement).
- [ ] **[M5]** Implement `AccountingService` (Invoice/Payment).

### Validation
- [ ] **[M1]** Browser Test: Conflict Booking.
- [ ] **[M3]** Browser Test: Photo Enforcement (Route Dispatch).
- [ ] **[M6]** Browser Test: End-to-End Flow (Job to Payment).

---

## 4. Phase Mapping
- **Current Branch:** `legacy-implement`
- **v2 Phase 4 (Procurement):** Maps to M2.
- **v2 Phase 5 (Routes):** Maps to M1 & M3.
- **New Logic:** Maps to M4 & M5 (ported from v3 legacy).
