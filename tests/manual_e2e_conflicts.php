<?php
/**
 * E2E Scenario: Conflict Matrix Tests
 * Tests double-booking detection for Vehicle, Serial, and Manpower
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/booking_conflicts.php';

$TEST_PREFIX = 'CF' . date('Hi') . '-';
$TEST_USER_ID = getenv('TEST_USER_ID') ?: 1;
$_SESSION['user_id'] = $TEST_USER_ID;
$_SESSION['roles'] = ['ADM'];

$db = getDB();
$stepNum = 0;
$passed = 0;
$failed = 0;

function step($desc) { global $stepNum; $stepNum++; echo "\n[Step $stepNum] $desc\n"; }
function pass($msg) { global $passed; echo "  [PASS] $msg\n"; $passed++; }
function fail($msg) { global $failed; echo "  [FAIL] $msg\n"; $failed++; echo "\n=== ABORTED ===\n"; exit(1); }
function check($cond, $passMsg, $failMsg) { if ($cond) pass($passMsg); else fail($failMsg); }

echo "=== E2E CONFLICT MATRIX SCENARIO ===\n";
echo "Prefix: $TEST_PREFIX\n";

try {
    $conflict = new BookingConflict($db);
    $tomorrow = date('Y-m-d', strtotime('+2 days')); // Use +2 days to avoid previous test conflicts
    
    // Create two jobs for conflict testing
    step("Create two test jobs for conflict scenarios");
    $stmt = $db->prepare("SELECT id FROM customers LIMIT 1");
    $stmt->execute();
    $customerId = $stmt->fetchColumn() ?: 1;
    
    $db->prepare("INSERT INTO jobs (job_number, customer_id, job_type, status, scope_short,
                  plan_start_date, plan_end_date, owner_sale_id, owner_planner_id, created_by)
                  VALUES (?, ?, 'Lumpsum', 'Planned', ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?, ?, ?)")
       ->execute([$TEST_PREFIX.'JOB-A', $customerId, 'Conflict Test A', $TEST_USER_ID, $TEST_USER_ID, $TEST_USER_ID]);
    $jobA = $db->lastInsertId();
    
    $db->prepare("INSERT INTO jobs (job_number, customer_id, job_type, status, scope_short,
                  plan_start_date, plan_end_date, owner_sale_id, owner_planner_id, created_by)
                  VALUES (?, ?, 'Lumpsum', 'Planned', ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?, ?, ?)")
       ->execute([$TEST_PREFIX.'JOB-B', $customerId, 'Conflict Test B', $TEST_USER_ID, $TEST_USER_ID, $TEST_USER_ID]);
    $jobB = $db->lastInsertId();
    
    check($jobA > 0 && $jobB > 0, "Created Job A ($jobA) and Job B ($jobB)", "Job creation failed");
    
    // ========================================
    // TEST 1: Serial Conflict
    // ========================================
    step("Test Serial double-booking conflict");
    $stmt = $db->prepare("SELECT id FROM serials WHERE status = 'Active' LIMIT 1");
    $stmt->execute();
    $serialId = $stmt->fetchColumn() ?: 1;
    
    // Book serial for Job A
    $bookingId = $conflict->bookResource('Serial', $serialId, "$tomorrow 08:00:00", "$tomorrow 17:00:00", 'jobs', $jobA);
    check($bookingId > 0, "Serial booked for Job A: Booking $bookingId", "Booking failed");
    
    // Try to book same serial for Job B (should conflict)
    $hasConflict = $conflict->hasConflict('Serial', $serialId, "$tomorrow 08:00:00", "$tomorrow 17:00:00", $jobB);
    check($hasConflict, "Serial conflict detected for Job B (DENY)", "Conflict not detected");
    
    // Non-overlapping time should NOT conflict
    $nextDay = date('Y-m-d', strtotime($tomorrow . ' +1 day'));
    $noConflict = !$conflict->hasConflict('Serial', $serialId, "$nextDay 08:00:00", "$nextDay 17:00:00", $jobB);
    check($noConflict, "No conflict for non-overlapping time (ALLOW)", "False positive conflict");
    
    // ========================================
    // TEST 2: Vehicle Conflict (if vehicles table exists)
    // ========================================
    step("Test Vehicle double-booking conflict");
    $stmt = $db->query("SHOW TABLES LIKE 'vehicles'");
    if ($stmt->fetchColumn()) {
        $stmt = $db->prepare("SELECT id FROM vehicles WHERE active = 1 LIMIT 1");
        $stmt->execute();
        $vehicleId = $stmt->fetchColumn();
        
        if ($vehicleId) {
            $vBookId = $conflict->bookResource('Vehicle', $vehicleId, "$tomorrow 08:00:00", "$tomorrow 17:00:00", 'jobs', $jobA);
            check($vBookId > 0, "Vehicle booked for Job A: Booking $vBookId", "Vehicle booking failed");
            
            $vConflict = $conflict->hasConflict('Vehicle', $vehicleId, "$tomorrow 09:00:00", "$tomorrow 12:00:00", $jobB);
            check($vConflict, "Vehicle conflict detected (overlapping hours)", "Vehicle conflict not detected");
        } else {
            pass("No active vehicles found (skipped)");
        }
    } else {
        pass("Vehicles table not present (skipped)");
    }
    
    // ========================================
    // TEST 3: Partial Overlap Detection
    // ========================================
    step("Test partial time overlap detection");
    $stmt = $db->prepare("SELECT id FROM serials WHERE status = 'Active' AND id != ? LIMIT 1");
    $stmt->execute([$serialId]);
    $serial2Id = $stmt->fetchColumn();
    
    if ($serial2Id) {
        // Book 08:00-12:00
        $conflict->bookResource('Serial', $serial2Id, "$tomorrow 08:00:00", "$tomorrow 12:00:00", 'jobs', $jobA);
        
        // Check overlap: 10:00-14:00 (overlaps with 08:00-12:00)
        $overlap = $conflict->hasConflict('Serial', $serial2Id, "$tomorrow 10:00:00", "$tomorrow 14:00:00", $jobB);
        check($overlap, "Partial overlap 10:00-14:00 detected", "Partial overlap not detected");
        
        // Check no-overlap: 13:00-17:00 (after 12:00)
        $noOverlap = !$conflict->hasConflict('Serial', $serial2Id, "$tomorrow 13:00:00", "$tomorrow 17:00:00", $jobB);
        check($noOverlap, "No overlap 13:00-17:00 allowed", "False positive on non-overlap");
    } else {
        pass("Only one serial available (partial overlap skipped)");
    }
    
    // ========================================
    // TEST 4: Booking Cancellation
    // ========================================
    step("Test booking cancellation releases resource");
    $stmt = $db->prepare("SELECT id FROM serials WHERE status = 'Active' LIMIT 1 OFFSET 2");
    $stmt->execute();
    $serial3Id = $stmt->fetchColumn();
    
    if ($serial3Id) {
        $dayAfter = date('Y-m-d', strtotime($tomorrow . ' +2 days'));
        $cancelBookId = $conflict->bookResource('Serial', $serial3Id, "$dayAfter 08:00:00", "$dayAfter 17:00:00", 'jobs', $jobA);
        
        // Cancel booking
        $conflict->cancelBooking($cancelBookId);
        
        // Now should be available (no conflict)
        $released = !$conflict->hasConflict('Serial', $serial3Id, "$dayAfter 08:00:00", "$dayAfter 17:00:00", $jobB);
        check($released, "Cancelled booking releases resource", "Resource still blocked after cancel");
    } else {
        pass("Not enough serials for cancellation test (skipped)");
    }
    
    // ========================================
    // TEST 5: Same-day Multi-booking Check
    // ========================================
    step("Test same-day multi-resource booking");
    $stmt = $db->prepare("SELECT id FROM serials WHERE status = 'Active' ORDER BY id LIMIT 3");
    $stmt->execute();
    $serials = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($serials) >= 2) {
        $futureDays = date('Y-m-d', strtotime('+5 days'));
        
        // Book multiple serials for same job on same day
        $b1 = $conflict->bookResource('Serial', $serials[0], "$futureDays 08:00:00", "$futureDays 17:00:00", 'jobs', $jobA);
        $b2 = $conflict->bookResource('Serial', $serials[1], "$futureDays 08:00:00", "$futureDays 17:00:00", 'jobs', $jobA);
        
        check($b1 > 0 && $b2 > 0, "Multiple resources booked for same job", "Multi-booking failed");
        
        // Different resources should not conflict
        $c1 = $conflict->hasConflict('Serial', $serials[0], "$futureDays 08:00:00", "$futureDays 17:00:00", $jobB);
        $c2 = $conflict->hasConflict('Serial', $serials[1], "$futureDays 08:00:00", "$futureDays 17:00:00", $jobB);
        check($c1 && $c2, "Both resources show conflict for Job B", "Multi-resource conflict failed");
    } else {
        pass("Not enough serials for multi-booking test (skipped)");
    }
    
    // ========================================
    // TEST 6: Get Conflicts Detail
    // ========================================
    step("Test getConflicts returns booking details");
    $conflicts = $conflict->getConflicts('Serial', $serialId, "$tomorrow 08:00:00", "$tomorrow 17:00:00");
    check(count($conflicts) >= 1, "getConflicts returned " . count($conflicts) . " conflict(s)", "No conflicts returned");
    
    if (count($conflicts) > 0) {
        $firstConflict = $conflicts[0];
        check(isset($firstConflict['id']), "Conflict has id field", "Missing id field");
    }
    
    // Summary
    echo "\n" . str_repeat("=", 40) . "\n";
    echo "=== CONFLICT MATRIX COMPLETE ===\n";
    echo "Passed: $passed, Failed: $failed\n";
    echo "RESULT: PASS\n";
    exit(0);

} catch (Exception $e) {
    echo "\n[FATAL] " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
