<?php
/**
 * Test AP Module - Phase 1
 * Tests APInvoice and APPayment services
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../core/APInvoice.php';
require_once __DIR__ . '/../core/APPayment.php';

// Simulate logged-in user (admin = 7)
$_SESSION['user_id'] = 7;

echo "=== AP Module Test ===\n\n";

$apInvoice = new APInvoice();
$apPayment = new APPayment();
$db = getDB();

// Test 1: Check tables exist
echo "1. Checking AP tables...\n";
$tables = ['ap_invoices', 'ap_invoice_lines', 'ap_payments', 'ap_debit_notes'];
foreach ($tables as $table) {
    $stmt = $db->query("SHOW TABLES LIKE '$table'");
    $exists = $stmt->fetch() ? '✓' : '✗';
    echo "   $exists $table\n";
}

// Test 2: Check doc_number_settings
echo "\n2. Checking doc_number_settings...\n";
$docTypes = ['API', 'APP', 'ADN'];
foreach ($docTypes as $type) {
    $stmt = $db->prepare("SELECT * FROM doc_number_settings WHERE doc_type = ?");
    $stmt->execute([$type]);
    $exists = $stmt->fetch() ? '✓' : '✗';
    echo "   $exists $type\n";
}

// Test 3: Create AP Invoice manually
echo "\n3. Testing APInvoice::create()...\n";

// First, get a supplier
$stmt = $db->query("SELECT id FROM suppliers LIMIT 1");
$supplier = $stmt->fetch();

if ($supplier) {
    $result = $apInvoice->create([
        'supplier_id' => $supplier['id'],
        'supplier_invoice_no' => 'TEST-INV-001',
        'invoice_date' => date('Y-m-d'),
        'tax_rate' => 7,
        'notes' => 'Test AP Invoice'
    ], [
        ['description' => 'Test Item 1', 'quantity' => 10, 'unit_price' => 100, 'unit' => 'pcs'],
        ['description' => 'Test Item 2', 'quantity' => 5, 'unit_price' => 200, 'unit' => 'pcs']
    ]);

    if ($result['success']) {
        echo "   ✓ Created invoice: {$result['invoice_no']} (ID: {$result['id']})\n";
        $testInvoiceId = $result['id'];

        // Test 4: Get invoice
        echo "\n4. Testing APInvoice::getById()...\n";
        $invoice = $apInvoice->getById($testInvoiceId);
        if ($invoice) {
            echo "   ✓ Retrieved: {$invoice['invoice_no']}\n";
            echo "   - Subtotal: " . number_format($invoice['subtotal'], 2) . "\n";
            echo "   - Tax: " . number_format($invoice['tax_amount'], 2) . "\n";
            echo "   - Total: " . number_format($invoice['total_amount'], 2) . "\n";
            echo "   - Status: {$invoice['status']}\n";
        }

        // Test 5: Get lines
        echo "\n5. Testing APInvoice::getLines()...\n";
        $lines = $apInvoice->getLines($testInvoiceId);
        echo "   ✓ Found " . count($lines) . " line(s)\n";

        // Test 6: Submit invoice
        echo "\n6. Testing APInvoice::submit()...\n";
        $result = $apInvoice->submit($testInvoiceId);
        echo "   " . ($result['success'] ? '✓' : '✗') . " Submit: " . ($result['success'] ? 'OK' : $result['error']) . "\n";

        // Test 7: Approve invoice
        echo "\n7. Testing APInvoice::approve()...\n";
        $result = $apInvoice->approve($testInvoiceId);
        echo "   " . ($result['success'] ? '✓' : '✗') . " Approve: " . ($result['success'] ? 'OK' : $result['error']) . "\n";

        // Refresh invoice
        $invoice = $apInvoice->getById($testInvoiceId);

        // Test 8: Request payment
        echo "\n8. Testing APPayment::requestPayment()...\n";
        $result = $apPayment->requestPayment($testInvoiceId, [
            'amount' => 1000,
            'payment_method' => 'Bank Transfer',
            'payment_date' => date('Y-m-d'),
            'reference_no' => 'TEST-PAY-001'
        ]);
        
        if ($result['success']) {
            echo "   ✓ Payment requested: {$result['payment_no']} (ID: {$result['id']})\n";
            $testPaymentId = $result['id'];

            // Test 9: Approve payment
            echo "\n9. Testing APPayment::approve()...\n";
            $result = $apPayment->approve($testPaymentId);
            echo "   " . ($result['success'] ? '✓' : '✗') . " Approve: " . ($result['success'] ? 'OK' : $result['error']) . "\n";

            // Test 10: Post payment
            echo "\n10. Testing APPayment::post()...\n";
            $result = $apPayment->post($testPaymentId);
            echo "   " . ($result['success'] ? '✓' : '✗') . " Post: " . ($result['success'] ? 'OK' : $result['error']) . "\n";
            if ($result['success']) {
                echo "   - Invoice status: {$result['invoice_status']}\n";
            }

            // Refresh invoice to check paid amount
            $invoice = $apInvoice->getById($testInvoiceId);
            echo "   - Paid amount: " . number_format($invoice['paid_amount'], 2) . "\n";
            echo "   - Balance: " . number_format($apInvoice->getBalance($testInvoiceId), 2) . "\n";

            // Test 11: Reverse payment
            echo "\n11. Testing APPayment::reverse()...\n";
            $result = $apPayment->reverse($testPaymentId, 'Test reversal');
            echo "   " . ($result['success'] ? '✓' : '✗') . " Reverse: " . ($result['success'] ? "OK (Reversal: {$result['reversal_no']})" : $result['error']) . "\n";

        } else {
            echo "   ✗ Payment request failed: {$result['error']}\n";
        }

        // Test 12: Void invoice
        echo "\n12. Testing APInvoice::void()...\n";
        $result = $apInvoice->void($testInvoiceId, 'Test void');
        echo "   " . ($result['success'] ? '✓' : '✗') . " Void: " . ($result['success'] ? "OK (DN: {$result['debit_note_no']})" : $result['error']) . "\n";

    } else {
        echo "   ✗ Create failed: {$result['error']}\n";
    }
} else {
    echo "   ✗ No supplier found for testing\n";
}

// Test 13: Get list
echo "\n13. Testing APInvoice::getList()...\n";
$list = $apInvoice->getList();
echo "   ✓ Found " . count($list) . " invoice(s)\n";

// Test 14: Get aging report
echo "\n14. Testing APInvoice::getAgingReport()...\n";
$aging = $apInvoice->getAgingReport();
echo "   ✓ Aging report has " . count($aging) . " supplier(s)\n";

// Test 15: Get pending payments
echo "\n15. Testing APPayment::getPendingCount()...\n";
$pending = $apPayment->getPendingCount();
echo "   ✓ Pending payments: $pending\n";

echo "\n=== Test Complete ===\n";
