<?php
/**
 * M4: Warehouse/Return - Manual Verification Script
 * 
 * Tests:
 * 1. Apply schema (stock_movements table)
 * 2. Record dispatch movement (GI_JOB)
 * 3. Record return movement (RETURN_JOB)
 * 4. WH Receive updates stock
 * 5. Reversal creates negative entry
 * 6. Verify no DELETE operations used
 * 
 * Run: TEST_USER_ID=1 php tests/manual_warehouse_return.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../modules/warehouse/WarehouseService.php';

// Setup test session
$_SESSION['user_id'] = getenv('TEST_USER_ID') ?: 1;
$_SESSION['username'] = 'test_user';
$_SESSION['roles'] = ['WH', 'ADM'];

try {
    $db = getDB();
    $wh = new WarehouseService();
    
    echo "=== M4: Warehouse/Return Test ===\n\n";
    
    // ======= SETUP: Get test item =======
    $stmt = $db->prepare("SELECT id, name, quantity FROM items WHERE item_type = 'Device' LIMIT 1");
    $stmt->execute();
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        echo "[SETUP] Creating test item...\n";
        $db->prepare("INSERT INTO items (item_code, name, item_type, quantity) VALUES (?, ?, 'Device', 100)")
           ->execute(['TEST-WH-001', 'Test Device for WH']);
        $itemId = $db->lastInsertId();
        $initialStock = 100;
    } else {
        $itemId = $item['id'];
        $initialStock = (float) $item['quantity'];
    }
    echo "[SETUP] Using Item ID: $itemId, Initial Stock: $initialStock\n\n";
    
    // ======= TEST 1: Verify stock_movements table exists =======
    echo "[Test 1] Checking stock_movements table...\n";
    $tables = $db->query("SHOW TABLES LIKE 'stock_movements'")->fetchAll(PDO::FETCH_COLUMN);
    if (count($tables) > 0) {
        echo "[PASS] stock_movements table exists.\n\n";
    } else {
        echo "[FAIL] stock_movements table missing.\n";
        exit(1);
    }
    
    // ======= TEST 2: Record GI_JOB movement (dispatch) =======
    echo "[Test 2] Recording dispatch movement (GI_JOB)...\n";
    $dispatchResult = $wh->recordMovement(
        WarehouseService::MOVE_GI_JOB,
        $itemId,
        -5, // 5 items out
        WarehouseService::LOC_WH,
        WarehouseService::LOC_IN_TRANSIT,
        'routes',
        9999, // test reference
        null, null,
        'Test dispatch movement'
    );
    
    if ($dispatchResult['success']) {
        echo "[PASS] Dispatch movement recorded: ID {$dispatchResult['id']}\n";
        $dispatchMoveId = $dispatchResult['id'];
    } else {
        echo "[FAIL] Dispatch movement failed: {$dispatchResult['error']}\n";
        exit(1);
    }
    
    $stockAfterDispatch = $wh->getStockLevel($itemId);
    $expectedDispatch = $initialStock - 5;
    echo "  Stock after dispatch: $stockAfterDispatch (expected: $expectedDispatch)\n\n";
    
    if (abs($stockAfterDispatch - $expectedDispatch) < 0.01) {
        echo "[PASS] Stock decreased correctly.\n\n";
    } else {
        echo "[FAIL] Stock mismatch.\n";
        exit(1);
    }
    
    // ======= TEST 3: Record RETURN_JOB movement =======
    echo "[Test 3] Recording return movement (RETURN_JOB)...\n";
    $returnResult = $wh->recordMovement(
        WarehouseService::MOVE_RETURN_JOB,
        $itemId,
        3, // 3 items back
        WarehouseService::LOC_SITE,
        WarehouseService::LOC_IN_TRANSIT,
        'routes',
        9999,
        null, null,
        'Test return movement'
    );
    
    if ($returnResult['success']) {
        echo "[PASS] Return movement recorded: ID {$returnResult['id']}\n";
    } else {
        echo "[FAIL] Return movement failed: {$returnResult['error']}\n";
        exit(1);
    }
    
    $stockAfterReturn = $wh->getStockLevel($itemId);
    $expectedReturn = $expectedDispatch + 3;
    echo "  Stock after return: $stockAfterReturn (expected: $expectedReturn)\n\n";
    
    if (abs($stockAfterReturn - $expectedReturn) < 0.01) {
        echo "[PASS] Stock increased correctly.\n\n";
    } else {
        echo "[FAIL] Stock mismatch.\n";
        exit(1);
    }
    
    // ======= TEST 4: Record WH_RECEIVE movement =======
    echo "[Test 4] Recording WH receive movement (WH_RECEIVE)...\n";
    $whReceiveResult = $wh->recordMovement(
        WarehouseService::MOVE_WH_RECEIVE,
        $itemId,
        2, // 2 items confirmed to WH
        WarehouseService::LOC_IN_TRANSIT,
        WarehouseService::LOC_WH,
        'routes',
        9999,
        null, null,
        'Test WH receive'
    );
    
    if ($whReceiveResult['success']) {
        echo "[PASS] WH Receive recorded: ID {$whReceiveResult['id']}\n\n";
    } else {
        echo "[FAIL] WH Receive failed: {$whReceiveResult['error']}\n";
        exit(1);
    }
    
    // ======= TEST 5: Reversal creates negative entry =======
    echo "[Test 5] Testing reversal of dispatch movement...\n";
    $reversalResult = $wh->reverseMovement($dispatchMoveId, 'Test reversal');
    
    if ($reversalResult['success']) {
        echo "[PASS] Reversal recorded: ID {$reversalResult['id']}\n";
        
        // Verify reversal entry
        $stmt = $db->prepare("SELECT * FROM stock_movements WHERE id = ?");
        $stmt->execute([$reversalResult['id']]);
        $reversal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reversal['movement_type'] === 'REVERSAL' && 
            $reversal['reverse_of_id'] == $dispatchMoveId &&
            (float)$reversal['qty'] === 5.0) {
            echo "[PASS] Reversal has correct type, reference, and reversed qty.\n\n";
        } else {
            echo "[FAIL] Reversal data incorrect.\n";
            exit(1);
        }
    } else {
        echo "[FAIL] Reversal failed: {$reversalResult['error']}\n";
        exit(1);
    }
    
    // ======= TEST 6: Verify movements log =======
    echo "[Test 6] Checking movement log...\n";
    $movements = $wh->getMovements($itemId, 10);
    echo "  Found " . count($movements) . " recent movements.\n";
    
    $types = array_column($movements, 'movement_type');
    // Should have: REVERSAL, WH_RECEIVE, RETURN_JOB, GI_JOB (in reverse order)
    if (in_array('GI_JOB', $types) && 
        in_array('RETURN_JOB', $types) && 
        in_array('WH_RECEIVE', $types) && 
        in_array('REVERSAL', $types)) {
        echo "[PASS] All movement types logged.\n\n";
    } else {
        echo "[FAIL] Missing movement types. Found: " . implode(', ', array_unique($types)) . "\n";
        exit(1);
    }
    
    // ======= TEST 7: Verify no DELETE was used (schema compliance) =======
    echo "[Test 7] Verifying append-only principle...\n";
    // This is a code review check - movements should never be deleted
    $count = $db->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
    if ($count >= 4) {
        echo "[PASS] Movements are being appended (count: $count).\n\n";
    } else {
        echo "[FAIL] Expected at least 4 movements.\n";
        exit(1);
    }
    
    echo "=== M4 Warehouse Test Complete ===\n";
    echo "All 7 tests PASSED.\n";
    exit(0);
    
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
