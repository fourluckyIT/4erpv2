<?php
/**
 * View PO
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

// Get PO
$stmt = $db->prepare("
    SELECT po.*,
           s.name as supplier_name, s.code as supplier_code,
           pr.pr_number,
           u.full_name as created_by_name,
           a.full_name as approved_by_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_requests pr ON po.pr_id = pr.id
    LEFT JOIN users u ON po.created_by = u.id
    LEFT JOIN users a ON po.approved_by = a.id
    WHERE po.id = ?
");
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get items
$items = $db->prepare("
    SELECT poi.*, i.code as item_code
    FROM po_items poi
    LEFT JOIN items i ON poi.item_id = i.id
    WHERE poi.po_id = ?
");
$items->execute([$id]);
$items = $items->fetchAll();

// Get GRs
$grs = $db->prepare("
    SELECT gr.*, u.full_name as received_by_name
    FROM goods_receipts gr
    LEFT JOIN users u ON gr.received_by = u.id
    WHERE gr.po_id = ?
    ORDER BY gr.received_date DESC
");
$grs->execute([$id]);
$grs = $grs->fetchAll();

// Handle actions
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("view.php?id=$id");
    }

    $action = post('action');

    if ($action === 'submit' && $po['status'] === 'Draft') {
        $db->prepare("
            UPDATE purchase_orders
            SET status = 'Submitted', submitted_at = NOW(), submitted_by = ?
            WHERE id = ?
        ")->execute([$_SESSION['user_id'], $id]);

        $audit->log('submit', 'PO', $id);
        $approverIds = $rbac->getUserIdsWithPermission('approve', 'PO', 'Submitted');
        if (!empty($approverIds)) {
            $title = "PO {$po['po_number']} รออนุมัติ";
            $message = "Supplier: {$po['supplier_name']}";
            $url = "/4erpv2/modules/procurement/po/view.php?id={$id}";
            $notification->createBulk(
                $approverIds,
                Notification::TYPE_APPROVAL_REQUEST,
                $title,
                $message,
                $url,
                'PO',
                $id,
                Notification::PRIORITY_HIGH
            );
        }
        setFlash('success', 'ส่งอนุมัติเรียบร้อย');

    } elseif ($action === 'approve' && $po['status'] === 'Submitted') {
        if (!$rbac->can('approve', 'PO', $po['status'])) {
            setFlash('error', 'คุณไม่มีสิทธิ์อนุมัติ');
            redirect("view.php?id=$id");
        }
        
        $db->prepare("
            UPDATE purchase_orders
            SET status = 'Approved', approved_at = NOW(), approved_by = ?
            WHERE id = ?
        ")->execute([$_SESSION['user_id'], $id]);

        $audit->log('approve', 'PO', $id);
        $notification->markReadByEntity('PO', $id, Notification::TYPE_APPROVAL_REQUEST);
        $requesterId = $po['submitted_by'] ?? $po['created_by'];
        if (!empty($requesterId)) {
            $title = "PO {$po['po_number']} อนุมัติแล้ว";
            $message = "Supplier: {$po['supplier_name']}";
            $url = "/4erpv2/modules/procurement/po/view.php?id={$id}";
            $notification->create(
                (int) $requesterId,
                Notification::TYPE_APPROVAL_RESULT,
                $title,
                $message,
                $url,
                'PO',
                $id,
                Notification::PRIORITY_NORMAL
            );
        }
        setFlash('success', 'อนุมัติเรียบร้อย');

    } elseif ($action === 'cancel' && in_array($po['status'], ['Draft', 'Submitted'])) {
        $reason = trim((string) post('cancel_reason', ''));
        if ($reason === '') {
            setFlash('error', 'กรุณาระบุเหตุผล');
            redirect("view.php?id=$id");
        }

        $db->prepare("UPDATE purchase_orders SET status = 'Cancelled' WHERE id = ?")->execute([$id]);
        $audit->log('cancel', 'PO', $id, null, ['status' => 'Cancelled'], $reason);
        $notification->markReadByEntity('PO', $id, Notification::TYPE_APPROVAL_REQUEST);
        $requesterId = $po['submitted_by'] ?? $po['created_by'];
        if (!empty($requesterId)) {
            $title = "PO {$po['po_number']} ถูกยกเลิก";
            $message = "Supplier: {$po['supplier_name']}";
            if ($reason !== '') {
                $message .= "\nเหตุผล: {$reason}";
            }
            $url = "/4erpv2/modules/procurement/po/view.php?id={$id}";
            $notification->create(
                (int) $requesterId,
                Notification::TYPE_APPROVAL_RESULT,
                $title,
                $message,
                $url,
                'PO',
                $id,
                Notification::PRIORITY_NORMAL
            );
        }
        setFlash('success', 'ยกเลิกเรียบร้อย');
    }

    redirect("view.php?id=$id");
}

$pageTitle = "PO: {$po['po_number']} - 4ERP";
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-receipt me-2"></i><?= e($po['po_number']) ?>
                <span class="badge bg-<?= match($po['status']) {
                    'Draft' => 'secondary',
                    'Submitted' => 'warning text-dark',
                    'Approved' => 'primary',
                    'Partially Received' => 'info',
                    'Received' => 'success',
                    'Cancelled' => 'dark',
                    default => 'secondary'
                } ?> ms-2"><?= match($po['status']) {
                    'Draft' => 'แบบร่าง',
                    'Submitted' => 'รออนุมัติ',
                    'Approved' => 'รอรับของ',
                    'Partially Received' => 'รับบางส่วน',
                    'Received' => 'รับครบ',
                    'Cancelled' => 'ยกเลิก',
                    default => $po['status']
                } ?></span>
                <span class="badge bg-<?= match($po['po_type']) {
                    'Goods' => 'primary',
                    'Service' => 'info',
                    'Manpower' => 'warning text-dark',
                    default => 'secondary'
                } ?>"><?= match($po['po_type']) {
                    'Goods' => 'สินค้า',
                    'Service' => 'บริการ',
                    'Manpower' => 'แรงงาน',
                    default => $po['po_type']
                } ?></span>
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="index.php">PO</a></li>
                    <li class="breadcrumb-item active"><?= e($po['po_number']) ?></li>
                </ol>
            </nav>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<!-- Actions -->
<?php if (!in_array($po['status'], ['Cancelled', 'Received'])): ?>
<div class="card mb-4">
    <div class="card-body">
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <?php if ($po['status'] === 'Draft'): ?>
            <button type="submit" name="action" value="submit" class="btn btn-primary" onclick="return confirm('ยืนยันส่งอนุมัติ?')">
                <i class="bi bi-send me-1"></i>ส่งอนุมัติ
            </button>
            <input type="hidden" name="cancel_reason" id="cancelReasonDraft" value="">
            <button type="button" class="btn btn-outline-danger" onclick="return promptCancelReason('cancelReasonDraft')">
                <i class="bi bi-x-circle me-1"></i>ยกเลิก
            </button>
            <?php endif; ?>

            <?php if ($po['status'] === 'Submitted' && $rbac->can('approve', 'PO', $po['status'])): ?>
            <button type="submit" name="action" value="approve" class="btn btn-success" onclick="return confirm('ยืนยันอนุมัติ?')">
                <i class="bi bi-check-circle me-1"></i>อนุมัติ
            </button>
            <input type="hidden" name="cancel_reason" id="cancelReasonSubmitted" value="">
            <button type="button" class="btn btn-outline-danger" onclick="return promptCancelReason('cancelReasonSubmitted')">
                <i class="bi bi-x-circle me-1"></i>ยกเลิก
            </button>
            <?php endif; ?>

            <?php if (in_array($po['status'], ['Approved', 'Partially Received'])): ?>
            <a href="../gr/create.php?po_id=<?= $id ?>" class="btn btn-info text-white">
                <i class="bi bi-box-seam me-1"></i>รับของ (GR)
            </a>
            <?php endif; ?>

            <?php if ($po['po_type'] === 'Manpower' && $po['status'] === 'Approved'): ?>
            <a href="manpower.php?id=<?= $id ?>" class="btn btn-warning">
                <i class="bi bi-people me-1"></i>จัดการแรงงาน
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- PO Info -->
<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">ข้อมูลทั่วไป</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="120">เลขที่ PO</th>
                        <td><?= e($po['po_number']) ?></td>
                    </tr>
                    <?php if ($po['pr_number']): ?>
                    <tr>
                        <th>จาก PR</th>
                        <td>
                            <a href="../pr/view.php?id=<?= $po['pr_id'] ?>" class="badge bg-secondary">
                                <?= e($po['pr_number']) ?>
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>ผู้ขาย</th>
                        <td><strong><?= e($po['supplier_code']) ?></strong> - <?= e($po['supplier_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่สั่ง</th>
                        <td><?= formatDate($po['order_date']) ?></td>
                    </tr>
                    <tr>
                        <th>วันส่งของ</th>
                        <td><?= $po['delivery_date'] ? formatDate($po['delivery_date']) : '-' ?></td>
                    </tr>
                    <tr>
                        <th>เครดิต</th>
                        <td><?= $po['payment_terms'] ?> วัน</td>
                    </tr>
                    <tr>
                        <th>หมายเหตุ</th>
                        <td><?= $po['notes'] ? nl2br(e($po['notes'])) : '-' ?></td>
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
                        <th width="120">สร้างโดย</th>
                        <td><?= e($po['created_by_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่สร้าง</th>
                        <td><?= formatDateTime($po['created_at']) ?></td>
                    </tr>
                    <?php if ($po['submitted_at']): ?>
                    <tr>
                        <th>วันที่ส่ง</th>
                        <td><?= formatDateTime($po['submitted_at']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($po['approved_at']): ?>
                    <tr>
                        <th>ผู้อนุมัติ</th>
                        <td><?= e($po['approved_by_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่อนุมัติ</th>
                        <td><?= formatDateTime($po['approved_at']) ?></td>
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
                        <th class="text-center">รับแล้ว</th>
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
                        <td class="text-center">
                            <?php
                            $receivedPercent = $item['qty'] > 0 ? ($item['received_qty'] / $item['qty']) * 100 : 0;
                            $badgeClass = $receivedPercent >= 100 ? 'success' : ($receivedPercent > 0 ? 'warning' : 'secondary');
                            ?>
                            <span class="badge bg-<?= $badgeClass ?>"><?= formatNumber($item['received_qty'], 0) ?></span>
                        </td>
                        <td><?= e($item['unit']) ?></td>
                        <td class="text-end"><?= formatNumber($item['unit_price']) ?></td>
                        <td class="text-end"><?= formatNumber($item['amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="7" class="text-end">รวมก่อน VAT</td>
                        <td class="text-end"><?= formatNumber($po['subtotal']) ?></td>
                    </tr>
                    <tr>
                        <td colspan="7" class="text-end">VAT <?= formatNumber($po['vat_rate'], 2) ?>%</td>
                        <td class="text-end"><?= formatNumber($po['vat_amount']) ?></td>
                    </tr>
                    <tr class="table-primary">
                        <td colspan="7" class="text-end"><strong>รวมทั้งสิ้น</strong></td>
                        <td class="text-end"><strong><?= formatNumber($po['grand_total']) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Goods Receipts -->
<?php if (!empty($grs) || in_array($po['status'], ['Approved', 'Partially Received', 'Received'])): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-box-seam me-2"></i>การรับของ (GR)</span>
        <?php if (in_array($po['status'], ['Approved', 'Partially Received'])): ?>
        <a href="../gr/create.php?po_id=<?= $id ?>" class="btn btn-sm btn-outline-info">
            <i class="bi bi-plus"></i> รับของ
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>เลขที่ GR</th>
                    <th>วันที่รับ</th>
                    <th>ผู้รับ</th>
                    <th>สถานะ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grs)): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">ยังไม่มีการรับของ</td></tr>
                <?php else: ?>
                <?php foreach ($grs as $gr): ?>
                <tr>
                    <td>
                        <a href="../gr/view.php?id=<?= $gr['id'] ?>">
                            <strong><?= e($gr['gr_number']) ?></strong>
                        </a>
                    </td>
                    <td><?= formatDate($gr['received_date']) ?></td>
                    <td><?= e($gr['received_by_name']) ?></td>
                    <td>
                        <span class="badge bg-<?= $gr['status'] === 'Confirmed' ? 'success' : 'secondary' ?>">
                            <?= $gr['status'] === 'Confirmed' ? 'ยืนยันแล้ว' : 'แบบร่าง' ?>
                        </span>
                    </td>
                    <td>
                        <a href="../gr/view.php?id=<?= $gr['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>

<script>
function promptCancelReason(inputId) {
    const reason = prompt('ระบุเหตุผลยกเลิก PO');
    if (reason === null) return false;
    if (!reason.trim()) {
        alert('กรุณาระบุเหตุผล');
        return false;
    }
    const input = document.getElementById(inputId);
    if (input) input.value = reason.trim();
    return true;
}
</script>
