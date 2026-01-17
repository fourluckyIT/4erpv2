# M3: Execution & Evidence Implementation

## 1. Environment / Context
- **Branch**: legacy-implement
- **DB**: erp_v2
- **Status**: Implemented & Verified

## 2. Sequence Log

### Step 1: Schema Application
- Applied `sql/schema_m3_photos.sql`.
- Added `route_status_history` table.
- Verified `evidence_photos` table exists.

### Step 2: Service Implementation (Route.php)
- **`addPhoto`**: Handle photo uploads (simulated file storage) and DB record.
- **`getPhotoCount`**: Enforce quantity check.
- **`transitionStatus`**:
    - **Linear Flow**: Confirmed -> Dispatched -> InProgress -> Returned -> WHReceived.
    - **Enforcement**: Blocks transition if photo count < 4 for 'Dispatched' and 'Returned'.
    - **Audit**: Writes to `route_status_history` and `audit_logs` (Transaction safe).

### Step 3: Verification (Manual Script)
Executed `php tests/manual_route_execution_m3.php`

**Result Summary:**
```text
[Step 1] Creating Route...
Route Created: ID 28

[Step 2] Try DISPATCH without photos...
[PASS] Blocked: Need 4 Dispatch photos (Current: 0)

[Step 4] Retry DISPATCH (After 4 uploads)...
[PASS] Dispatched successfully.

[Step 5] Verifying History...
[PASS] History record found.

[Step 6] Try RETURN without photos...
[PASS] Blocked: Need 4 Return photos (Current: 0)

[Step 7] Upload 3 Return photos...
[PASS] Blocked with 3 photos (Current: 3)

[Step 8] Upload 4th photo and Return...
[PASS] Returned successfully.
```

## 3. Proof Checklist (Verified)
- [x] **Schema**: `route_status_history` created.
- [x] **Dispatch Rule**: Cannot Dispatch without 4 photos.
- [x] **Return Rule**: Cannot Return without 4 photos.
- [x] **Counting**: Counts by `event_type` (Dispatch photos don't count for Return status).
- [x] **History**: Transitions logged in `route_status_history`.
- [x] **Audit**: Transitions logged in `audit_logs`.

## 4. Key Logic
```php
public function canTransition(int $routeId, string $toStatus): array {
    if ($toStatus === 'Dispatched') {
        if ($this->getPhotoCount($routeId, 'Dispatch') < 4) {
            return ['allowed' => false, 'reason' => "Need 4 Dispatch photos"];
        }
    }
    // ...
}
```
