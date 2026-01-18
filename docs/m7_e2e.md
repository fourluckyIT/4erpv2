# M7: End-to-End Test

**Status**: Implemented & Verified  
**Date**: 2026-01-18

## Summary

Comprehensive E2E test validating the complete Minimum Working ERP flow per `agents.md`.

## Test Coverage

| Step | Module | Assertions |
|------|--------|------------|
| 1-5 | Job Lifecycle | Draft → Submitted → Approved → Planned |
| 6 | Conflict Guard (M1) | Resource booking + double-booking detection |
| 7 | Procurement (M2) | PR → PO → GR + stock increase |
| 8 | Route/Evidence (M3) | 4-photo enforcement + dispatch + status history |
| 9 | Warehouse (M4) | Stock movements + reversal integrity |
| 10 | Accounting (M5) | Invoice → Issue → Payments → Reverse + immutability |
| 11 | RBAC (M6) | Deny/Allow matrix (WH/ACC/PLN/PUR/MGR/ADM) |
| 12 | Audit | Trail verification across entities |

## Test Results

```
=== MANUAL E2E TEST: Minimum Working ERP ===
Test Prefix: E2E-20260118133931-

[Step 1] Setup: Get or create test customer
  [PASS] Using existing customer ID: 1

[Step 2] Create Job in Draft status
  [PASS] Job created: E2E-xxx-JOB (ID: 41)

[Step 3] Job Status: Draft -> Submitted
  [PASS] Job status = Submitted

[Step 4] Job Status: Submitted -> Approved (MGR action)
  [PASS] Job status = Approved

[Step 5] Job Status: Approved -> Planned (PLN action)
  [PASS] Job status = Planned

[Step 6] Conflict Guard: Book a serial, then attempt double-booking
  [PASS] Resource already booked (expected in re-runs)
  [PASS] Conflict correctly detected on double-booking

[Step 7] Procurement: Create PR -> Approve -> PO -> GR
  [PASS] PR created: PR-2026-00012
  [PASS] PR approved
  [PASS] PO created: PO-2026-00010
  [PASS] GR created: GR-2026-00009
  [PASS] Stock increased: 70 -> 75

[Step 8] Route: Create, upload photos, dispatch
  [PASS] Route created: ID 37
  [PASS] 4 Dispatch photos uploaded
  [PASS] Route dispatched
  [PASS] Route status history recorded

[Step 9] Warehouse: Record movements, test reversal
  [PASS] Dispatch movement recorded: ID 17
  [PASS] Reversal recorded: ID 18
  [PASS] Reversal integrity verified

[Step 10] Accounting: Invoice -> Issue -> Payments -> Reverse
  [PASS] Invoice created: INV-2026-00003
  [PASS] Invoice issued
  [PASS] Partial payment: PAY-2026-00007
  [PASS] Balance after partial: 4490
  [PASS] Final payment: PAY-2026-00008
  [PASS] Invoice status = Paid
  [PASS] Payment reversed
  [PASS] Original payment preserved (immutability)

[Step 11] RBAC: Verify deny/allow matrix
  [PASS] WH denied INVOICE_ISSUE
  [PASS] ACC denied WH_RECEIVE
  [PASS] PLN allowed ROUTE_DISPATCH
  [PASS] PUR allowed PO_CREATE
  [PASS] MGR allowed JOB_VOID
  [PASS] ADM allowed all

[Step 12] Audit: Verify trail exists for key actions
  [PASS] Audit trail has 53 records

==================================================
=== E2E TEST COMPLETE ===
Steps: 12
Passed: 34
Failed: 0
==================================================
RESULT: PASS
```

## Regression Tests

All module tests passing:
- M1: Route Conflict → PASS
- M2: Procurement → PASS
- M3: Route Execution → PASS
- M4: Warehouse → 7/7 PASS
- M5: Accounting → 7/7 PASS
- M6: RBAC → 22/22 PASS

## agents.md Compliance

| Rule | Compliance |
|------|------------|
| No DELETE/TRUNCATE | ✅ Verified |
| Append-only ledgers | ✅ Stock movements, payments |
| Reversal pattern | ✅ Tested in M4, M5 |
| Immutability | ✅ Original records preserved |
| Audit trail | ✅ 53+ records verified |
| RBAC enforcement | ✅ Deny/allow matrix tested |

## Run Command

```bash
php tests/manual_e2e.php
# Expected: Exit 0
```
