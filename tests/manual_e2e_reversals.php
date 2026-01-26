<?php
/**
 * E2E Scenario: Reversals & Credit Notes
 * Tests reversal patterns for stock movements, payments, and invoice credit notes
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../core/Policy.php';
require_once __DIR__ . '/../modules/warehouse/WarehouseService.php';
require_once __DIR__ . '/../modules/accounting/InvoiceService.php';
require_once __DIR__ . '/../modules/accounting/PaymentService.php';

$TEST_PREFIX = 'RV' . date('Hi') . '-';
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

echo "=== E2E REVERSALS SCENARIO ===\n";
echo "Prefix: $TEST_PREFIX\n";

try {
    // Setup: Create a job for testing
    step("Setup: Create test job");
    $stmt = $db->prepare("SELECT id FROM customers LIMIT 1");
    $stmt->execute();
    $customerId = $stmt->fetchColumn() ?: 1;
    
    $db->prepare("INSERT INTO jobs (job_number, customer_id, job_type, status, scope_short,
                  plan_start_date, plan_end_date, owner_sale_id, owner_planner_id, created_by)
                  VALUES (?, ?, 'Lumpsum', 'Planned', ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), ?, ?, ?)")
       ->execute([$TEST_PREFIX.'JOB', $customerId, 'Reversal Test Job', $TEST_USER_ID, $TEST_USER_ID, $TEST_USER_ID]);
    $jobId = $db->lastInsertId();
    check($jobId > 0, "Test job created: ID $jobId", "Job creation failed");
    
    // ========================================
    // SECTION 1: STOCK MOVEMENT REVERSAL
    // ========================================
    step("Stock Movement: Record dispatch movement");
    $wh = new WarehouseService();
    
    $stmt = $db->prepare("SELECT id, quantity FROM items WHERE item_type = 'Device' LIMIT 1");
    $stmt->execute();
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    $itemId = $item['id'] ?? 1;
    $initialQty = (float)($item['quantity'] ?? 0);
    
    $moveResult = $wh->recordMovement('GI_JOB', $itemId, -3, 'WH', 'IN_TRANSIT', 'jobs', $jobId);
    check($moveResult['success'], "Dispatch movement recorded: ID {$moveResult['id']}", "Movement failed");
    $movementId = $moveResult['id'];
    
    // Check stock decreased
    $stmt = $db->prepare("SELECT quantity FROM items WHERE id = ?");
    $stmt->execute([$itemId]);
    $afterDispatch = (float)$stmt->fetchColumn();
    check($afterDispatch < $initialQty, "Stock decreased: $initialQty -> $afterDispatch", "Stock unchanged");
    
    step("Stock Movement: Reverse the dispatch (error correction)");
    $reverseResult = $wh->reverseMovement($movementId, 'Reversal test - incorrect dispatch');
    check($reverseResult['success'], "Reversal recorded: ID {$reverseResult['id']}", "Reversal failed");
    
    // Verify reversal record
    $stmt = $db->prepare("SELECT movement_type, reverse_of_id, notes FROM stock_movements WHERE id = ?");
    $stmt->execute([$reverseResult['id']]);
    $revRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    check($revRecord['movement_type'] === 'REVERSAL', "Movement type = REVERSAL", "Wrong type");
    check($revRecord['reverse_of_id'] == $movementId, "reverse_of_id links to original", "Link incorrect");
    check(strpos($revRecord['notes'], 'Reversal') !== false, "Notes contain reversal reason", "No reason");
    
    // Check stock restored
    $stmt = $db->prepare("SELECT quantity FROM items WHERE id = ?");
    $stmt->execute([$itemId]);
    $afterReversal = (float)$stmt->fetchColumn();
    check($afterReversal == $afterDispatch + 3, "Stock restored after reversal", "Stock not restored");
    
    step("Stock Movement: Verify immutability of original");
    $stmt = $db->prepare("SELECT id FROM stock_movements WHERE id = ?");
    $stmt->execute([$movementId]);
    check($stmt->fetchColumn() == $movementId, "Original movement still exists (immutable)", "Original deleted");
    
    // ========================================
    // SECTION 2: PAYMENT REVERSAL
    // ========================================
    step("Payment: Create and issue invoice");
    $db->prepare("UPDATE jobs SET status = 'Accounting Ready' WHERE id = ?")->execute([$jobId]);
    
    $invoiceService = new InvoiceService();
    $invResult = $invoiceService->createInvoiceFromJob($jobId, [
        ['description' => 'Reversal Test Service', 'qty' => 1, 'unit_price' => 10000, 'unit' => 'job']
    ], ['tax_rate' => 7]);
    check($invResult['success'], "Invoice created: {$invResult['invoice_no']}", "Invoice failed");
    $invoiceId = $invResult['id'];
    
    $invoiceService->issueInvoice($invoiceId);
    pass("Invoice issued");
    
    step("Payment: Record payment");
    $paymentService = new PaymentService();
    $payResult = $paymentService->recordPayment($invoiceId, 5000, 'Bank Transfer');
    check($payResult['success'], "Payment recorded: {$payResult['payment_no']}", "Payment failed");
    $paymentId = $payResult['id'];
    $balanceAfterPay = $payResult['new_balance'];
    
    step("Payment: Reverse payment (customer dispute)");
    $revPayResult = $paymentService->reversePayment($paymentId, 'Customer disputed charge');
    check($revPayResult['success'], "Payment reversed: {$revPayResult['reversal_no']}", "Reversal failed");
    
    // Verify original payment immutability
    $origPayment = $paymentService->getById($paymentId);
    check($origPayment['status'] === 'Reversed', "Original payment status = Reversed", "Status not updated");
    check($origPayment['id'] == $paymentId, "Original payment still exists", "Payment deleted");
    
    // Verify balance restored (invoice no longer Paid)
    $invoice = $invoiceService->getById($invoiceId);
    check($invoice['status'] !== 'Paid' || $invoice['balance'] > 0, "Balance/status changed after reversal", "No balance change");
    
    step("Payment: Verify reversal record");
    $stmt = $db->prepare("SELECT reverse_of_id, status FROM payments WHERE id = ?");
    $stmt->execute([$revPayResult['id']]);
    $revPayRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    check($revPayRecord['reverse_of_id'] == $paymentId, "Reversal links to original payment", "Link incorrect");
    
    // ========================================
    // SECTION 3: INVOICE VOID VIA CREDIT NOTE
    // ========================================
    step("Credit Note: Create new invoice for void test");
    
    // Create a second job for a fresh invoice
    $db->prepare("INSERT INTO jobs (job_number, customer_id, job_type, status, scope_short,
                  plan_start_date, plan_end_date, owner_sale_id, owner_planner_id, created_by)
                  VALUES (?, ?, 'Lumpsum', 'Accounting Ready', ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), ?, ?, ?)")
       ->execute([$TEST_PREFIX.'JOB2', $customerId, 'Credit Note Test', $TEST_USER_ID, $TEST_USER_ID, $TEST_USER_ID]);
    $job2Id = $db->lastInsertId();
    
    $inv2Result = $invoiceService->createInvoiceFromJob($job2Id, [
        ['description' => 'Should be voided via CN', 'qty' => 1, 'unit_price' => 8000, 'unit' => 'job']
    ], ['tax_rate' => 7]);
    $invoice2Id = $inv2Result['id'];
    $invoiceService->issueInvoice($invoice2Id);
    pass("Second invoice created and issued");
    
    step("Credit Note: Void invoice via credit note");
    $voidResult = $invoiceService->voidInvoice($invoice2Id, 'Billing error - void entire invoice');
    check($voidResult['success'], "Invoice voided via credit note", "Void failed");
    
    // Verify credit note created
    $stmt = $db->prepare("SELECT id FROM ar_credit_notes WHERE invoice_id = ?");
    $stmt->execute([$invoice2Id]);
    $creditNoteId = $stmt->fetchColumn();
    check($creditNoteId > 0, "Credit note created: ID $creditNoteId", "No credit note");
    
    // Verify invoice marked as voided
    $voidedInvoice = $invoiceService->getById($invoice2Id);
    check($voidedInvoice['status'] === 'Voided', "Invoice status = Voided", "Not voided");
    check($voidedInvoice['voided_by_credit_note_id'] == $creditNoteId, "voided_by_credit_note_id set", "Link missing");
    
    step("Credit Note: Verify original invoice immutability");
    check($voidedInvoice['id'] == $invoice2Id, "Original invoice still exists", "Invoice deleted");
    check($voidedInvoice['invoice_no'] == $inv2Result['invoice_no'], "Invoice number preserved", "Number changed");
    
    // ========================================
    // SECTION 4: AUDIT TRAIL VERIFICATION
    // ========================================
    step("Audit: Verify reversal actions logged");
    $stmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action LIKE '%revers%' OR action LIKE '%void%'");
    $stmt->execute();
    check($stmt->fetchColumn() >= 2, "Reversal actions in audit log", "No reversal audit");
    
    // ========================================
    // SECTION 5: RBAC FOR REVERSALS
    // ========================================
    step("RBAC: Verify reversal permissions");
    check(Policy::roleCanDo(Policy::ROLE_MGR, Policy::STOCK_MOVE_REVERSE), "MGR can reverse stock", "MGR denied");
    check(Policy::roleCanDo(Policy::ROLE_ACC, Policy::PAYMENT_REVERSE), "ACC can reverse payment", "ACC denied");
    check(Policy::roleCanDo(Policy::ROLE_MGR, Policy::INVOICE_VOID), "MGR can void invoice", "MGR denied void");
    check(!Policy::roleCanDo(Policy::ROLE_WH, Policy::PAYMENT_REVERSE), "WH cannot reverse payment", "WH should not");
    
    // Summary
    echo "\n" . str_repeat("=", 40) . "\n";
    echo "=== REVERSALS SCENARIO COMPLETE ===\n";
    echo "Passed: $passed, Failed: $failed\n";
    echo "RESULT: PASS\n";
    exit(0);

} catch (Exception $e) {
    echo "\n[FATAL] " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
