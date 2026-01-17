# M4: Returns & Warehouse Implementation

## 1. Environment / Context
- **Branch**: legacy-implement
- **DB**: erp_v2
- **Status**: Implemented & Verified

## 2. Sequence Log

### Step 1: Schema Application
- Applied `sql/schema_m4_warehouse.sql`.
- Added `stock_movements` table (Tracks `GI_JOB`, `GR_PO`, `RETURN_JOB`, `ADJUSTMENT`).

### Step 2: Service Implementation (WarehouseService.php)
- **`recordStockMovement`**: Atomically updates `items.quantity` and logs to `stock_movements`.
- **`receiveReturn`**:
    - Validates Route is in `Returned` status.
    - Transitions Route to `WHReceived` (via `Route::transitionStatus` for history consistency).
    - Restocks returned items (quantity increase).

### Step 3: Verification (Manual Script)
Executed `php tests/manual_warehouse_flow.php`

**Result Summary:**
```text
[Step 1] Creating Route & Moving to Returned...
Route Status: Returned

[Step 2] Processing WH Receive (Return 5 items)...
WH Receive Success.

[Step 3] Verifying Route Status...
New Status: WHReceived
[PASS] Route is WHReceived.

[Step 4] Verifying Stock...
New Stock: 55.00 (Expected 55)
[PASS] Stock increased correctly.

[Step 5] Checking Movement Log...
[PASS] Log found: Type=RETURN_JOB, Qty=5.00
```

## 3. Proof Checklist (Verified)
- [x] **Schema**: `stock_movements` table active.
- [x] **Stock Management**: Inventory increases upon `RETURN_JOB`.
- [x] **Route Workflow**: `Returned` -> `WHReceived` status enforced.
- [x] **Logging**: All stock changes have trace in `stock_movements`.
