<?php
/**
 * M2: Procurement Flow - Manual Verification Script
 * 
 * Tests:
 * 1. PR Creation with document number
 * 2. PR Approval
 * 3. PO Creation from PR
 * 4. GR Recording with stock update
 * 
 * Run: php tests/manual_procurement_flow.php
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/procurement/ProcurementService.php';

try {
    $db = getDB();
    $proc = new ProcurementService();
    
    echo "=== M2: Procurement Flow Test ===\n\n";
    
    // Setup: Create or find test item
    $stmt = $db->prepare("SELECT id, name, quantity FROM items WHERE item_type = 'Consumable' LIMIT 1");
    $stmt->execute();
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        // Create test item
        $db->prepare("INSERT INTO items (item_code, name, item_type, quantity) VALUES (?, ?, 'Consumable', 0)")
           ->execute(['TEST-M2-001', 'Test Consumable for M2']);
        $itemId = $db->lastInsertId();
        echo "Created Test Item ID: $itemId\n";
        $initialStock = 0;
    } else {
        $itemId = $item['id'];
        $initialStock = (float) $item['quantity'];
        echo "Using Item: {$item['name']} (ID: $itemId, Stock: $initialStock)\n";
    }
    
    // Setup: Get or create supplier
    $stmt = $db->prepare("SELECT id FROM suppliers LIMIT 1");
    $stmt->execute();
    $supplierId = $stmt->fetchColumn();
    
    if (!$supplierId) {
        $db->prepare("INSERT INTO suppliers (name, contact_name) VALUES (?, ?)")
           ->execute(['Test Supplier M2', 'Test Contact']);
        $supplierId = $db->lastInsertId();
        echo "Created Test Supplier ID: $supplierId\n\n";
    } else {
        echo "Using Supplier ID: $supplierId\n\n";
    }
    
    // ======= TEST 1: Create PR =======
    echo "[Step 1] Creating PR...\n";
    $prResult = $proc->createPR([
        'requester_id' => 1,
        'purpose' => 'M2 Test - Stock Replenishment',
        'required_date' => date('Y-m-d', strtotime('+7 days'))
    ], [
        [
            'item_id' => $itemId,
            'description' => 'Test Consumable',
            'qty' => 10,
            'unit' => 'pcs',
            'unit_price' => 100
        ]
    ]);
    
    if (!$prResult['success']) {
        throw new Exception("PR Creation failed: " . $prResult['error']);
    }
    echo "[PASS] PR Created: {$prResult['pr_number']} (ID: {$prResult['id']})\n\n";
    $prId = $prResult['id'];
    
    // ======= TEST 2: Approve PR =======
    echo "[Step 2] Approving PR...\n";
    $approveResult = $proc->approvePR($prId, 1);
    
    if (!$approveResult['success']) {
        throw new Exception("PR Approval failed: " . $approveResult['error']);
    }
    echo "[PASS] PR Approved.\n\n";
    
    // ======= TEST 3: Create PO from PR =======
    echo "[Step 3] Creating PO from PR...\n";
    $poResult = $proc->createPOFromPR($prId, $supplierId, date('Y-m-d', strtotime('+14 days')));
    
    if (!$poResult['success']) {
        throw new Exception("PO Creation failed: " . $poResult['error']);
    }
    echo "[PASS] PO Created: {$poResult['po_number']} (ID: {$poResult['id']})\n\n";
    $poId = $poResult['id'];
    
    // Get PO item ID for GR
    $stmt = $db->prepare("SELECT id FROM po_items WHERE po_id = ? LIMIT 1");
    $stmt->execute([$poId]);
    $poItemId = $stmt->fetchColumn();
    
    // ======= TEST 4: Record GR =======
    echo "[Step 4] Recording GR (Receive 10 items)...\n";
    $grResult = $proc->recordGR($poId, [
        [
            'po_item_id' => $poItemId,
            'received_qty' => 10,
            'condition_note' => 'Good condition'
        ]
    ]);
    
    if (!$grResult['success']) {
        throw new Exception("GR Recording failed: " . $grResult['error']);
    }
    echo "[PASS] GR Created: {$grResult['gr_number']} (ID: {$grResult['id']})\n\n";
    
    // ======= TEST 5: Verify Stock Update =======
    echo "[Step 5] Verifying Stock Update...\n";
    $stmt = $db->prepare("SELECT quantity FROM items WHERE id = ?");
    $stmt->execute([$itemId]);
    $newStock = (float) $stmt->fetchColumn();
    
    $expectedStock = $initialStock + 10;
    if ($newStock >= $expectedStock) {
        echo "[PASS] Stock increased: $initialStock → $newStock (Expected: $expectedStock)\n\n";
    } else {
        echo "[FAIL] Stock mismatch: Got $newStock, Expected $expectedStock\n\n";
    }
    
    echo "=== M2 Test Complete ===\n";
    
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
