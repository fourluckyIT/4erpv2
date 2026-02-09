<?php
/**
 * Test Script: PR > PO > GR Flow + Master Create
 * Creates test items for all 3 types via both GR and Master
 */

require_once __DIR__ . '/../config/bootstrap.php';

$db = getDB();
$audit = new AuditLog();

// Simulate logged-in user (admin)
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'ADM';

echo "=== 4ERP Item Flow Test ===\n\n";

// Get or create a supplier
$supplier = $db->query("SELECT id, code FROM suppliers WHERE is_active = 1 LIMIT 1")->fetch();
if (!$supplier) {
    $db->exec("INSERT INTO suppliers (code, name, is_active, created_by) VALUES ('SUP-TEST', 'Test Supplier', 1, 1)");
    $supplierId = $db->lastInsertId();
    echo "Created supplier: SUP-TEST (ID: $supplierId)\n";
} else {
    $supplierId = $supplier['id'];
    echo "Using supplier: {$supplier['code']} (ID: $supplierId)\n";
}

// Item types to test
$itemTypes = [
    'Device' => ['prefix' => 'DEV', 'name' => 'Test Device from GR', 'unit' => 'เครื่อง', 'serialized' => true],
    'Equipment' => ['prefix' => 'EQP', 'name' => 'Test Equipment from GR', 'unit' => 'ชิ้น', 'serialized' => true],
    'Consumable' => ['prefix' => 'CON', 'name' => 'Test Consumable from GR', 'unit' => 'กล่อง', 'serialized' => false],
];

echo "\n--- PART 1: PR > PO > GR Flow ---\n";

