<?php
/**
 * GR List
 * ERP v2 - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();

// Filters
$search = get('search', '');

$where = '1=1';
$params = [];

if ($search) {
    $where .= ' AND (gr.gr_number LIKE ? OR po.po_number LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$grs = $db->prepare("
    SELECT gr.*, po.po_number, s.name as supplier_name, u.full_name as receiver_name
    FROM goods_receipts gr
    JOIN purchase_orders po ON gr.po_id = po.id
    JOIN suppliers s ON po.supplier_id = s.id
    JOIN users u ON gr.received_by = u.id
    WHERE $where
    ORDER BY gr.received_date DESC
");
$grs->execute($params);
$grs = $grs->fetchAll();

$pageTitle = 'Goods Receipts - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-box-seam me-2"></i>Goods Receipt (GR)</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item active">GR</li>
                </ol>
            </nav>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="ค้นหา GR หรือ PO..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">ค้นหา</button>
            </div>
        </form>
    </div>
</div>

<!-- List -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>เลขที่ GR</th>
                        <th>PO</th>
                        <th>ผู้ขาย</th>
                        <th>วันที่รับ</th>
                        <th>ผู้รับ</th>
                        <th>สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grs)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
                    <?php else: ?>
                    <?php foreach ($grs as $gr): ?>
                    <tr>
                        <td>
                            <a href="view.php?id=<?= $gr['id'] ?>">
                                <strong><?= e($gr['gr_number']) ?></strong>
                            </a>
                        </td>
                        <td>
                            <a href="../po/view.php?id=<?= $gr['po_id'] ?>" class="badge bg-secondary">
                                <?= e($gr['po_number']) ?>
                            </a>
                        </td>
                        <td><?= e($gr['supplier_name']) ?></td>
                        <td><?= formatDate($gr['received_date']) ?></td>
                        <td><?= e($gr['receiver_name']) ?></td>
                        <td>
                            <span class="badge bg-<?= $gr['status'] === 'Confirmed' ? 'success' : 'secondary' ?>">
                                <?= $gr['status'] === 'Confirmed' ? 'ยืนยันแล้ว' : 'แบบร่าง' ?>
                            </span>
                        </td>
                        <td>
                            <a href="view.php?id=<?= $gr['id'] ?>" class="btn btn-sm btn-outline-primary">
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
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
