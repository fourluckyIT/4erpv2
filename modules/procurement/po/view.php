<?php
/**
 * View PO
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

// Get PO
$stmt = $db->prepare("
    SELECT po.*, 
           s.name as supplier_name, s.code as supplier_code,
           pr.pr_number,
           a.full_name as approver_name,
           c.full_name as creator_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_requests pr ON po.pr_id = pr.id
    LEFT JOIN users a ON po.approved_by = a.id
    LEFT JOIN users c ON po.created_by = c.id
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
    SELECT gr.*, u.full_name as receiver_name
    FROM goods_receipts gr
    JOIN users u ON gr.received_by = u.id
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
        setFlash('success', 'ส่งอนุมัติเรียบร้อย');
        
    } elseif ($action === 'approve' && $po['status'] === 'Submitted') {
        if (!in_array('ADM', $_SESSION['roles']) && !in_array('MGR', $_SESSION['roles'])) {
            setFlash('error', 'คุณไม่มีสิทธิ์อนุมัติ');
            redirect("view.php?id=$id");
        }
        
        $db->prepare("
            UPDATE purchase_orders 
            SET status = 'Approved', approved_at = NOW(), approved_by = ?
            WHERE id = ?
        ")->execute([$_SESSION['user_id'], $id]);
        
        $audit->log('approve', 'PO', $id);
        setFlash('success', 'อนุมัติเรียบร้อย');
        
    } elseif ($action === 'cancel') {
        $db->prepare("UPDATE purchase_orders SET status = 'Cancelled' WHERE id = ?")->execute([$id]);
        $audit->log('cancel', 'PO', $id);
        setFlash('success', 'ยกเลิกเรียบร้อย');
    }
    
    redirect("view.php?id=$id");
}

$pageTitle = "PO: {$po['po_number']} - ERP v2";
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-receipt me-2"></i><?= e($po['po_number']) ?>
                <span class="badge bg-<?= match($po['po_type']) {
                    'Goods' => 'primary',
                    'Service' => 'info',
                    'Manpower' => 'warning text-dark',
                    default => 'secondary'
                } ?> ms-2"><?= match($po['po_type']) {
                    'Goods' => 'สินค้า',
                    'Service' => 'บริการ',
                    'Manpower' => 'แรงงาน',
                    default => $po['po_type']
                } ?></span>
                <span class="badge bg-<?= match($po['status']) {
                    'Draft' => 'secondary',
                    'Submitted' => 'warning text-dark',
                    'Approved' => 'primary',
                    'Partially Received' => 'info',
                    'Received' => 'success',
                    'Cancelled' => 'dark',
                    default => 'secondary'
                } ?>"><?= match($po['status']) {
                    'Draft' => 'แบบร่าง',
                    'Submitted' => 'รออนุมัติ',
                    'Approved' => 'รอรับของ',
                    'Partially Received' => 'รับบางส่วน',
                    'Received' => 'รับครบ',
                    'Cancelled' => 'ยกเลิก',
                    default => $po['status']
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
        <div>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
        </div>
    </div>
</div>

<!-- Actions -->
<?php if ($po['status'] !== 'Cancelled' && $po['status'] !== 'Received'): ?>
<div class="card mb-4">
    <div class="card-body">
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            
            <?php if ($po['status'] === 'Draft'): ?>
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
            
            <?php if ($po['status'] === 'Submitted' && (in_array('ADM', $_SESSION['roles']) || in_array('MGR', $_SESSION['roles']))): ?>
            <button type="submit" name="action" value="approve" class="btn btn-success" onclick="return confirm('ยืนยันอนุมัติ?')">
                <i class="bi bi-check-circle me-1"></i>อนุมัติ
            </button>
            <?php endif; ?>
            
            <?php if (in_array($po['status'], ['Approved', 'Partially Received']) && $po['po_type'] === 'Goods'): ?>
            <a href="../gr/create.php?po_id=<?= $id ?>" class="btn btn-success">
                <i class="bi bi-box-arrow-in-down me-1"></i>รับสินค้า (GR)
            </a>
            <?php endif; ?>
            
            <?php if ($po['po_type'] === 'Manpower' && $po['status'] === 'Approved'): ?>
            <a href="manpower.php?po_id=<?= $id ?>" class="btn btn-warning">
                <i class="bi bi-people me-1"></i>ลงทะเบียนแรงงาน
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
                    <tr>
                        <th>ประเภท</th>
                        <td><?= match($po['po_type']) {
                            'Goods' => 'สินค้า',
                            'Service' => 'บริการ',
                            'Manpower' => 'แรงงาน',
                            default => $po['po_type']
                        } ?></td>
                    </tr>
                    <tr>
                        <th>ผู้ขาย</th>
                        <td><?= e($po['supplier_code']) ?> - <?= e($po['supplier_name']) ?></td>
                    </tr>
                    <?php if ($po['pr_number']): ?>
                    <tr>
                        <th>จาก PR</th>
                        <td><a href="../pr/view.php?id=<?= $po['pr_id'] ?>"><?= e($po['pr_number']) ?></a></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>วันที่สั่ง</th>
                        <td><?= formatDate($po['order_date']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่ส่ง</th>
                        <td><?= $po['delivery_date'] ? formatDate($po['delivery_date']) : '-' ?></td>
                    </tr>
                    <tr>
                        <th>เครดิต</th>
                        <td><?= $po['payment_terms'] ?> วัน</td>
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
                        <th width="120">ผู้สร้าง</th>
                        <td><?= e($po['creator_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่สร้าง</th>
                        <td><?= formatDateTime($po['created_at']) ?></td>
                    </tr>
                    <?php if ($po['approved_at']): ?>
                    <tr>
                        <th>ผู้อนุมัติ</th>
                        <td><?= e($po['approver_name']) ?></td>
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
<?php if ($po['po_type'] !== 'Manpower'): ?>
<div class="card mb-4">
    <div class="card-header">รายการ</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>รหัส</th>
                        <th>รายละเอียด</th>
                        <th class="text-center">สั่ง</th>
                        <th class="text-center">รับแล้ว</th>
                        <th>หน่วย</th>
                        <th class="text-end">ราคา</th>
                        <th class="text-end">รวม</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $item): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= $item['item_code'] ?: '-' ?></td>
                        <td><?= e($item['description']) ?></td>
                        <td class="text-center"><?= formatNumber($item['qty'], 0) ?></td>
                        <td class="text-center">
                            <?php if ($item['received_qty'] >= $item['qty']): ?>
                            <span class="badge bg-success"><?= formatNumber($item['received_qty'], 0) ?></span>
                            <?php elseif ($item['received_qty'] > 0): ?>
                            <span class="badge bg-warning text-dark"><?= formatNumber($item['received_qty'], 0) ?></span>
                            <?php else: ?>
                            <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($item['unit']) ?></td>
                        <td class="text-end"><?= formatNumber($item['unit_price']) ?></td>
                        <td class="text-end"><?= formatNumber($item['amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="7" class="text-end">ยอดรวม</td>
                        <td class="text-end"><?= formatNumber($po['subtotal']) ?></td>
                    </tr>
                    <tr>
                        <td colspan="7" class="text-end">VAT <?= formatNumber($po['vat_rate'], 0) ?>%</td>
                        <td class="text-end"><?= formatNumber($po['vat_amount']) ?></td>
                    </tr>
                    <tr>
                        <td colspan="7" class="text-end"><strong>รวมทั้งสิ้น</strong></td>
                        <td class="text-end"><strong><?= formatNumber($po['grand_total']) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- GR History -->
<?php if (!empty($grs)): ?>
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-box-seam me-2"></i>ประวัติรับสินค้า (GR)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
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
                    <?php foreach ($grs as $gr): ?>
                    <tr>
                        <td><a href="../gr/view.php?id=<?= $gr['id'] ?>"><?= e($gr['gr_number']) ?></a></td>
                        <td><?= formatDate($gr['received_date']) ?></td>
                        <td><?= e($gr['receiver_name']) ?></td>
                        <td>
                            <span class="badge bg-<?= $gr['status'] === 'Confirmed' ? 'success' : 'secondary' ?>">
                                <?= $gr['status'] === 'Confirmed' ? 'ยืนยันแล้ว' : 'แบบร่าง' ?>
                            </span>
                        </td>
                        <td>
                            <a href="../gr/view.php?id=<?= $gr['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
