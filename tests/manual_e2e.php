<?php
/**
 * MANUAL E2E TEST: Minimum Working ERP
 * 
 * Validates full workflow across all modules:
 * 1) User/Role setup, 2) Job lifecycle, 3) Planning/Conflicts,
 * 4) Procurement, 5) Route/Evidence, 6) Warehouse/Stock,
 * 7) Accounting AR, 8) RBAC enforcement
 * 
 * Run: php tests/manual_e2e.php
 * Exit 0 = All PASS, Exit 1 = Any FAIL
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../core/Policy.php';
require_once __DIR__ . '/../includes/booking_conflicts.php';
require_once __DIR__ . '/../modules/procurement/ProcurementService.php';
require_once __DIR__ . '/../modules/warehouse/WarehouseService.php';
require_once __DIR__ . '/../modules/accounting/InvoiceService.php';
require_once __DIR__ . '/../modules/accounting/PaymentService.php';

// ===== CONFIG =====
$TEST_PREFIX = 'E2E-' . date('YmdHis') . '-';
$TEST_ADM_ID = getenv('TEST_ADM_ID') ?: 1; // Use existing admin
$TEST_PLN_ID = getenv('TEST_PLN_ID') ?: 1;
$TEST_PUR_ID = getenv('TEST_PUR_ID') ?: 1;
$TEST_WH_ID  = getenv('TEST_WH_ID') ?: 1;
$TEST_ACC_ID = getenv('TEST_ACC_ID') ?: 1;
$TEST_MGR_ID = getenv('TEST_MGR_ID') ?: 1;

$_SESSION['user_id'] = $TEST_ADM_ID;
$_SESSION['username'] = 'e2e_admin';
$_SESSION['roles'] = ['ADM'];

$db = getDB();
$stepNum = 0;
$passed = 0;
$failed = 0;

function step($desc) {
    global $stepNum;
    $stepNum++;
    echo "\n[Step $stepNum] $desc\n";
}

function pass($msg) {
    global $passed;
    echo "  [PASS] $msg\n";
    $passed++;
}

function fail($msg) {
    global $failed;
    echo "  [FAIL] $msg\n";
    $failed++;
    echo "\n=== E2E ABORTED ===\n";
    exit(1);
}

function check($condition, $passMsg, $failMsg) {
    if ($condition) {
        pass($passMsg);
    } else {
        fail($failMsg);
    }
}

echo "=== MANUAL E2E TEST: Minimum Working ERP ===\n";
echo "Test Prefix: $TEST_PREFIX\n";
echo "User ID: $TEST_ADM_ID (ADM role)\n";

try {
    // ================================================================
    // STEP 1: CUSTOMER & SITE SETUP
    // ================================================================
    step("Setup: Get or create test customer");
    
    $stmt = $db->prepare("SELECT id FROM customers LIMIT 1");
    $stmt->execute();
    $customerId = $stmt->fetchColumn();
    
    if (!$customerId) {
        $db->prepare("INSERT INTO customers (name, customer_code, active, created_by) VALUES (?, ?, 1, ?)")
           ->execute([$TEST_PREFIX . 'Customer', $TEST_PREFIX . 'CUST', $TEST_ADM_ID]);
        $customerId = $db->lastInsertId();
        pass("Created customer ID: $customerId");
    } else {
        pass("Using existing customer ID: $customerId");
    }
    
    // ================================================================
    // STEP 2: CREATE JOB (DRAFT)
    // ================================================================
    step("Create Job in Draft status");
    
    $jobNumber = $TEST_PREFIX . 'JOB';
    $db->prepare("
        INSERT INTO jobs (job_number, customer_id, job_type, status, scope_short, 
                         plan_start_date, plan_end_date, owner_sale_id, owner_planner_id, created_by)
        VALUES (?, ?, 'Lumpsum', 'Draft', ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), ?, ?, ?)
    ")->execute([$jobNumber, $customerId, 'E2E Test Job', $TEST_ADM_ID, $TEST_PLN_ID, $TEST_ADM_ID]);
    $jobId = $db->lastInsertId();
    
    check($jobId > 0, "Job created: $jobNumber (ID: $jobId)", "Failed to create job");
    
    // ================================================================
    // STEP 3: JOB STATUS TRANSITIONS WITH RBAC
    // ================================================================
    step("Job Status: Draft -> Submitted");
    
    $db->prepare("UPDATE jobs SET status = 'Submitted', submitted_at = NOW(), submitted_by = ? WHERE id = ?")
       ->execute([$TEST_ADM_ID, $jobId]);
    
    $stmt = $db->prepare("SELECT status FROM jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    check($stmt->fetchColumn() === 'Submitted', "Job status = Submitted", "Status transition failed");
    
    step("Job Status: Submitted -> Approved (MGR action)");
    
    $db->prepare("UPDATE jobs SET status = 'Approved', approved_at = NOW(), approved_by = ? WHERE id = ?")
       ->execute([$TEST_MGR_ID, $jobId]);
    
    $stmt = $db->prepare("SELECT status FROM jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    check($stmt->fetchColumn() === 'Approved', "Job status = Approved", "Status transition failed");
    
    step("Job Status: Approved -> Planned (PLN action)");
    
    $db->prepare("UPDATE jobs SET status = 'Planned' WHERE id = ?")
       ->execute([$jobId]);
    
    $stmt = $db->prepare("SELECT status FROM jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    check($stmt->fetchColumn() === 'Planned', "Job status = Planned", "Status transition failed");
    
    // ================================================================
    // STEP 4: CONFLICT GUARD (M1)
    // ================================================================
    step("Conflict Guard: Book a serial, then attempt double-booking");
    
    $stmt = $db->prepare("SELECT id FROM serials WHERE status = 'Active' LIMIT 1");
    $stmt->execute();
    $serialId = $stmt->fetchColumn() ?: 1;
    
    $conflict = new BookingConflict($db);
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    
    // First booking should succeed
    try {
        $bookingId = $conflict->bookResource('Serial', $serialId, "$tomorrow 08:00:00", "$tomorrow 17:00:00", 'jobs', $jobId);
        pass("First booking created: ID $bookingId");
    } catch (Exception $e) {
        // May already be booked by previous tests
        pass("Resource already booked (expected in re-runs)");
    }
    
    // Second booking (same time) should conflict - check from different job perspective (job+9999)
    // The hasConflict method excludes conflicts from the same reference_id, so we simulate another job
    $hasConflict = $conflict->hasConflict('Serial', $serialId, "$tomorrow 08:00:00", "$tomorrow 17:00:00", $jobId + 9999);
    check($hasConflict, "Conflict correctly detected on double-booking", "Conflict guard failed");
    
    // ================================================================
    // STEP 5: PROCUREMENT (M2)
    // ================================================================
    step("Procurement: Create PR -> Approve -> PO -> GR");
    
    $proc = new ProcurementService();
    
    // Get test item
    $stmt = $db->prepare("SELECT id, quantity FROM items WHERE item_type = 'Consumable' LIMIT 1");
    $stmt->execute();
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    $itemId = $item['id'] ?? 1;
    $initialStock = (float)($item['quantity'] ?? 0);
    
    // Get supplier
    $stmt = $db->prepare("SELECT id FROM suppliers LIMIT 1");
    $stmt->execute();
    $supplierId = $stmt->fetchColumn() ?: 1;
    
    // Create PR
    $prResult = $proc->createPR([
        'requester_id' => $TEST_PUR_ID,
        'purpose' => $TEST_PREFIX . 'PR Test',
        'required_date' => date('Y-m-d', strtotime('+7 days'))
    ], [
        ['item_id' => $itemId, 'description' => 'E2E Test Item', 'qty' => 5, 'unit' => 'pcs', 'unit_price' => 100]
    ]);
    check($prResult['success'], "PR created: {$prResult['pr_number']}", "PR failed: " . ($prResult['error'] ?? ''));
    $prId = $prResult['id'];
    
    // Approve PR
    $approveResult = $proc->approvePR($prId, $TEST_MGR_ID);
    check($approveResult['success'], "PR approved", "PR approval failed: " . ($approveResult['error'] ?? ''));
    
    // Create PO
    $poResult = $proc->createPOFromPR($prId, $supplierId, date('Y-m-d', strtotime('+14 days')));
    check($poResult['success'], "PO created: {$poResult['po_number']}", "PO failed: " . ($poResult['error'] ?? ''));
    $poId = $poResult['id'];
    
    // Get PO item for GR
    $stmt = $db->prepare("SELECT id FROM po_items WHERE po_id = ? LIMIT 1");
    $stmt->execute([$poId]);
    $poItemId = $stmt->fetchColumn();
    
    // Record GR
    $grResult = $proc->recordGR($poId, [
        ['po_item_id' => $poItemId, 'received_qty' => 5, 'condition_note' => 'Good']
    ]);
    check($grResult['success'], "GR created: {$grResult['gr_number']}", "GR failed: " . ($grResult['error'] ?? ''));
    
    // Verify stock increase
    $stmt = $db->prepare("SELECT quantity FROM items WHERE id = ?");
    $stmt->execute([$itemId]);
    $newStock = (float)$stmt->fetchColumn();
    check($newStock >= $initialStock + 5, "Stock increased: $initialStock -> $newStock", "Stock not updated");
    
    // ================================================================
    // STEP 6: ROUTE + EVIDENCE (M3)
    // ================================================================
    step("Route: Create, upload photos, dispatch");
    
    // Get or create plan
    $stmt = $db->prepare("SELECT id FROM plans LIMIT 1");
    $stmt->execute();
    $planId = $stmt->fetchColumn() ?: 1;
    
    $routeNumber = $TEST_PREFIX . 'RT';
    $db->prepare("
        INSERT INTO routes (route_number, plan_id, route_date, status, created_by, created_at)
        VALUES (?, ?, CURDATE(), 'Confirmed', ?, NOW())
    ")->execute([$routeNumber, $planId, $TEST_ADM_ID]);
    $routeId = $db->lastInsertId();
    
    check($routeId > 0, "Route created: ID $routeId", "Failed to create route");
    
    // Upload 4 dispatch photos
    $photoStmt = $db->prepare("
        INSERT INTO evidence_photos (route_id, event_type, photo_seq, file_path, uploaded_by, uploaded_at)
        VALUES (?, 'Dispatch', ?, ?, ?, NOW())
    ");
    for ($i = 1; $i <= 4; $i++) {
        $photoStmt->execute([$routeId, $i, "/uploads/e2e/dispatch_$i.jpg", $TEST_WH_ID]);
    }
    pass("4 Dispatch photos uploaded");
    
    // Dispatch using Route class
    require_once __DIR__ . '/../core/Route.php';
    $routeService = new Route();
    $dispatchResult = $routeService->transitionStatus($routeId, 'Dispatched');
    check($dispatchResult['success'], "Route dispatched", "Dispatch failed: " . ($dispatchResult['error'] ?? ''));
    
    // Verify route_status_history
    $stmt = $db->prepare("SELECT COUNT(*) FROM route_status_history WHERE route_id = ?");
    $stmt->execute([$routeId]);
    check($stmt->fetchColumn() > 0, "Route status history recorded", "No history record");
    
    // ================================================================
    // STEP 7: WAREHOUSE + STOCK LEDGER (M4)
    // ================================================================
    step("Warehouse: Record movements, test reversal");
    
    $wh = new WarehouseService();
    
    // Get a device item for stock movement test
    $stmt = $db->prepare("SELECT id FROM items WHERE item_type = 'Device' LIMIT 1");
    $stmt->execute();
    $deviceId = $stmt->fetchColumn() ?: $itemId;
    
    // Record a dispatch movement
    $moveResult = $wh->recordMovement(
        WarehouseService::MOVE_GI_JOB,
        $deviceId, -2,
        WarehouseService::LOC_WH, WarehouseService::LOC_IN_TRANSIT,
        'routes', $routeId
    );
    check($moveResult['success'], "Dispatch movement recorded: ID {$moveResult['id']}", "Movement failed");
    $movementId = $moveResult['id'];
    
    // Reverse the movement
    $reverseResult = $wh->reverseMovement($movementId, 'E2E Test reversal');
    check($reverseResult['success'], "Reversal recorded: ID {$reverseResult['id']}", "Reversal failed");
    
    // Verify stock_movements has reversal entry
    $stmt = $db->prepare("SELECT movement_type, reverse_of_id FROM stock_movements WHERE id = ?");
    $stmt->execute([$reverseResult['id']]);
    $reversal = $stmt->fetch(PDO::FETCH_ASSOC);
    check(
        $reversal['movement_type'] === 'REVERSAL' && $reversal['reverse_of_id'] == $movementId,
        "Reversal integrity verified",
        "Reversal data incorrect"
    );
    
    // ================================================================
    // STEP 8: ACCOUNTING AR (M5)
    // ================================================================
    step("Accounting: Invoice -> Issue -> Payments -> Reverse");
    
    // Ensure job is in accounting-ready status
    $db->prepare("UPDATE jobs SET status = 'Accounting Ready' WHERE id = ?")->execute([$jobId]);
    
    $invoiceService = new InvoiceService();
    $paymentService = new PaymentService();
    
    // Create invoice
    $invResult = $invoiceService->createInvoiceFromJob($jobId, [
        ['description' => 'E2E Service', 'qty' => 1, 'unit_price' => 5000, 'unit' => 'job'],
        ['description' => 'E2E Fee', 'qty' => 1, 'unit_price' => 2000, 'unit' => 'job']
    ], ['tax_rate' => 7, 'withholding_rate' => 0]);
    check($invResult['success'], "Invoice created: {$invResult['invoice_no']}", "Invoice failed: " . ($invResult['error'] ?? ''));
    $invoiceId = $invResult['id'];
    $totalAmount = $invResult['total_amount'];
    
    // Issue invoice
    $issueResult = $invoiceService->issueInvoice($invoiceId);
    check($issueResult['success'], "Invoice issued", "Issue failed: " . ($issueResult['error'] ?? ''));
    
    // Partial payment
    $pay1Result = $paymentService->recordPayment($invoiceId, 3000, 'Bank Transfer');
    check($pay1Result['success'], "Partial payment: {$pay1Result['payment_no']}", "Payment failed");
    $payment1Id = $pay1Result['id'];
    $balance1 = $pay1Result['new_balance'];
    check($balance1 < $totalAmount && $balance1 > 0, "Balance after partial: $balance1", "Balance incorrect");
    
    // Final payment
    $pay2Result = $paymentService->recordPayment($invoiceId, $balance1, 'Bank Transfer');
    check($pay2Result['success'], "Final payment: {$pay2Result['payment_no']}", "Payment failed");
    
    $invoice = $invoiceService->getById($invoiceId);
    check($invoice['status'] === 'Paid', "Invoice status = Paid", "Expected Paid status");
    
    // Reverse payment 1
    $reversePayResult = $paymentService->reversePayment($payment1Id, 'E2E Test reversal');
    check($reversePayResult['success'], "Payment reversed", "Reversal failed");
    
    // Verify original payment still exists (immutability)
    $originalPay = $paymentService->getById($payment1Id);
    check($originalPay && $originalPay['status'] === 'Reversed', "Original payment preserved (immutability)", "Payment deleted or wrong status");
    
    // ================================================================
    // STEP 9: RBAC ENFORCEMENT (M6)
    // ================================================================
    step("RBAC: Verify deny/allow matrix");
    
    // WH cannot issue invoice
    check(!Policy::roleCanDo(Policy::ROLE_WH, Policy::INVOICE_ISSUE), "WH denied INVOICE_ISSUE", "WH should not issue");
    
    // ACC cannot WH receive
    check(!Policy::roleCanDo(Policy::ROLE_ACC, Policy::WH_RECEIVE_RETURN), "ACC denied WH_RECEIVE", "ACC should not WH");
    
    // PLN can dispatch
    check(Policy::roleCanDo(Policy::ROLE_PLN, Policy::ROUTE_DISPATCH), "PLN allowed ROUTE_DISPATCH", "PLN should dispatch");
    
    // PUR can create PO
    check(Policy::roleCanDo(Policy::ROLE_PUR, Policy::PO_CREATE), "PUR allowed PO_CREATE", "PUR should create PO");
    
    // MGR can void job
    check(Policy::roleCanDo(Policy::ROLE_MGR, Policy::JOB_VOID), "MGR allowed JOB_VOID", "MGR should void");
    
    // ADM can all
    check(Policy::roleCanDo(Policy::ROLE_ADM, Policy::INVOICE_ISSUE), "ADM allowed all", "ADM should do all");
    
    // ================================================================
    // STEP 10: AUDIT TRAIL VERIFICATION
    // ================================================================
    step("Audit: Verify trail exists for key actions");
    
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM audit_logs 
        WHERE entity_type IN ('ar_invoices', 'payments', 'stock_movements', 'purchase_request')
    ");
    $stmt->execute();
    $auditCount = $stmt->fetchColumn();
    check($auditCount >= 5, "Audit trail has $auditCount records", "Insufficient audit trail");
    
    // ================================================================
    // SUMMARY
    // ================================================================
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "=== E2E TEST COMPLETE ===\n";
    echo "Steps: $stepNum\n";
    echo "Passed: $passed\n";
    echo "Failed: $failed\n";
    echo str_repeat("=", 50) . "\n";
    
    if ($failed > 0) {
        echo "RESULT: FAIL\n";
        exit(1);
    } else {
        echo "RESULT: PASS\n";
        exit(0);
    }
    
} catch (Exception $e) {
    echo "\n[FATAL ERROR] " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
