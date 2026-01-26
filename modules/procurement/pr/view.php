<?php
/**
 * View PR
 * ERP v2 - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
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
        setFlash('success', 'ส่งอนุมัติเรียบร้อย');
        
    } elseif ($action === 'approve' && $pr['status'] === 'Submitted') {
        // Check permission
        if (!in_array('ADM', $_SESSION['roles']) && !in_array('MGR', $_SESSION['roles'])) {
            setFlash('error', 'คุณไม่มีสิทธิ์อนุมัติ');
            redirect("view.php?id=$id");
        }
        
        $db->prepare("
            UPDATE purchase_requests 
            SET status = 'Approved', approved_at = NOW(), approved_by = ?
            WHERE id = ?
        ")->execute([$_SESSION['user_id'], $id]);
        
        $audit->log('approve', 'PR', $id);
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
        setFlash('success', 'ปฏิเสธเรียบร้อย');
        
    } elseif ($action === 'cancel') {
        $db->prepare("UPDATE purchase_requests SET status = 'Cancelled' WHERE id = ?")->execute([$id]);
        $audit->log('cancel', 'PR', $id);
        setFlash('success', 'ยกเลิกเรียบร้อย');
    }
    
    redirect("view.php?id=$id");
}

$pageTitle = "PR: {$pr['pr_number']} - ERP v2";
require_once __DIR__ . '/../../../includes/header.php';
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
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="index.php">PR</a></li>
                    <li class="breadcrumb-item active"><?= e($pr['pr_number']) ?></li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary">
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
            
            <?php if ($pr['status'] === 'Submitted' && (in_array('ADM', $_SESSION['roles']) || in_array('MGR', $_SESSION['roles']))): ?>
            <button type="submit" name="action" value="approve" class="btn btn-success" onclick="return confirm('ยืนยันอนุมัติ?')">
                <i class="bi bi-check-circle me-1"></i>อนุมัติ
            </button>
            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                <i class="bi bi-x-circle me-1"></i>ไม่อนุมัติ
            </button>
            <?php endif; ?>
            
            <?php if ($pr['status'] === 'Approved'): ?>
            <a href="../po/create.php?pr_id=<?= $id ?>" class="btn btn-info text-white">
                <i class="bi bi-plus-circle me-1"></i>สร้าง PO จาก PR นี้
            </a>
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

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
