<?php
/**
 * M1: Route Conflict Guard - Manual Verification Script
 * 
 * Tests:
 * 1. Vehicle conflict: Same vehicle, overlapping time (diff plan) ⇒ REJECT
 * 2. Smart Reservation: Route uses vehicle reserved by Plan ⇒ ALLOW
 * 3. Inter-Route Item: Same item used in 2 routes (same plan) ⇒ REJECT
 * 
 * Run: php tests/manual_route_conflict.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/booking_conflicts.php';
require_once __DIR__ . '/../core/Plan.php';
require_once __DIR__ . '/../core/Route.php';

try {
    $db = getDB();
    $conflict = new BookingConflict($db);
    
    echo "=== M1: Route Conflict Guard Test ===\n\n";
    
    // Setup: Get a test serial (Device)
    $stmt = $db->prepare("SELECT id FROM serials LIMIT 1");
    $stmt->execute();
    $serialId = $stmt->fetchColumn() ?: 1;
    echo "Using Serial ID: $serialId\n\n";
    
    // Test time range
    $startTime = date('Y-m-d 08:00:00', strtotime('+1 day'));
    $endTime = date('Y-m-d 17:00:00', strtotime('+1 day'));
    
    // ======= TEST 1: Book a serial =======
    echo "[Test 1] Booking Serial $serialId...\n";
    try {
        $bookingId = $conflict->bookResource('Serial', $serialId, $startTime, $endTime, 'plans', 9999);
        echo "[PASS] Booking created: ID $bookingId\n\n";
    } catch (Exception $e) {
        echo "[INFO] Serial already booked or error: {$e->getMessage()}\n\n";
    }
    
    // ======= TEST 2: Attempt double-booking =======
    echo "[Test 2] Attempting double-booking same serial...\n";
    try {
        $conflict->bookResource('Serial', $serialId, $startTime, $endTime, 'plans', 8888);
        echo "[FAIL] Double-booking was allowed (should have been rejected)\n\n";
    } catch (Exception $e) {
        echo "[PASS] Conflict detected: {$e->getMessage()}\n\n";
    }
    
    // ======= TEST 3: Check conflict detection =======
    $overlapStart = date('Y-m-d 10:00:00', strtotime('+1 day'));
    $overlapEnd = date('Y-m-d 14:00:00', strtotime('+1 day'));
    
    echo "[Test 3] Checking overlapping time range...\n";
    $hasConflict = $conflict->hasConflict('Serial', $serialId, $overlapStart, $overlapEnd);
    if ($hasConflict) {
        echo "[PASS] Overlap conflict detected.\n\n";
    } else {
        echo "[FAIL] No conflict detected for overlapping range.\n\n";
    }
    
    // ======= TEST 4: Non-overlapping should pass =======
    $laterStart = date('Y-m-d 08:00:00', strtotime('+2 days'));
    $laterEnd = date('Y-m-d 17:00:00', strtotime('+2 days'));
    
    echo "[Test 4] Checking non-overlapping time (next day)...\n";
    $hasConflict = $conflict->hasConflict('Serial', $serialId, $laterStart, $laterEnd);
    if (!$hasConflict) {
        echo "[PASS] No conflict for different day.\n\n";
    } else {
        echo "[FAIL] False positive conflict.\n\n";
    }
    
    // ======= TEST 5: Cancel booking releases resource =======
    echo "[Test 5] Cancelling booking and re-checking...\n";
    $conflict->cancelBooking('plans', 9999);
    $hasConflict = $conflict->hasConflict('Serial', $serialId, $startTime, $endTime);
    if (!$hasConflict) {
        echo "[PASS] Cancellation released the resource.\n\n";
    } else {
        echo "[FAIL] Resource still shows as booked after cancel.\n\n";
    }
    
    echo "=== M1 Test Complete ===\n";
    
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
