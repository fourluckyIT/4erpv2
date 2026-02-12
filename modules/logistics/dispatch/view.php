<?php
/**
 * Dispatch View - Redirect to Route View
 * 4ERP - Phase 5
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$id = (int) get('id', 0);
if ($id) {
    // Try to find corresponding route and redirect
    $db = getDB();
    $stmt = $db->prepare("SELECT route_id FROM dispatch_notes WHERE id = ?");
    $stmt->execute([$id]);
    $routeId = $stmt->fetchColumn();
    
    if ($routeId) {
        setFlash('info', 'ข้อมูลได้ย้ายไปยังการจัดการ Route แล้ว');
        redirect('../routes/view.php?id=' . $routeId);
    }
}

setFlash('info', 'ระบบจัดส่งได้เปลี่ยนไปใช้การจัดการ Route แทน');
redirect('../dispatch/index.php');