foreach ($itemTypes as $type => $config) {
    echo "\n[$type]\n";
    
    try {
        $db->beginTransaction();
        
        // 1. Create PR
        $prNo = 'PR-TEST-' . date('ymd') . '-' . strtoupper(substr($type, 0, 3));
        $stmt = $db->prepare("INSERT INTO purchase_requests (pr_number, requester_id, purpose, status, created_by) VALUES (?, 1, 'Test item flow', 'Draft', 1)");
        $stmt->execute([$prNo]);
        $prId = $db->lastInsertId();
        echo "  PR Created: $prNo (ID: $prId)\n";
        
        // Add PR item (without item_id - new item)
        $stmt = $db->prepare("INSERT INTO pr_items (pr_id, description, qty, unit, unit_price) VALUES (?, ?, 2, ?, 1000)");
        $stmt->execute([$prId, $config['name'] . ' (' . $type . ')', $config['unit']]);
        $prItemId = $db->lastInsertId();
        echo "  PR Item added: {$config['name']}\n";
        
        // Approve PR
        $db->prepare("UPDATE purchase_requests SET status = 'Approved', approved_by = 1, approved_at = NOW() WHERE id = ?")->execute([$prId]);
        echo "  PR Approved\n";
        
        // 2. Create PO from PR
        $poNo = 'PO-TEST-' . date('ymd') . '-' . strtoupper(substr($type, 0, 3));
        $stmt = $db->prepare("INSERT INTO purchase_orders (po_number, supplier_id, pr_id, po_type, order_date, status, grand_total, created_by) VALUES (?, ?, ?, 'Goods', CURDATE(), 'Draft', 2000, 1)");
        $stmt->execute([$poNo, $supplierId, $prId]);
        $poId = $db->lastInsertId();
        echo "  PO Created: $poNo (ID: $poId)\n";
        
        // Add PO item
        $stmt = $db->prepare("INSERT INTO po_items (po_id, pr_item_id, description, qty, unit, unit_price) VALUES (?, ?, ?, 2, ?, 1000)");
        $stmt->execute([$poId, $prItemId, $config['name'] . ' (' . $type . ')', $config['unit']]);
        $poItemId = $db->lastInsertId();
        echo "  PO Item added\n";
        
        // Approve PO
        $db->prepare("UPDATE purchase_orders SET status = 'Approved', approved_by = 1, approved_at = NOW() WHERE id = ?")->execute([$poId]);
        echo "  PO Approved\n";
        
        // 3. Create GR
        $grNo = 'GR-TEST-' . date('ymd') . '-' . strtoupper(substr($type, 0, 3));
        $stmt = $db->prepare("INSERT INTO goods_receipts (gr_number, po_id, received_by, received_date, status, created_by) VALUES (?, ?, 1, CURDATE(), 'Draft', 1)");
        $stmt->execute([$grNo, $poId]);
        $grId = $db->lastInsertId();
        echo "  GR Created: $grNo (ID: $grId)\n";
        
        // Generate item code (simple format: PREFIX-N)
        $prefix = $config['prefix'];
        $stmtMax = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(code, '-', 2), '-', -1) AS UNSIGNED)) as maxseq FROM items WHERE code REGEXP :pattern");
        $stmtMax->execute([':pattern' => '^' . $prefix . '-[0-9]+']);
        $maxSeq = (int) $stmtMax->fetchColumn();
        $newSeq = $maxSeq + 1;
        $newCode = $prefix . '-' . $newSeq;
        $newCode = ensureUniqueItemCode($db, $newCode);
        
        // Create item with source=GR
        $stmt = $db->prepare("INSERT INTO items (code, name, item_type, unit, is_serialized, cost_price, is_active, source, created_by) VALUES (?, ?, ?, ?, ?, 1000, 1, 'GR', 1)");
        $stmt->execute([$newCode, $config['name'], $type, $config['unit'], $config['serialized'] ? 1 : 0]);
        $itemId = $db->lastInsertId();
        echo "  Item Created: $newCode (ID: $itemId, source: GR)\n";
        
        // Link to PO item
        $db->prepare("UPDATE po_items SET item_id = ? WHERE id = ?")->execute([$itemId, $poItemId]);
        
        // Add GR item (no item_id column - link via po_item_id)
        $serialsJson = '[]';
        if ($config['serialized']) {
            $serialsList = [];
            for ($i = 1; $i <= 2; $i++) {
                $serialsList[] = $newCode . '-SN' . str_pad($i, 3, '0', STR_PAD_LEFT);
            }
            $serialsJson = json_encode($serialsList);
        }
        $stmt = $db->prepare("INSERT INTO gr_items (gr_id, po_item_id, received_qty, serial_numbers) VALUES (?, ?, 2, ?)");
        $stmt->execute([$grId, $poItemId, $serialsJson]);
        $grItemId = $db->lastInsertId();
        
        // Create serials if serialized
        if ($config['serialized']) {
            for ($i = 1; $i <= 2; $i++) {
                $serialNo = $newCode . '-SN' . str_pad($i, 3, '0', STR_PAD_LEFT);
                $stmt = $db->prepare("INSERT INTO serials (item_id, serial_number, status, location, created_by) VALUES (?, ?, 'Available', 'WH', 1)");
                $stmt->execute([$itemId, $serialNo]);
            }
            echo "  Serials Created: 2 units\n";
        }
        
        // Confirm GR
        $db->prepare("UPDATE goods_receipts SET status = 'Confirmed', confirmed_at = NOW(), confirmed_by = 1 WHERE id = ?")->execute([$grId]);
        echo "  GR Completed\n";
        
        // Stock movement
        $stmt = $db->prepare("INSERT INTO stock_movements (item_id, movement_type, qty, from_location, to_location, reference_table, reference_id, created_by) VALUES (?, 'GR_PO', 2, 'SUPPLIER', 'WH', 'goods_receipts', ?, 1)");
        $stmt->execute([$itemId, $grId]);
        echo "  Stock Movement recorded\n";
        
        $db->commit();
        $audit->log('test_flow', 'ITEM', $itemId, null, ['flow' => 'PR>PO>GR', 'type' => $type]);
        echo "  ✓ Complete!\n";
        
    } catch (Exception $e) {
        $db->rollBack();
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n--- PART 2: Master Create ---\n";

$masterTypes = [
    'Device' => ['name' => 'Test Device from Master', 'unit' => 'เครื่อง'],
    'Equipment' => ['name' => 'Test Equipment from Master', 'unit' => 'ชิ้น'],
    'Consumable' => ['name' => 'Test Consumable from Master', 'unit' => 'กล่อง'],
];

foreach ($masterTypes as $type => $config) {
    echo "\n[$type]\n";
    
    try {
        $prefix = match($type) {
            'Device' => 'DEV',
            'Equipment' => 'EQP',
            'Consumable' => 'CON',
            default => 'ITM'
        };
        
        // Generate item code (simple format: PREFIX-N)
        $stmtMax = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(code, '-', 2), '-', -1) AS UNSIGNED)) as maxseq FROM items WHERE code REGEXP :pattern");
        $stmtMax->execute([':pattern' => '^' . $prefix . '-[0-9]+']);
        $maxSeq = (int) $stmtMax->fetchColumn();
        $newSeq = $maxSeq + 1;
        $newCode = $prefix . '-' . $newSeq;
        $newCode = ensureUniqueItemCode($db, $newCode);
        
        // Create item with source=MASTER
        $isSerialized = ($type !== 'Consumable') ? 1 : 0;
        $stmt = $db->prepare("INSERT INTO items (code, name, item_type, unit, is_serialized, cost_price, rental_price_day, is_active, source, created_by) VALUES (?, ?, ?, ?, ?, 1500, 100, 1, 'MASTER', 1)");
        $stmt->execute([$newCode, $config['name'], $type, $config['unit'], $isSerialized]);
        $itemId = $db->lastInsertId();
        echo "  Item Created: $newCode (ID: $itemId, source: MASTER)\n";
        
        $audit->log('create', 'ITEM', $itemId, null, ['code' => $newCode, 'type' => $type, 'source' => 'MASTER']);
        echo "  ✓ Complete!\n";
        
    } catch (Exception $e) {
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n--- SUMMARY ---\n";
$items = $db->query("SELECT code, name, item_type, source, created_at FROM items WHERE code LIKE 'DEV-%' OR code LIKE 'EQP-%' OR code LIKE 'CON-%' ORDER BY created_at DESC LIMIT 10")->fetchAll();
echo "\nLatest Items:\n";
foreach ($items as $i) {
    echo sprintf("  %-10s %-30s %-12s %-8s %s\n", $i['code'], substr($i['name'], 0, 30), $i['item_type'], $i['source'], $i['created_at']);
}

echo "\n✓ Test complete!\n";
