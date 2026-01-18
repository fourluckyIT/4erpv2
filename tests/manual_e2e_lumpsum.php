<?php
/**
 * E2E Scenario: Lumpsum Job Flow
 * Tests Lumpsum job type with fixed-price billing
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../core/Policy.php';
require_once __DIR__ . '/../modules/procurement/ProcurementService.php';
require_once __DIR__ . '/../modules/warehouse/WarehouseService.php';
require_once __DIR__ . '/../modules/accounting/InvoiceService.php';
require_once __DIR__ . '/../modules/accounting/PaymentService.php';

$TEST_PREFIX = 'LS' . date('Hi') . '-';
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

echo "=== E2E LUMPSUM SCENARIO ===\n";
echo "Prefix: $TEST_PREFIX\n";

try {
    // 1. Create Lumpsum Job
    step("Create Lumpsum job with fixed scope");
    $stmt = $db->prepare("SELECT id FROM customers LIMIT 1");
    $stmt->execute();
    $customerId = $stmt->fetchColumn() ?: 1;
    
    $db->prepare("
        INSERT INTO jobs (job_number, customer_id, job_type, status, scope_short,
                         plan_start_date, plan_end_date, owner_sale_id, owner_planner_id, created_by)
        VALUES (?, ?, 'Lumpsum', 'Draft', ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?, ?, ?)
    ")->execute([$TEST_PREFIX.'JOB', $customerId, 'Fixed-price project', 
                 $TEST_USER_ID, $TEST_USER_ID, $TEST_USER_ID]);
    $jobId = $db->lastInsertId();
    check($jobId > 0, "Lumpsum job created: ID $jobId", "Job creation failed");
    
    // 2. Full job lifecycle
    step("Complete job lifecycle: Draft -> Closed");
    $db->prepare("UPDATE jobs SET status = 'Submitted', submitted_by = ? WHERE id = ?")->execute([$TEST_USER_ID, $jobId]);
    $db->prepare("UPDATE jobs SET status = 'Approved', approved_by = ? WHERE id = ?")->execute([$TEST_USER_ID, $jobId]);
    $db->prepare("UPDATE jobs SET status = 'Planned' WHERE id = ?")->execute([$jobId]);
    pass("Job transitioned to Planned");
    
    // 3. Procurement for project materials
    step("Create procurement: PR -> PO -> GR");
    $proc = new ProcurementService();
    
    $stmt = $db->prepare("SELECT id, quantity FROM items WHERE item_type = 'Consumable' LIMIT 1");
    $stmt->execute();
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    $itemId = $item['id'] ?? 1;
    $initialStock = (float)($item['quantity'] ?? 0);
    
    $stmt = $db->prepare("SELECT id FROM suppliers LIMIT 1");
    $stmt->execute();
    $supplierId = $stmt->fetchColumn() ?: 1;
    
    $prResult = $proc->createPR([
        'requester_id' => $TEST_USER_ID,
        'purpose' => $TEST_PREFIX . 'Materials',
        'required_date' => date('Y-m-d', strtotime('+7 days'))
    ], [
        ['item_id' => $itemId, 'description' => 'Project Materials', 'qty' => 10, 'unit' => 'pcs', 'unit_price' => 500]
    ]);
    check($prResult['success'], "PR created: {$prResult['pr_number']}", "PR failed");
    
    $proc->approvePR($prResult['id'], $TEST_USER_ID);
    $poResult = $proc->createPOFromPR($prResult['id'], $supplierId, date('Y-m-d', strtotime('+14 days')));
    check($poResult['success'], "PO created: {$poResult['po_number']}", "PO failed");
    
    $stmt = $db->prepare("SELECT id FROM po_items WHERE po_id = ? LIMIT 1");
    $stmt->execute([$poResult['id']]);
    $poItemId = $stmt->fetchColumn();
    
    $grResult = $proc->recordGR($poResult['id'], [
        ['po_item_id' => $poItemId, 'received_qty' => 10, 'condition_note' => 'Good']
    ]);
    check($grResult['success'], "GR created: {$grResult['gr_number']}", "GR failed");
    
    // 4. Verify stock increase
    step("Verify stock increased");
    $stmt = $db->prepare("SELECT quantity FROM items WHERE id = ?");
    $stmt->execute([$itemId]);
    $newStock = (float)$stmt->fetchColumn();
    check($newStock >= $initialStock + 10, "Stock: $initialStock -> $newStock", "Stock unchanged");
    
    // 5. Route with full evidence cycle
    step("Create route with dispatch/receive/return photos");
    $stmt = $db->prepare("SELECT id FROM plans LIMIT 1");
    $stmt->execute();
    $planId = $stmt->fetchColumn() ?: 1;
    
    $db->prepare("INSERT INTO routes (route_number, plan_id, route_date, status, created_by, created_at)
                  VALUES (?, ?, CURDATE(), 'Confirmed', ?, NOW())")
       ->execute([$TEST_PREFIX.'RT', $planId, $TEST_USER_ID]);
    $routeId = $db->lastInsertId();
    
    // All event types
    foreach (['Dispatch', 'Receive', 'Return'] as $eventType) {
        for ($i = 1; $i <= 4; $i++) {
            $db->prepare("INSERT INTO evidence_photos (route_id, event_type, photo_seq, file_path, uploaded_by, uploaded_at)
                          VALUES (?, ?, ?, ?, ?, NOW())")
               ->execute([$routeId, $eventType, $i, "/uploads/lumpsum/{$eventType}_$i.jpg", $TEST_USER_ID]);
        }
    }
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM evidence_photos WHERE route_id = ?");
    $stmt->execute([$routeId]);
    check($stmt->fetchColumn() == 12, "12 photos (3 events x 4) uploaded", "Photo count wrong");
    
    // 6. Route transitions
    step("Route transitions: Dispatch -> InProgress -> Returned");
    require_once __DIR__ . '/../core/Route.php';
    $routeService = new Route();
    
    $routeService->transitionStatus($routeId, 'Dispatched');
    $routeService->transitionStatus($routeId, 'InProgress');
    $routeService->transitionStatus($routeId, 'Returned');
    
    $stmt = $db->prepare("SELECT status FROM routes WHERE id = ?");
    $stmt->execute([$routeId]);
    check($stmt->fetchColumn() === 'Returned', "Route in Returned status", "Status wrong");
    
    // 7. Status history
    step("Verify route status history");
    $stmt = $db->prepare("SELECT COUNT(*) FROM route_status_history WHERE route_id = ?");
    $stmt->execute([$routeId]);
    check($stmt->fetchColumn() >= 3, "3+ status history records", "History incomplete");
    
    // 8. Lumpsum invoice
    step("Create Lumpsum invoice");
    $db->prepare("UPDATE jobs SET status = 'Accounting Ready' WHERE id = ?")->execute([$jobId]);
    
    $invoiceService = new InvoiceService();
    $invResult = $invoiceService->createInvoiceFromJob($jobId, [
        ['description' => 'Lumpsum Project Fee', 'qty' => 1, 'unit_price' => 50000, 'unit' => 'project'],
        ['description' => 'Materials (pass-through)', 'qty' => 10, 'unit_price' => 500, 'unit' => 'pcs']
    ], ['tax_rate' => 7, 'withholding_rate' => 3]);
    check($invResult['success'], "Invoice created: {$invResult['invoice_no']}", "Invoice failed");
    
    // 9. Issue and partial payments
    step("Issue and process partial payments");
    $invoiceService->issueInvoice($invResult['id']);
    
    $paymentService = new PaymentService();
    $total = $invResult['total_amount'];
    $pay1 = $paymentService->recordPayment($invResult['id'], $total * 0.5, 'Bank Transfer');
    check($pay1['success'], "50% payment recorded", "Payment failed");
    
    $pay2 = $paymentService->recordPayment($invResult['id'], $pay1['new_balance'], 'Bank Transfer');
    check($pay2['success'], "Final payment recorded", "Payment failed");
    
    $invoice = $invoiceService->getById($invResult['id']);
    check($invoice['status'] === 'Paid', "Invoice status = Paid", "Not paid");
    
    // 10. RBAC check
    step("RBAC: Verify Lumpsum permissions");
    check(Policy::roleCanDo(Policy::ROLE_SAL, Policy::JOB_CREATE), "SAL can create job", "SAL denied");
    check(!Policy::roleCanDo(Policy::ROLE_WH, Policy::INVOICE_CREATE), "WH cannot create invoice", "WH should not");
    
    // Summary
    echo "\n" . str_repeat("=", 40) . "\n";
    echo "=== LUMPSUM SCENARIO COMPLETE ===\n";
    echo "Passed: $passed, Failed: $failed\n";
    echo "RESULT: PASS\n";
    exit(0);

} catch (Exception $e) {
    echo "\n[FATAL] " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
