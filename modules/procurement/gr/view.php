<?php
/**
 * View GR
 * 4ERP - Phase 4
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

// Get GR
$stmt = $db->prepare("
    SELECT gr.*, 
           po.po_number, po.supplier_id,
           s.name as supplier_name,
           u.full_name as receiver_name,
           c.full_name as confirmer_name
    FROM goods_receipts gr
    JOIN purchase_orders po ON gr.po_id = po.id
    JOIN suppliers s ON po.supplier_id = s.id
    JOIN users u ON gr.received_by = u.id
    LEFT JOIN users c ON gr.confirmed_by = c.id
    WHERE gr.id = ?
");
$stmt->execute([$id]);
$gr = $stmt->fetch();

if (!$gr) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get items
$items = $db->prepare("
    SELECT gri.*, poi.description, poi.qty as po_qty, poi.unit, i.code as item_code
    FROM gr_items gri
    JOIN po_items poi ON gri.po_item_id = poi.id
    LEFT JOIN items i ON poi.item_id = i.id
    WHERE gri.gr_id = ?
");
$items->execute([$id]);
$items = $items->fetchAll();

$needsBackfill = false;
foreach ($items as $it) {
    if (empty($it['item_code'])) {
        $needsBackfill = true;
        break;
    }
}

// Handle actions
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("view.php?id=$id");
    }
    
    $action = post('action');
    
    if ($action === 'confirm' && $gr['status'] === 'Draft') {
        $db->prepare("
            UPDATE goods_receipts 
            SET status = 'Confirmed', confirmed_at = NOW(), confirmed_by = ?
            WHERE id = ?
        ")->execute([$_SESSION['user_id'], $id]);
        
        $audit->log('confirm', 'GR', $id);
        setFlash('success', 'ยืนยันการรับเรียบร้อย');
    }
    
    redirect("view.php?id=$id");
}

$pageTitle = "GR: {$gr['gr_number']} - 4ERP";
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-box-seam me-2"></i><?= e($gr['gr_number']) ?>
                <span class="badge bg-<?= $gr['status'] === 'Confirmed' ? 'success' : 'secondary' ?> ms-2">
                    <?= $gr['status'] === 'Confirmed' ? 'ยืนยันแล้ว' : 'แบบร่าง' ?>
                </span>
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="index.php">GR</a></li>
                    <li class="breadcrumb-item active"><?= e($gr['gr_number']) ?></li>
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
<?php if ($gr['status'] === 'Draft'): ?>
<div class="card mb-4">
    <div class="card-body">
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <button type="submit" name="action" value="confirm" class="btn btn-success" onclick="return confirm('ยืนยันการรับสินค้า?')">
                <i class="bi bi-check-circle me-1"></i>ยืนยันการรับ
            </button>
        </form>
        <?php if ($needsBackfill && (in_array('ADM', $_SESSION['roles'] ?? [], true) || in_array('MGR', $_SESSION['roles'] ?? [], true) || in_array('WH', $_SESSION['roles'] ?? [], true))): ?>
            <a href="backfill.php?gr_id=<?= (int)$id ?>" class="btn btn-outline-primary ms-2">
                <i class="bi bi-tools me-1"></i>Backfill
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($gr['status'] !== 'Draft' && $needsBackfill && (in_array('ADM', $_SESSION['roles'] ?? [], true) || in_array('MGR', $_SESSION['roles'] ?? [], true) || in_array('WH', $_SESSION['roles'] ?? [], true))): ?>
<div class="card mb-4">
    <div class="card-body">
        <a href="backfill.php?gr_id=<?= (int)$id ?>" class="btn btn-outline-primary">
            <i class="bi bi-tools me-1"></i>Backfill
        </a>
    </div>
</div>
<?php endif; ?>

<!-- GR Info -->
<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">ข้อมูลการรับ</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="120">เลขที่ GR</th>
                        <td><?= e($gr['gr_number']) ?></td>
                    </tr>
                    <tr>
                        <th>PO</th>
                        <td><a href="../po/view.php?id=<?= $gr['po_id'] ?>"><?= e($gr['po_number']) ?></a></td>
                    </tr>
                    <tr>
                        <th>ผู้ขาย</th>
                        <td><?= e($gr['supplier_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่รับ</th>
                        <td><?= formatDate($gr['received_date']) ?></td>
                    </tr>
                    <tr>
                        <th>ผู้รับ</th>
                        <td><?= e($gr['receiver_name']) ?></td>
                    </tr>
                    <?php if (!empty($gr['notes'])): ?>
                    <tr>
                        <th>หมายเหตุ</th>
                        <td><?= e($gr['notes']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">สถานะ</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="120">สถานะ</th>
                        <td>
                            <span class="badge bg-<?= $gr['status'] === 'Confirmed' ? 'success' : 'secondary' ?>">
                                <?= $gr['status'] === 'Confirmed' ? 'ยืนยันแล้ว' : 'แบบร่าง' ?>
                            </span>
                        </td>
                    </tr>
                    <?php if (!empty($gr['confirmed_at'])): ?>
                    <tr>
                        <th>ยืนยันโดย</th>
                        <td><?= e($gr['confirmer_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่ยืนยัน</th>
                        <td><?= formatDateTime($gr['confirmed_at']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Items -->
<div class="card mb-4">
    <div class="card-header">รายการที่รับ</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>รหัส</th>
                        <th>รายละเอียด</th>
                        <th class="text-center">รับ</th>
                        <th>หน่วย</th>
                        <th>สภาพ</th>
                        <th>Serial</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $item): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= $item['item_code'] ?: '-' ?></td>
                        <td><?= e($item['description']) ?></td>
                        <td class="text-center">
                            <span class="badge bg-success"><?= formatNumber($item['received_qty'], 0) ?></span>
                        </td>
                        <td><?= e($item['unit']) ?></td>
                        <td><?= e($item['condition_note']) ?: '-' ?></td>
                        <td>
                            <?php if ($item['serial_numbers']): 
                                $serials = json_decode($item['serial_numbers'], true);
                            ?>
                            <?php foreach ($serials as $sn): ?>
                            <span class="badge bg-secondary"><?= e($sn) ?></span>
                            <?php endforeach; ?>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
