<?php
/**
 * M3: Route Execution & Evidence - Manual Verification Script
 * 
 * Tests:
 * 1. Route cannot Dispatch without 4 photos
 * 2. Route can Dispatch after 4 photos uploaded
 * 3. Route cannot Return without 4 Return photos
 * 4. Status history is recorded
 * 
 * Run: TEST_USER_ID=1 php tests/manual_route_execution_m3.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../core/Route.php';
require_once __DIR__ . '/../core/EvidencePhoto.php';

// Setup test session
$_SESSION['user_id'] = getenv('TEST_USER_ID') ?: 1;
$_SESSION['username'] = 'test_user';
$_SESSION['roles'] = ['ADM'];

try {
    $db = getDB();
    $route = new Route();
    $photo = new EvidencePhoto();
    
    echo "=== M3: Route Execution & Evidence Test ===\n\n";
    
    // Setup: Find or create a confirmed route
    $stmt = $db->prepare("SELECT id FROM routes WHERE status = 'Confirmed' LIMIT 1");
    $stmt->execute();
    $routeId = $stmt->fetchColumn();
    
    if (!$routeId) {
        echo "[Setup] No Confirmed route found. Creating test route...\n";
        
        // Get a plan (routes use plan_id, not job_id)
        $stmt = $db->prepare("SELECT id FROM plans LIMIT 1");
        $stmt->execute();
        $planId = $stmt->fetchColumn() ?: 1;
        
        // Generate test route number
        $routeNumber = 'TEST-RT-' . date('YmdHis');
        
        // Create route directly for testing (using plan_id)
        $db->prepare("
            INSERT INTO routes (route_number, plan_id, route_date, status, created_by, created_at)
            VALUES (?, ?, CURDATE(), 'Confirmed', ?, NOW())
        ")->execute([$routeNumber, $planId, $_SESSION['user_id']]);
        $routeId = $db->lastInsertId();
        echo "[Setup] Created Route ID: $routeId\n\n";
    } else {
        echo "[Setup] Using existing Route ID: $routeId\n\n";
    }
    
    // ======= TEST 1: Try Dispatch without photos =======
    echo "[Test 1] Attempting DISPATCH without photos...\n";
    try {
        $result = $route->transitionStatus($routeId, 'Dispatched');
        if ($result['success']) {
            echo "[FAIL] Dispatch succeeded without photos (should have blocked)\n\n";
        } else {
            echo "[PASS] Blocked: {$result['error']}\n\n";
        }
    } catch (Exception $e) {
        echo "[PASS] Blocked: {$e->getMessage()}\n\n";
    }
    
    // ======= TEST 2: Upload 4 Dispatch photos =======
    echo "[Test 2] Uploading 4 Dispatch photos...\n";
    $insertStmt = $db->prepare("
        INSERT INTO evidence_photos (route_id, event_type, photo_seq, file_path, uploaded_by, uploaded_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    for ($i = 1; $i <= 4; $i++) {
        $insertStmt->execute([$routeId, 'Dispatch', $i, "/uploads/test/dispatch_$i.jpg", $_SESSION['user_id']]);
        echo "  Photo $i uploaded.\n";
    }
    echo "[DONE] 4 photos uploaded.\n\n";
    
    // ======= TEST 3: Retry Dispatch =======
    echo "[Test 3] Retrying DISPATCH with photos...\n";
    $result = $route->transitionStatus($routeId, 'Dispatched');
    if ($result['success']) {
        echo "[PASS] Dispatched successfully.\n\n";
    } else {
        echo "[FAIL] Dispatch still blocked: {$result['error']}\n\n";
    }
    
    // ======= TEST 4: Verify status history =======
    echo "[Test 4] Checking route_status_history...\n";
    $stmt = $db->prepare("
        SELECT * FROM route_status_history 
        WHERE route_id = ? 
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$routeId]);
    $history = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($history) {
        echo "[PASS] History record found:\n";
        echo "  - From: {$history['from_status']} → To: {$history['to_status']}\n";
        echo "  - At: {$history['created_at']}\n\n";
    } else {
        echo "[FAIL] No history record found.\n\n";
    }
    
    // ======= TEST 5: Move to InProgress =======
    echo "[Test 5] Transitioning to InProgress...\n";
    $result = $route->transitionStatus($routeId, 'InProgress');
    if ($result['success']) {
        echo "[PASS] Now InProgress.\n\n";
    } else {
        echo "[INFO] {$result['error']}\n\n";
    }
    
    // ======= TEST 6: Try Return without photos =======
    echo "[Test 6] Attempting RETURN without Return photos...\n";
    try {
        $result = $route->transitionStatus($routeId, 'Returned');
        if ($result['success']) {
            echo "[FAIL] Return succeeded without photos (should have blocked)\n\n";
        } else {
            echo "[PASS] Blocked: {$result['error']}\n\n";
        }
    } catch (Exception $e) {
        echo "[PASS] Blocked: {$e->getMessage()}\n\n";
    }
    
    // ======= TEST 7: Upload 4 Return photos and complete =======
    echo "[Test 7] Uploading 4 Return photos...\n";
    for ($i = 1; $i <= 4; $i++) {
        $insertStmt->execute([$routeId, 'Return', $i, "/uploads/test/return_$i.jpg", $_SESSION['user_id']]);
    }
    
    $result = $route->transitionStatus($routeId, 'Returned');
    if ($result['success']) {
        echo "[PASS] Returned successfully.\n\n";
    } else {
        echo "[FAIL] Return blocked: {$result['error']}\n\n";
    }
    
    echo "=== M3 Test Complete ===\n";
    
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
