<?php
/**
 * View PR
 * 4ERP - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
$db = getDB();
$audit = new AuditLog();
$notification = new Notification();
$id = (int) get('id');

if (!$id) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get PR
$stmt = $db->prepare("
    SELECT pr.*, 
           u.full_name as requester_name,
           a.full_name as approver_name,
           j.job_number
    FROM purchase_requests pr
    JOIN users u ON pr.requester_id = u.id
    LEFT JOIN users a ON pr.approved_by = a.id
    LEFT JOIN jobs j ON pr.job_id = j.id
    WHERE pr.id = ?
");
$stmt->execute([$id]);
$pr = $stmt->fetch();

if (!$pr) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get items
$items = $db->prepare("
    SELECT pri.*, i.code as item_code
    FROM pr_items pri
    LEFT JOIN items i ON pri.item_id = i.id
    WHERE pri.pr_id = ?
");
$items->execute([$id]);
$items = $items->fetchAll();

// Check existing PO(s) for this PR
$poStmt = $db->prepare("
    SELECT id, po_number, status
    FROM purchase_orders
    WHERE pr_id = ?
    ORDER BY id DESC
");
$poStmt->execute([$id]);
$poRows = $poStmt->fetchAll();
$activePo = null;
foreach ($poRows as $poRow) {
    $poStatus = trim((string) ($poRow['status'] ?? ''));
    $isInactivePo = in_array(strtoupper($poStatus), ['CANCELLED', 'CANCELED', 'VOIDED'], true);
    if (!$isInactivePo) {
        $activePo = $poRow;
        break;
    }
}
$hasActivePo = $activePo !== null;
$prStatus = trim((string) ($pr['status'] ?? ''));
$isApproved = strcasecmp($prStatus, 'Approved') === 0;
$roleCodes = array_map('strtoupper', $auth->getCurrentRoles() ?? []);
$hasPurchaseRole = in_array('PUR', $roleCodes, true) || in_array('PURCHASE', $roleCodes, true);
$hasManagerRole = in_array('MGR', $roleCodes, true) || in_array('MANAGER', $roleCodes, true);
$canViewPo = $rbac->can('view', 'PO') || $hasPurchaseRole || $hasManagerRole || $auth->isAdmin();
$canCreatePo = $rbac->can('create', 'PO') || $hasPurchaseRole || $hasManagerRole || $auth->isAdmin();

// Handle actions
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("view.php?id=$id");
    }
    
    $action = post('action');
    
    if ($action === 'submit' && $pr['status'] === 'Draft') {
        $db->prepare("
            UPDATE purchase_requests 
            SET status = 'Submitted', submitted_at = NOW(), submitted_by = ?
            WHERE id = ?
        ")->execute([$_SESSION['user_id'], $id]);
        
        $audit->log('submit', 'PR', $id);
        $approverIds = $rbac->getUserIdsWithPermission('approve', 'PR', 'Submitted');
        if (!empty($approverIds)) {
            $title = "PR {$pr['pr_number']} รออนุมัติ";
            $message = $pr['job_number'] ? "Job: {$pr['job_number']}" : "มี PR ใหม่รออนุมัติ";
            $url = "/4erpv2/modules/procurement/pr/view.php?id={$id}";
            $notification->createBulk(
                $approverIds,
                Notification::TYPE_APPROVAL_REQUEST,
                $title,
                $message,
                $url,
                'PR',
                $id,
                Notification::PRIORITY_HIGH
            );
        }
        setFlash('success', 'ส่งอนุมัติเรียบร้อย');
        
    } elseif ($action === 'approve' && $pr['status'] === 'Submitted') {
        // Check permission
        if (!$rbac->can('approve', 'PR', $pr['status'])) {
            setFlash('error', 'คุณไม่มีสิทธิ์อนุมัติ');
            redirect("view.php?id=$id");
        }
        
        $db->prepare("
            UPDATE purchase_requests 
            SET status = 'Approved', approved_at = NOW(), approved_by = ?
            WHERE id = ?
        ")->execute([$_SESSION['user_id'], $id]);
        
        $audit->log('approve', 'PR', $id);
        $notification->markReadByEntity('PR', $id, Notification::TYPE_APPROVAL_REQUEST);
        $requesterId = $pr['submitted_by'] ?? $pr['requester_id'];
        if (!empty($requesterId)) {
            $title = "PR {$pr['pr_number']} อนุมัติแล้ว";
            $message = $pr['job_number'] ? "Job: {$pr['job_number']}" : "คำขอได้รับอนุมัติ";
            $url = "/4erpv2/modules/procurement/pr/view.php?id={$id}";
            $notification->create(
                (int) $requesterId,
                Notification::TYPE_APPROVAL_RESULT,
                $title,
                $message,
                $url,
                'PR',
                $id,
                Notification::PRIORITY_NORMAL
            );
        }
        setFlash('success', 'อนุมัติเรียบร้อย');
        
    } elseif ($action === 'reject' && $pr['status'] === 'Submitted') {
        $reason = post('reject_reason');
        if (empty($reason)) {
            setFlash('error', 'กรุณาระบุเหตุผล');
            redirect("view.php?id=$id");
        }
        
        $db->prepare("
            UPDATE purchase_requests 
            SET status = 'Rejected', rejected_at = NOW(), rejected_by = ?, reject_reason = ?
            WHERE id = ?
        ")->execute([$_SESSION['user_id'], $reason, $id]);
        
        $audit->log('reject', 'PR', $id, null, ['reason' => $reason]);
        $notification->markReadByEntity('PR', $id, Notification::TYPE_APPROVAL_REQUEST);
        $requesterId = $pr['submitted_by'] ?? $pr['requester_id'];
        if (!empty($requesterId)) {
            $title = "PR {$pr['pr_number']} ถูกปฏิเสธ";
            $message = "เหตุผล: {$reason}";
            $url = "/4erpv2/modules/procurement/pr/view.php?id={$id}";
            $notification->create(
                (int) $requesterId,
                Notification::TYPE_APPROVAL_RESULT,
                $title,
                $message,
                $url,
                'PR',
                $id,
                Notification::PRIORITY_HIGH
            );
        }
        setFlash('success', 'ปฏิเสธเรียบร้อย');
        
    } elseif ($action === 'cancel') {
        $db->prepare("UPDATE purchase_requests SET status = 'Cancelled' WHERE id = ?")->execute([$id]);
        $audit->log('cancel', 'PR', $id);
        $notification->markReadByEntity('PR', $id, Notification::TYPE_APPROVAL_REQUEST);
        $requesterId = $pr['submitted_by'] ?? $pr['requester_id'];
        if (!empty($requesterId)) {
            $title = "PR {$pr['pr_number']} ถูกยกเลิก";
            $message = $pr['job_number'] ? "Job: {$pr['job_number']}" : "คำขอถูกยกเลิก";
            $url = "/4erpv2/modules/procurement/pr/view.php?id={$id}";
            $notification->create(
                (int) $requesterId,
                Notification::TYPE_APPROVAL_RESULT,
                $title,
                $message,
                $url,
                'PR',
                $id,
                Notification::PRIORITY_NORMAL
            );
        }
        setFlash('success', 'ยกเลิกเรียบร้อย');
    }
    
    redirect("view.php?id=$id");
}

$pageTitle = "PR: {$pr['pr_number']} - 4ERP";
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-file-text me-2"></i><?= e($pr['pr_number']) ?>
                <span class="badge bg-<?= match($pr['status']) {
                    'Draft' => 'secondary',
                    'Submitted' => 'warning text-dark',
                    'Approved' => 'success',
                    'Rejected' => 'danger',
                    'Cancelled' => 'dark',
                    default => 'secondary'
                } ?> ms-2"><?= match($pr['status']) {
                    'Draft' => 'แบบร่าง',
                    'Submitted' => 'รออนุมัติ',
                    'Approved' => 'อนุมัติแล้ว',
                    'Rejected' => 'ไม่อนุมัติ',
                    'Cancelled' => 'ยกเลิก',
                    default => $pr['status']
                } ?></span>
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <?php $canBreadcrumbLinks = ($auth->isAdmin() || $auth->hasRole(ROLE_PURCHASE) || $auth->hasRole(ROLE_MANAGER) || $auth->hasRole(ROLE_ACCOUNTANT)); ?>
                    <li class="breadcrumb-item">
                        <?php if ($canBreadcrumbLinks): ?>
                            <a href="../">Procurement</a>
                        <?php else: ?>
                            <span>Procurement</span>
                        <?php endif; ?>
                    </li>
                    <li class="breadcrumb-item">
                        <?php if ($canBreadcrumbLinks): ?>
                            <a href="index.php">PR</a>
                        <?php else: ?>
                            <span>PR</span>
                        <?php endif; ?>
                    </li>
                    <li class="breadcrumb-item active"><?= e($pr['pr_number']) ?></li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="javascript:history.back()" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
        </div>
    </div>
</div>

<!-- Actions -->
<?php if ($pr['status'] !== 'Cancelled'): ?>
<div class="card mb-4">
    <div class="card-body">
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            
            <?php if ($pr['status'] === 'Draft'): ?>
            <a href="edit.php?id=<?= $id ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>แก้ไข
            </a>
            <button type="submit" name="action" value="submit" class="btn btn-primary" onclick="return confirm('ยืนยันส่งอนุมัติ?')">
                <i class="bi bi-send me-1"></i>ส่งอนุมัติ
            </button>
            <button type="submit" name="action" value="cancel" class="btn btn-outline-danger" onclick="return confirm('ยืนยันยกเลิก?')">
                <i class="bi bi-x-circle me-1"></i>ยกเลิก
            </button>
            <?php endif; ?>
            
            <?php if ($pr['status'] === 'Submitted' && $rbac->can('approve', 'PR', $pr['status'])): ?>
            <button type="submit" name="action" value="approve" class="btn btn-success" onclick="return confirm('ยืนยันอนุมัติ?')">
                <i class="bi bi-check-circle me-1"></i>อนุมัติ
            </button>
            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                <i class="bi bi-x-circle me-1"></i>ไม่อนุมัติ
            </button>
            <?php endif; ?>
            
            <?php 
            // Check if PR is approved (robust check)
            $statusApproved = ($isApproved || strtoupper(trim($pr['status'])) === 'APPROVED');
            ?>
            <?php if ($statusApproved): ?>
                <?php if ($hasActivePo): ?>
                    <?php if ($canViewPo): ?>
                        <a href="../po/view.php?id=<?= $activePo['id'] ?>" class="btn btn-info text-white">
                            <i class="bi bi-box-arrow-up-right me-1"></i>เปิด PO
                        </a>
                    <?php endif; ?>
                    <span class="text-muted ms-2">
                        มี PO แล้ว: <span class="text-decoration-none"><?= e($activePo['po_number']) ?></span>
                    </span>
                <?php else: ?>
                    <?php if ($canCreatePo): ?>
                        <a href="../po/create.php?pr_id=<?= $id ?>" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>สร้าง PO จาก PR นี้
                        </a>
                    <?php else: ?>
                        <span class="text-muted">ยังไม่มี PO (ไม่มีสิทธิ์สร้าง PO)</span>
                    <?php endif; ?>
                <?php endif; ?>
            <?php elseif ($canCreatePo): ?>
                <button type="button" class="btn btn-outline-secondary" disabled>
                    <i class="bi bi-plus-circle me-1"></i>สร้าง PO จาก PR นี้
                </button>
                <span class="text-muted ms-2">ต้องอนุมัติ PR ก่อน</span>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- PR Info -->
<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">ข้อมูลทั่วไป</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="120">เลขที่ PR</th>
                        <td><?= e($pr['pr_number']) ?></td>
                    </tr>
                    <tr>
                        <th>Job</th>
                        <td><?= $pr['job_number'] ? e($pr['job_number']) : '<span class="text-muted">-</span>' ?></td>
                    </tr>
                    <tr>
                        <th>วัตถุประสงค์</th>
                        <td><?= nl2br(e($pr['purpose'])) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่ต้องการ</th>
                        <td><?= $pr['required_date'] ? formatDate($pr['required_date']) : '-' ?></td>
                    </tr>
                    <tr>
                        <th>หมายเหตุ</th>
                        <td><?= $pr['notes'] ? nl2br(e($pr['notes'])) : '-' ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">ข้อมูลการอนุมัติ</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="120">ผู้ขอ</th>
                        <td><?= e($pr['requester_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่สร้าง</th>
                        <td><?= formatDateTime($pr['created_at']) ?></td>
                    </tr>
                    <?php if ($pr['submitted_at']): ?>
                    <tr>
                        <th>วันที่ส่ง</th>
                        <td><?= formatDateTime($pr['submitted_at']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($pr['approved_at']): ?>
                    <tr>
                        <th>ผู้อนุมัติ</th>
                        <td><?= e($pr['approver_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่อนุมัติ</th>
                        <td><?= formatDateTime($pr['approved_at']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($pr['reject_reason']): ?>
                    <tr>
                        <th>เหตุผลปฏิเสธ</th>
                        <td class="text-danger"><?= nl2br(e($pr['reject_reason'])) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Items -->
<div class="card mb-4">
    <div class="card-header">รายการ</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>รหัสสินค้า</th>
                        <th>รายละเอียด</th>
                        <th class="text-center">จำนวน</th>
                        <th>หน่วย</th>
                        <th class="text-end">ราคา/หน่วย</th>
                        <th class="text-end">รวม</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $item): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= $item['item_code'] ? e($item['item_code']) : '-' ?></td>
                        <td><?= e($item['description']) ?></td>
                        <td class="text-center"><?= formatNumber($item['qty'], 0) ?></td>
                        <td><?= e($item['unit']) ?></td>
                        <td class="text-end"><?= formatNumber($item['unit_price']) ?></td>
                        <td class="text-end"><?= formatNumber($item['amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6" class="text-end"><strong>รวมทั้งสิ้น</strong></td>
                        <td class="text-end"><strong><?= formatNumber($pr['total_amount']) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="reject">
                <div class="modal-header">
                    <h5 class="modal-title">ไม่อนุมัติ PR</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">เหตุผลที่ไม่อนุมัติ <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="reject_reason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-danger">ยืนยัน</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
