<?php
/**
 * Approve/Reject OT
 * 4ERP - HR Module
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

// Check permission
if (!$auth->hasRole(ROLE_MANAGER) && !$auth->isAdmin()) {
    setFlash('error', 'คุณไม่มีสิทธิ์อนุมัติ OT');
    redirect('index.php');
}

$db = getDB();
$audit = new AuditLog();

if (!isPost()) {
    redirect('index.php');
}

if (!verifyCsrf(post('csrf_token', ''))) {
    setFlash('error', 'Invalid request');
    redirect('index.php');
}

$otId = (int) post('ot_id');
$action = post('action');

if (!$otId) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get OT record
$stmt = $db->prepare("SELECT * FROM people_overtime WHERE id = ? AND status = 'Pending'");
$stmt->execute([$otId]);
$ot = $stmt->fetch();

if (!$ot) {
    setFlash('error', 'ไม่พบ OT หรือไม่อยู่ในสถานะรออนุมัติ');
    redirect('index.php');
}

try {
    if ($action === 'approve') {
        $stmt = $db->prepare("
            UPDATE people_overtime 
            SET status = 'Approved', approved_by = ?, approved_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $otId]);
        
        $audit->log('approve', 'OVERTIME', $otId);
        setFlash('success', 'อนุมัติ OT เรียบร้อย');
        
    } elseif ($action === 'reject') {
        $reason = post('rejection_reason', 'ไม่ระบุเหตุผล');
        
        $stmt = $db->prepare("
            UPDATE people_overtime 
            SET status = 'Rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ?
            WHERE id = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $reason, $otId]);
        
        $audit->log('reject', 'OVERTIME', $otId, null, ['reason' => $reason]);
        setFlash('success', 'ปฏิเสธ OT เรียบร้อย');
    }
    
} catch (Exception $e) {
    setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
}

redirect('index.php?status=Pending');
