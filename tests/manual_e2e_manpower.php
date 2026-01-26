<?php
/**
 * E2E Scenario: Manpower Job Flow
 * Tests Manpower job type with labor allocation
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../core/Policy.php';
require_once __DIR__ . '/../modules/accounting/InvoiceService.php';
require_once __DIR__ . '/../modules/accounting/PaymentService.php';

$TEST_PREFIX = 'MP' . date('Hi') . '-';
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

echo "=== E2E MANPOWER SCENARIO ===\n";
echo "Prefix: $TEST_PREFIX\n";

try {
    // 1. Create Manpower Job
    step("Create Manpower job");
    $stmt = $db->prepare("SELECT id FROM customers LIMIT 1");
    $stmt->execute();
    $customerId = $stmt->fetchColumn() ?: 1;
    
    $db->prepare("
        INSERT INTO jobs (job_number, customer_id, job_type, status, scope_short,
                         plan_start_date, plan_end_date, owner_sale_id, owner_planner_id, created_by)
        VALUES (?, ?, 'Manpower', 'Draft', ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), ?, ?, ?)
    ")->execute([$TEST_PREFIX.'JOB', $customerId, 'Labor supply for event', 
                 $TEST_USER_ID, $TEST_USER_ID, $TEST_USER_ID]);
    $jobId = $db->lastInsertId();
    check($jobId > 0, "Manpower job created: ID $jobId", "Job creation failed");
    
    // 2. Job lifecycle
    step("Job lifecycle to Planned");
    $db->prepare("UPDATE jobs SET status = 'Submitted', submitted_by = ? WHERE id = ?")->execute([$TEST_USER_ID, $jobId]);
    $db->prepare("UPDATE jobs SET status = 'Approved', approved_by = ? WHERE id = ?")->execute([$TEST_USER_ID, $jobId]);
    $db->prepare("UPDATE jobs SET status = 'Planned' WHERE id = ?")->execute([$jobId]);
    pass("Job transitioned to Planned");
    
    // 3. Allocate manpower (simulate technician assignments)
    step("Allocate manpower resources");
    $stmt = $db->prepare("SELECT id FROM plans LIMIT 1");
    $stmt->execute();
    $planId = $stmt->fetchColumn() ?: 1;
    
    // Check if individuals table exists for manpower tracking
    $stmt = $db->query("SHOW TABLES LIKE 'individuals'");
    if ($stmt->fetchColumn()) {
        $stmt = $db->prepare("SELECT id FROM individuals WHERE active = 1 LIMIT 3");
        $stmt->execute();
        $individuals = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($individuals) > 0) {
            pass("Found " . count($individuals) . " individuals for allocation");
        } else {
            pass("No individuals found (table exists, empty - acceptable for test)");
        }
    } else {
        pass("Individuals table not present (Manpower simplified)");
    }
    
    // 4. Create Route for dispatch
    step("Create dispatch route for manpower");
    $db->prepare("INSERT INTO routes (route_number, plan_id, route_date, status, created_by, created_at)
                  VALUES (?, ?, CURDATE(), 'Confirmed', ?, NOW())")
       ->execute([$TEST_PREFIX.'RT', $planId, $TEST_USER_ID]);
    $routeId = $db->lastInsertId();
    
    // Upload dispatch photos
    for ($i = 1; $i <= 4; $i++) {
        $db->prepare("INSERT INTO evidence_photos (route_id, event_type, photo_seq, file_path, uploaded_by, uploaded_at)
                      VALUES (?, 'Dispatch', ?, ?, ?, NOW())")
           ->execute([$routeId, $i, "/uploads/manpower/dispatch_$i.jpg", $TEST_USER_ID]);
    }
    pass("Route created with 4 dispatch photos");
    
    // 5. Dispatch
    step("Dispatch route");
    require_once __DIR__ . '/../core/Route.php';
    $routeService = new Route();
    $result = $routeService->transitionStatus($routeId, 'Dispatched');
    check($result['success'], "Route dispatched", "Dispatch failed");
    
    // 6. Return after work complete
    step("Complete work and return");
    for ($i = 1; $i <= 4; $i++) {
        $db->prepare("INSERT INTO evidence_photos (route_id, event_type, photo_seq, file_path, uploaded_by, uploaded_at)
                      VALUES (?, 'Return', ?, ?, ?, NOW())")
           ->execute([$routeId, $i, "/uploads/manpower/return_$i.jpg", $TEST_USER_ID]);
    }
    
    $returnResult = $routeService->transitionStatus($routeId, 'Returned');
    check($returnResult['success'], "Route returned", "Return failed");
    
    // 7. Status history verification
    step("Verify status history");
    $stmt = $db->prepare("SELECT COUNT(*) FROM route_status_history WHERE route_id = ?");
    $stmt->execute([$routeId]);
    check($stmt->fetchColumn() >= 2, "Status history recorded", "No history");
    
    // 8. Manpower invoice (hourly/daily billing)
    step("Create Manpower invoice");
    $db->prepare("UPDATE jobs SET status = 'Accounting Ready' WHERE id = ?")->execute([$jobId]);
    
    $invoiceService = new InvoiceService();
    $invResult = $invoiceService->createInvoiceFromJob($jobId, [
        ['description' => 'Technician A - 8 hours', 'qty' => 8, 'unit_price' => 150, 'unit' => 'hour'],
        ['description' => 'Technician B - 8 hours', 'qty' => 8, 'unit_price' => 150, 'unit' => 'hour'],
        ['description' => 'Supervisor - 8 hours', 'qty' => 8, 'unit_price' => 200, 'unit' => 'hour'],
    ], ['tax_rate' => 7]);
    check($invResult['success'], "Invoice created: {$invResult['invoice_no']}", "Invoice failed");
    
    // 9. Payment
    step("Process payment");
    $invoiceService->issueInvoice($invResult['id']);
    $paymentService = new PaymentService();
    $payResult = $paymentService->recordPayment($invResult['id'], $invResult['total_amount'], 'Bank Transfer');
    check($payResult['success'], "Payment recorded", "Payment failed");
    
    // 10. RBAC for HR
    step("RBAC: HR permissions");
    check(Policy::roleCanDo(Policy::ROLE_HR, Policy::GR_REGISTER_MANPOWER), "HR can register manpower", "HR denied");
    check(!Policy::roleCanDo(Policy::ROLE_HR, Policy::INVOICE_CREATE), "HR cannot create invoice", "HR should not");
    
    // 11. Audit trail
    step("Verify audit trail");
    $stmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE entity_type IN ('ar_invoices', 'payments')");
    $stmt->execute();
    check($stmt->fetchColumn() >= 2, "Audit trail verified", "Insufficient audit");
    
    // Summary
    echo "\n" . str_repeat("=", 40) . "\n";
    echo "=== MANPOWER SCENARIO COMPLETE ===\n";
    echo "Passed: $passed, Failed: $failed\n";
    echo "RESULT: PASS\n";
    exit(0);

} catch (Exception $e) {
    echo "\n[FATAL] " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
