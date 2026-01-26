<?php
/**
 * M5: Accounting AR - Manual Verification Script
 * 
 * Tests:
 * 1. Create invoice from Job with 2 lines
 * 2. Issue invoice
 * 3. Record partial payment (verify balance)
 * 4. Record final payment (verify paid status)
 * 5. Reverse one payment (verify balance increases)
 * 6. Validate immutability (no UPDATE on totals)
 * 7. Verify audit trail
 * 
 * Run: TEST_USER_ID=1 php tests/manual_accounting_ar.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../modules/accounting/InvoiceService.php';
require_once __DIR__ . '/../modules/accounting/PaymentService.php';

// Setup test session
$_SESSION['user_id'] = getenv('TEST_USER_ID') ?: 1;
$_SESSION['username'] = 'test_user';
$_SESSION['roles'] = ['ACC', 'ADM'];

try {
    $db = getDB();
    $invoiceService = new InvoiceService();
    $paymentService = new PaymentService();
    
    echo "=== M5: Accounting AR Test ===\n\n";
    
    // ======= SETUP: Get or create test job in accounting-ready status =======
    echo "[SETUP] Finding or creating test job...\n";
    
    // Find a job that's in a valid invoicing status
    $stmt = $db->prepare("
        SELECT j.id, j.job_number, j.status, j.customer_id
        FROM jobs j
        WHERE j.status IN ('Accounting Ready', 'WH Received', 'POS Checked', 'Closed')
        LIMIT 1
    ");
    $stmt->execute();
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$job) {
        // Update any existing job to Accounting Ready for testing
        $stmt = $db->prepare("SELECT id, customer_id FROM jobs WHERE status NOT IN ('Voided', 'Draft') LIMIT 1");
        $stmt->execute();
        $anyJob = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($anyJob) {
            $db->prepare("UPDATE jobs SET status = 'Accounting Ready' WHERE id = ?")->execute([$anyJob['id']]);
            $jobId = $anyJob['id'];
            $customerId = $anyJob['customer_id'];
            echo "  Updated Job ID $jobId to Accounting Ready for testing.\n";
        } else {
            // Create minimal job if none exists
            $stmt = $db->prepare("SELECT id FROM customers LIMIT 1");
            $stmt->execute();
            $customerId = $stmt->fetchColumn() ?: 1;
            
            $db->prepare("
                INSERT INTO jobs (job_no, customer_id, status, job_type, created_by)
                VALUES (?, ?, 'Accounting Ready', 'Lumpsum', ?)
            ")->execute(['TEST-ACC-' . time(), $customerId, $_SESSION['user_id']]);
            $jobId = $db->lastInsertId();
            echo "  Created test Job ID: $jobId\n";
        }
    } else {
        $jobId = $job['id'];
        $customerId = $job['customer_id'];
        echo "  Using existing Job ID: $jobId (Status: {$job['status']})\n";
    }
    echo "\n";
    
    // ======= TEST 1: Create invoice with 2 lines =======
    echo "[Test 1] Creating invoice with 2 lines...\n";
    $invoiceResult = $invoiceService->createInvoiceFromJob($jobId, [
        ['description' => 'Equipment Rental (Test)', 'qty' => 5, 'unit_price' => 1000, 'unit' => 'days'],
        ['description' => 'Transportation Fee (Test)', 'qty' => 1, 'unit_price' => 2500, 'unit' => 'trip']
    ], [
        'tax_rate' => 7,
        'withholding_rate' => 3,
        'notes' => 'Test invoice for M5 verification'
    ]);
    
    if (!$invoiceResult['success']) {
        echo "[FAIL] Invoice creation failed: {$invoiceResult['error']}\n";
        exit(1);
    }
    
    $invoiceId = $invoiceResult['id'];
    $invoiceNo = $invoiceResult['invoice_no'];
    $totalAmount = $invoiceResult['total_amount'];
    echo "[PASS] Invoice created: $invoiceNo (ID: $invoiceId, Total: $totalAmount)\n\n";
    
    // ======= TEST 2: Issue invoice =======
    echo "[Test 2] Issuing invoice...\n";
    $issueResult = $invoiceService->issueInvoice($invoiceId);
    
    if (!$issueResult['success']) {
        echo "[FAIL] Issue failed: {$issueResult['error']}\n";
        exit(1);
    }
    
    $invoice = $invoiceService->getById($invoiceId);
    if ($invoice['status'] === 'Issued') {
        echo "[PASS] Invoice issued successfully. Status: {$invoice['status']}\n\n";
    } else {
        echo "[FAIL] Expected status 'Issued', got: {$invoice['status']}\n";
        exit(1);
    }
    
    // ======= TEST 3: Record partial payment =======
    echo "[Test 3] Recording partial payment (3000)...\n";
    $payment1Result = $paymentService->recordPayment($invoiceId, 3000, 'Bank Transfer', null, 'TFR-001');
    
    if (!$payment1Result['success']) {
        echo "[FAIL] Payment failed: {$payment1Result['error']}\n";
        exit(1);
    }
    
    $payment1Id = $payment1Result['id'];
    $balance1 = $payment1Result['new_balance'];
    $invoice = $invoiceService->getById($invoiceId);
    
    if ($invoice['status'] === 'Partial' && $balance1 < $totalAmount) {
        echo "[PASS] Partial payment recorded: {$payment1Result['payment_no']}\n";
        echo "  Balance: $balance1, Status: {$invoice['status']}\n\n";
    } else {
        echo "[FAIL] Expected Partial status and reduced balance.\n";
        exit(1);
    }
    
    // ======= TEST 4: Record final payment =======
    echo "[Test 4] Recording final payment ($balance1)...\n";
    $payment2Result = $paymentService->recordPayment($invoiceId, $balance1, 'Bank Transfer', null, 'TFR-002');
    
    if (!$payment2Result['success']) {
        echo "[FAIL] Payment failed: {$payment2Result['error']}\n";
        exit(1);
    }
    
    $payment2Id = $payment2Result['id'];
    $balance2 = $payment2Result['new_balance'];
    $invoice = $invoiceService->getById($invoiceId);
    
    if ($invoice['status'] === 'Paid' && $balance2 == 0) {
        echo "[PASS] Final payment recorded: {$payment2Result['payment_no']}\n";
        echo "  Balance: $balance2, Status: {$invoice['status']}\n\n";
    } else {
        echo "[FAIL] Expected Paid status and zero balance.\n";
        exit(1);
    }
    
    // ======= TEST 5: Reverse payment 1 =======
    echo "[Test 5] Reversing first payment...\n";
    $reverseResult = $paymentService->reversePayment($payment1Id, 'Test reversal for M5 verification');
    
    if (!$reverseResult['success']) {
        echo "[FAIL] Reversal failed: {$reverseResult['error']}\n";
        exit(1);
    }
    
    $balance3 = $reverseResult['new_balance'];
    $invoice = $invoiceService->getById($invoiceId);
    
    // After reversing 3000, balance should increase back
    if ($balance3 > 0 && $invoice['status'] !== 'Paid') {
        echo "[PASS] Payment reversed: {$reverseResult['reversal_no']}\n";
        echo "  New Balance: $balance3, Status: {$invoice['status']}\n\n";
    } else {
        echo "[FAIL] Expected positive balance after reversal.\n";
        exit(1);
    }
    
    // ======= TEST 6: Verify original payment not deleted =======
    echo "[Test 6] Verifying original payment still exists (immutability)...\n";
    $originalPayment = $paymentService->getById($payment1Id);
    
    if ($originalPayment && $originalPayment['status'] === 'Reversed') {
        echo "[PASS] Original payment exists with status 'Reversed'.\n";
        echo "  Original ID: {$originalPayment['id']}, Reversed by ID: {$originalPayment['reversed_by_id']}\n\n";
    } else {
        echo "[FAIL] Original payment missing or wrong status.\n";
        exit(1);
    }
    
    // ======= TEST 7: Verify audit trail =======
    echo "[Test 7] Checking audit trail...\n";
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM audit_logs 
        WHERE entity_type IN ('ar_invoices', 'payments')
        AND entity_id IN (?, ?, ?, ?)
    ");
    $stmt->execute([$invoiceId, $payment1Id, $payment2Id, $reverseResult['reversal_id']]);
    $auditCount = $stmt->fetchColumn();
    
    if ($auditCount >= 4) {
        echo "[PASS] Audit trail verified: $auditCount records found.\n\n";
    } else {
        echo "[FAIL] Expected at least 4 audit records, found: $auditCount\n";
        exit(1);
    }
    
    echo "=== M5 Accounting AR Test Complete ===\n";
    echo "All 7 tests PASSED.\n";
    exit(0);
    
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
