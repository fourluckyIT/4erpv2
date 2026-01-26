<?php
/**
 * API: Create Vehicle (Item + Serial)
 * ERP v2
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$serialNumber = trim($input['serial_number'] ?? '');
$name = trim($input['name'] ?? '');
$licensePlate = trim($input['license_plate'] ?? '');

if (empty($serialNumber) || empty($name)) {
    echo json_encode(['success' => false, 'error' => 'กรุณาระบุทะเบียนและชื่อรถ']);
    exit;
}

$db = getDB();

// Check duplicate serial
$stmt = $db->prepare("SELECT id FROM serials WHERE serial_number = ?");
$stmt->execute([$serialNumber]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'ทะเบียน/Serial ซ้ำ']);
    exit;
}

try {
    $db->beginTransaction();
    
    // Generate vehicle code
    $stmt = $db->query("SELECT COUNT(*) FROM items WHERE item_type = 'Vehicle'");
    $count = $stmt->fetchColumn() + 1;
    $code = 'VEH' . str_pad($count, 4, '0', STR_PAD_LEFT);
    
    // Create item (Vehicle type)
    $stmt = $db->prepare("
        INSERT INTO items (code, name, item_type, unit, is_active, created_by)
        VALUES (?, ?, 'Vehicle', 'คัน', 1, ?)
    ");
    $stmt->execute([$code, $name, $_SESSION['user_id']]);
    $itemId = $db->lastInsertId();
    
    // Create serial
    $stmt = $db->prepare("
        INSERT INTO serials (item_id, serial_number, license_plate, status, created_by)
        VALUES (?, ?, ?, 'Available', ?)
    ");
    $stmt->execute([$itemId, $serialNumber, $licensePlate ?: null, $_SESSION['user_id']]);
    $serialId = $db->lastInsertId();
    
    $db->commit();
    
    $audit = new AuditLog();
    $audit->log('create', 'VEHICLE', $serialId, null, ['serial' => $serialNumber, 'name' => $name]);
    
    echo json_encode([
        'success' => true, 
        'id' => $serialId, 
        'item_id' => $itemId,
        'serial_number' => $serialNumber, 
        'name' => $name
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
