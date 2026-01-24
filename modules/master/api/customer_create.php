<?php
/**
 * API: Create Customer
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

$code = trim($input['code'] ?? '');
$name = trim($input['name'] ?? '');
$contactPerson = trim($input['contact_person'] ?? '');
$phone = trim($input['phone'] ?? '');

if (empty($code) || empty($name)) {
    echo json_encode(['success' => false, 'error' => 'กรุณาระบุรหัสและชื่อลูกค้า']);
    exit;
}

$db = getDB();

// Check duplicate code
$stmt = $db->prepare("SELECT id FROM customers WHERE code = ?");
$stmt->execute([$code]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'รหัสลูกค้าซ้ำ']);
    exit;
}

try {
    $stmt = $db->prepare("
        INSERT INTO customers (code, name, contact_person, phone, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$code, $name, $contactPerson, $phone, $_SESSION['user_id']]);
    
    $id = $db->lastInsertId();
    
    $audit = new AuditLog();
    $audit->log('create', 'CUSTOMER', $id, null, ['code' => $code, 'name' => $name]);
    
    echo json_encode(['success' => true, 'id' => $id, 'code' => $code, 'name' => $name]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
