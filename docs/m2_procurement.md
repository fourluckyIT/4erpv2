# M2: Procurement & Inventory Implementation

## 1. Environment / Context
- **Branch**: legacy-implement
- **DB**: erp_v2
- **Status**: Implemented & Verified

## 2. Sequence Log

### Step 1: Schema Application
- Applied `sql/schema_phase4.sql` (PR, PO, GR tables).
- Applied `sql/schema_m2_stock.sql` (Added `quantity` column to `items` table for inventory tracking).

### Step 2: Service Implementation
- Ported `4erpv3-legacy/includes/classes/PurchaseAgent.php` to `modules/procurement/ProcurementService.php`.
- Refactored `DocumentNumber.php` to support nested transactions (critical fix for `createPR` inside transaction).

### Step 3: Verification (Manual Script)
Executed `php tests/manual_procurement_flow.php`

**Result Summary:**
```text
=== Starting Procurement Verification ===
Created Test Item: TEST-CON-001 (ID: 2006) Stock: 0
Created Test Supplier: Test Supplier Co (ID: 5)

[Step 1] Creating PR...
PR Created: PR-2026-00004

[Step 2] Approving PR...
PR Approved.

[Step 3] Creating PO...
PO Created: PO-2026-00002

[Step 4] Recording GR (Receive 10)...
GR Created: GR-2026-00001

[Step 5] Verifying Stock...
New Stock Level: 10.00
[PASS] Stock increased correctly.
```

## 3. Proof Checklist (Verified)
- [x] **Schema**: Tables `purchase_requests`, `purchase_orders`, `goods_receipts` exist.
- [x] **Inventory**: `items` table has `quantity` column.
- [x] **Flow**: PR -> Approve -> PO -> GR works end-to-end.
- [x] **Stock Update**: Recording GR increases item stock.
- [x] **Transactions**: Nested transaction handling in `DocumentNumber` prevents crashes.

## 4. Key Fixes
- **Nested Transactions**: Modified `core/DocumentNumber.php` to respect existing transactions.
- **SQL Binding**: Fixed parameter reuse issue in `ProcurementService::recordGR`.
