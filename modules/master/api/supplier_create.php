<?php
/**
 * API: Create Supplier
 * 4ERP
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

$code = trim($input['code'] ?? '');
$name = trim($input['name'] ?? '');
$contactPerson = trim($input['contact_person'] ?? '');
$phone = trim($input['phone'] ?? '');
$supplierType = trim($input['supplier_type'] ?? 'Goods');

$allowedTypes = ['Goods', 'Service', 'Manpower'];
if (!in_array($supplierType, $allowedTypes, true)) {
    $supplierType = 'Goods';
}

if (empty($code) || empty($name)) {
    echo json_encode(['success' => false, 'error' => 'กรุณาระบุรหัสและชื่อผู้ขาย']);
    exit;
}

$db = getDB();

// Check duplicate code
$stmt = $db->prepare("SELECT id FROM suppliers WHERE code = ?");
$stmt->execute([$code]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'รหัสผู้ขายซ้ำ']);
    exit;
}

try {
    $stmt = $db->prepare("
        INSERT INTO suppliers (code, name, supplier_type, contact_name, phone, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$code, $name, $supplierType, $contactPerson, $phone, $_SESSION['user_id']]);
    
    $id = $db->lastInsertId();
    
    $audit = new AuditLog();
    $audit->log('create', 'SUPPLIER', $id, null, ['code' => $code, 'name' => $name, 'supplier_type' => $supplierType]);
    
    echo json_encode(['success' => true, 'id' => $id, 'code' => $code, 'name' => $name, 'supplier_type' => $supplierType]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
