<?php
/**
 * API: Generate Item Code
 * Auto-generates sequential item code based on item_type
 * Format: {PREFIX}-{SEQUENCE}
 *   Device     → DEV-0001
 *   Equipment  → EQP-0001
 *   Vehicle    → VEH-0001
 *   Consumable → CON-0001
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$itemType = get('item_type', '');

// Define prefixes for each type
$prefixes = [
    'Device'     => 'DEV',
    'Equipment'  => 'EQP',
    'Vehicle'    => 'VEH',
    'Consumable' => 'CON',
];

if (!isset($prefixes[$itemType])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid item_type', 'valid_types' => array_keys($prefixes)]);
    exit;
}

$prefix = $prefixes[$itemType];
$db = getDB();

try {
    // Find the highest existing code for this prefix
    // Pattern: PREFIX-NNNN (4 digits)
    $stmt = $db->prepare("
        SELECT code FROM items 
        WHERE code LIKE :pattern 
        ORDER BY code DESC 
        LIMIT 1
    ");
    $stmt->execute(['pattern' => $prefix . '-%']);
    $lastCode = $stmt->fetchColumn();
    
    $nextSeq = 1;
    if ($lastCode) {
        // Extract sequence number from code like "DEV-0001"
        $parts = explode('-', $lastCode);
        if (count($parts) >= 2) {
            $lastSeq = (int) end($parts);
            $nextSeq = $lastSeq + 1;
        }
    }
    
    // Format: PREFIX-0001
    $newCode = sprintf('%s-%04d', $prefix, $nextSeq);
    
    // Double-check this code doesn't exist (edge case)
    $newCode = ensureUniqueItemCode($db, $newCode);
    
    echo json_encode([
        'success' => true,
        'code' => $newCode,
        'item_type' => $itemType,
        'prefix' => $prefix,
        'sequence' => $nextSeq
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
