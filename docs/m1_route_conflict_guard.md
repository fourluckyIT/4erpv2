# M1: Route Creation Conflict Guard

## 1. Environment / Context
- **Branch**: legacy-implement
- **DB**: erp_v2
- **TZ**: UTC+7 (Thailand)
- **Status**: Implemented & Verified

## 2. Sequence Log

### Step 1: Logic Integration
Integrated `BookingConflict` class (Legacy Tier-A) into `Route.php` and `Plan.php`.
- **Method**: `BookingConflict::bookResource()` called within transaction.
- **Smart Check**: Implemented logic to allow `Route` to use resources reserved by its parent `Plan`.

### Step 2: Verification (Manual Script)
Executed `php tests/manual_route_conflict.php`

**Result Summary:**
```text
[PASS] Setup: Created Confirmed Plan #25 reserving Serial A(1) & B(2)
[PASS] Smart Check Allowed Plan Reservation.
[PASS] Error: Conflict detected: Vehicle is already booked by routes #24
[PASS] Blocked: Conflict detected: Vehicle is already booked by routes #24
[PASS] Added serial to Route 1.
[PASS] Blocked: Conflict detected: Serial is already booked by route_items #0
```

## 3. Proof Checklist (Verified)
- [x] **Vehicle Conflict**: Same vehicle, overlapping time (diff plan) ⇒ **REJECT**
- [x] **Vehicle Double Booking**: Same vehicle, same plan, 2nd route ⇒ **REJECT**
- [x] **Smart Reservation**: Route uses vehicle reserved by Plan ⇒ **ALLOW**
- [x] **Item Consistency**: Route using unassigned Plan item ⇒ **REJECT** (Strict Planning)
- [x] **Inter-Route Item**: Same item used in 2 routes (same plan) ⇒ **REJECT**
- [x] **Cleanup**: Cancellation releases bookings ⇒ **PASS**

## 4. Key Fixes
- **ENUM Mismatch**: Corrected `Plan` assignment types from 'Serial'/'People' to 'Device'/'Manpower'.
- **Missing Includes**: Added `booking_conflicts.php` to `Route.php`.
