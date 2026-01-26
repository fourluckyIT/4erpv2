<?php
/**
 * E2E Scenario: Device POS Check Flow
 * Tests device deployment with POS (Point of Sale) check evidence
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../core/Policy.php';
require_once __DIR__ . '/../modules/warehouse/WarehouseService.php';

$TEST_PREFIX = 'DP' . date('Hi') . '-';
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

echo "=== E2E DEVICE POS CHECK SCENARIO ===\n";
echo "Prefix: $TEST_PREFIX\n";

try {
    // 1. Create Job for device deployment
    step("Create Device deployment job");
    $stmt = $db->prepare("SELECT id FROM customers LIMIT 1");
    $stmt->execute();
    $customerId = $stmt->fetchColumn() ?: 1;
    
    $db->prepare("
        INSERT INTO jobs (job_number, customer_id, job_type, status, scope_short,
                         plan_start_date, plan_end_date, owner_sale_id, owner_planner_id, created_by)
        VALUES (?, ?, 'Lumpsum', 'Draft', ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), ?, ?, ?)
    ")->execute([$TEST_PREFIX.'JOB', $customerId, 'Device install + POS check', 
                 $TEST_USER_ID, $TEST_USER_ID, $TEST_USER_ID]);
    $jobId = $db->lastInsertId();
    check($jobId > 0, "Device job created: ID $jobId", "Job creation failed");
    
    // 2. Job to Planned
    step("Transition job to Planned");
    $db->prepare("UPDATE jobs SET status = 'Submitted', submitted_by = ? WHERE id = ?")->execute([$TEST_USER_ID, $jobId]);
    $db->prepare("UPDATE jobs SET status = 'Approved', approved_by = ? WHERE id = ?")->execute([$TEST_USER_ID, $jobId]);
    $db->prepare("UPDATE jobs SET status = 'Planned' WHERE id = ?")->execute([$jobId]);
    pass("Job in Planned status");
    
    // 3. Create Route
    step("Create route for device deployment");
    $stmt = $db->prepare("SELECT id FROM plans LIMIT 1");
    $stmt->execute();
    $planId = $stmt->fetchColumn() ?: 1;
    
    $db->prepare("INSERT INTO routes (route_number, plan_id, route_date, status, created_by, created_at)
                  VALUES (?, ?, CURDATE(), 'Confirmed', ?, NOW())")
       ->execute([$TEST_PREFIX.'RT', $planId, $TEST_USER_ID]);
    $routeId = $db->lastInsertId();
    check($routeId > 0, "Route created: ID $routeId", "Route failed");
    
    // 4. Dispatch with photos
    step("Upload 4 dispatch photos and dispatch");
    for ($i = 1; $i <= 4; $i++) {
        $db->prepare("INSERT INTO evidence_photos (route_id, event_type, photo_seq, file_path, uploaded_by, uploaded_at)
                      VALUES (?, 'Dispatch', ?, ?, ?, NOW())")
           ->execute([$routeId, $i, "/uploads/devpos/dispatch_$i.jpg", $TEST_USER_ID]);
    }
    
    require_once __DIR__ . '/../core/Route.php';
    $routeService = new Route();
    $dispatchResult = $routeService->transitionStatus($routeId, 'Dispatched');
    check($dispatchResult['success'], "Route dispatched", "Dispatch failed");
    
    // 5. Stock movement - device out (optional)
    step("Record device dispatch from WH");
    $wh = new WarehouseService();
    $stmt = $db->prepare("SELECT id FROM items WHERE item_type = 'Device' LIMIT 1");
    $stmt->execute();
    $deviceId = $stmt->fetchColumn() ?: 1;
    
    $moveResult = $wh->recordMovement('GI_JOB', $deviceId, -1, 'WH', 'CUSTOMER', 'routes', $routeId);
    if ($moveResult['success']) {
        pass("Device dispatched: Movement ID {$moveResult['id']}");
    } else {
        pass("Movement skipped (acceptable): " . ($moveResult['error'] ?? 'unknown'));
    }
    
    // 6. POS Check photos (4 required for POS verification at customer site)
    step("Upload 4 POS check photos at customer site");
    for ($i = 1; $i <= 4; $i++) {
        $db->prepare("INSERT INTO evidence_photos (route_id, event_type, photo_seq, file_path, uploaded_by, uploaded_at)
                      VALUES (?, 'POS', ?, ?, ?, NOW())")
           ->execute([$routeId, $i, "/uploads/devpos/pos_$i.jpg", $TEST_USER_ID]);
    }
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM evidence_photos WHERE route_id = ? AND event_type = 'POS'");
    $stmt->execute([$routeId]);
    check($stmt->fetchColumn() == 4, "4 POS photos uploaded", "POS photo count wrong");
    
    // 7. RBAC: POS check permission
    step("RBAC: Verify POS check permissions");
    check(Policy::roleCanDo(Policy::ROLE_WH, Policy::POS_CHECK), "WH can POS check", "WH denied POS");
    check(Policy::roleCanDo(Policy::ROLE_ADM, Policy::POS_CHECK), "ADM can POS check", "ADM denied");
    check(!Policy::roleCanDo(Policy::ROLE_ACC, Policy::POS_CHECK), "ACC cannot POS check", "ACC should not");
    
    // 8. InProgress (at customer site after install)
    step("Upload receive photos and mark in progress");
    for ($i = 1; $i <= 4; $i++) {
        $db->prepare("INSERT INTO evidence_photos (route_id, event_type, photo_seq, file_path, uploaded_by, uploaded_at)
                      VALUES (?, 'Receive', ?, ?, ?, NOW())")
           ->execute([$routeId, $i, "/uploads/devpos/receive_$i.jpg", $TEST_USER_ID]);
    }
    
    $progressResult = $routeService->transitionStatus($routeId, 'InProgress');
    check($progressResult['success'], "Route in progress (installed)", "InProgress failed");
    
    // 9. Verify all evidence counts
    step("Verify evidence photo counts by event type");
    $stmt = $db->prepare("SELECT event_type, COUNT(*) as cnt FROM evidence_photos WHERE route_id = ? GROUP BY event_type");
    $stmt->execute([$routeId]);
    $photoCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    check(($photoCounts['Dispatch'] ?? 0) == 4, "Dispatch: 4 photos", "Dispatch count wrong");
    check(($photoCounts['POS'] ?? 0) == 4, "POS: 4 photos", "POS count wrong");
    check(($photoCounts['Receive'] ?? 0) == 4, "Receive: 4 photos", "Receive count wrong");
    
    // 10. Status history
    step("Verify route status history");
    $stmt = $db->prepare("SELECT COUNT(*) FROM route_status_history WHERE route_id = ?");
    $stmt->execute([$routeId]);
    check($stmt->fetchColumn() >= 2, "Status history has 2+ records", "History incomplete");
    
    // 11. Stock movement verification
    step("Verify stock movement in ledger");
    $stmt = $db->prepare("SELECT COUNT(*) FROM stock_movements WHERE reference_type = 'routes' AND reference_id = ?");
    $stmt->execute([$routeId]);
    check($stmt->fetchColumn() >= 1, "Stock movement recorded", "No movement");
    
    // Summary
    echo "\n" . str_repeat("=", 40) . "\n";
    echo "=== DEVICE POS CHECK COMPLETE ===\n";
    echo "Passed: $passed, Failed: $failed\n";
    echo "RESULT: PASS\n";
    exit(0);

} catch (Exception $e) {
    echo "\n[FATAL] " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
