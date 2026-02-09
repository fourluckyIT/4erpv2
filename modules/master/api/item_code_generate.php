<?php
/**
 * API: Generate Item Code
 * Auto-generates sequential item code based on item_type
 * Format: {PREFIX}-{SEQUENCE}
 *   Device     → DEV-1, DEV-2, ...
 *   Equipment  → EQP-1, EQP-2, ...
 *   Vehicle    → VEH-1, VEH-2, ...
 *   Consumable → CON-1, CON-2, ...
 * If duplicate: DEV-1-1, DEV-1-2, ...
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

// Define document types for each item type
$docTypes = [
    'Device'     => 'DEV',
    'Equipment'  => 'EQP',
    'Vehicle'    => 'VEH',
    'Consumable' => 'CON',
];

if (!isset($docTypes[$itemType])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid item_type', 'valid_types' => array_keys($docTypes)]);
    exit;
}

$docType = $docTypes[$itemType];
$db = getDB();
$docNum = new DocumentNumber();

try {
    $newCode = null;
    for ($i = 0; $i < 5; $i++) {
        $candidate = $docNum->generate($docType);
        $check = $db->prepare("SELECT 1 FROM items WHERE code = ?");
        $check->execute([$candidate]);
        if (!$check->fetch()) {
            $newCode = $candidate;
            break;
        }
    }
    if ($newCode === null) {
        throw new Exception('ไม่สามารถสร้างรหัสได้');
    }

    $setting = $docNum->getSetting($docType) ?: [];
    $prefix = $setting['prefix'] ?? ($docType . '-');
    $padding = (int) ($setting['padding'] ?? 5);
    $year = (int) date('Y');
    $sequence = null;
    if (preg_match('/(\\d+)$/', $newCode, $m)) {
        $sequence = (int) $m[1];
    }
    
    echo json_encode([
        'success' => true,
        'code' => $newCode,
        'item_type' => $itemType,
        'doc_type' => $docType,
        'prefix' => $prefix,
        'sequence' => $sequence,
        'year' => $year,
        'padding' => $padding
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
