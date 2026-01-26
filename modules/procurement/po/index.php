<?php
/**
 * PO List
 * ERP v2 - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();

// Filters
$statusFilter = get('status', '');
$typeFilter = get('type', '');
$search = get('search', '');

$where = '1=1';
$params = [];

if ($statusFilter) {
    $where .= ' AND po.status = ?';
    $params[] = $statusFilter;
}
if ($typeFilter) {
    $where .= ' AND po.po_type = ?';
    $params[] = $typeFilter;
}
if ($search) {
    $where .= ' AND (po.po_number LIKE ? OR s.name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$pos = $db->prepare("
    SELECT po.*, s.name as supplier_name, pr.pr_number
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_requests pr ON po.pr_id = pr.id
    WHERE $where
    ORDER BY po.created_at DESC
");
$pos->execute($params);
$pos = $pos->fetchAll();

$pageTitle = 'Purchase Orders - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-receipt me-2"></i>Purchase Order (PO)</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item active">PO</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>สร้าง PO
            </a>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="ค้นหา..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="type">
                    <option value="">-- ประเภท --</option>
                    <option value="Goods" <?= $typeFilter === 'Goods' ? 'selected' : '' ?>>สินค้า</option>
                    <option value="Service" <?= $typeFilter === 'Service' ? 'selected' : '' ?>>บริการ</option>
                    <option value="Manpower" <?= $typeFilter === 'Manpower' ? 'selected' : '' ?>>แรงงาน</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">-- สถานะ --</option>
                    <option value="Draft" <?= $statusFilter === 'Draft' ? 'selected' : '' ?>>แบบร่าง</option>
                    <option value="Submitted" <?= $statusFilter === 'Submitted' ? 'selected' : '' ?>>รออนุมัติ</option>
                    <option value="Approved" <?= $statusFilter === 'Approved' ? 'selected' : '' ?>>อนุมัติแล้ว</option>
                    <option value="Partially Received" <?= $statusFilter === 'Partially Received' ? 'selected' : '' ?>>รับบางส่วน</option>
                    <option value="Received" <?= $statusFilter === 'Received' ? 'selected' : '' ?>>รับครบ</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">ค้นหา</button>
                <a href="index.php" class="btn btn-outline-secondary">ล้าง</a>
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
                        <th>เลขที่ PO</th>
                        <th>ประเภท</th>
                        <th>ผู้ขาย</th>
                        <th>PR</th>
                        <th class="text-end">ยอดรวม</th>
                        <th>สถานะ</th>
                        <th>วันสั่ง</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pos)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
                    <?php else: ?>
                    <?php foreach ($pos as $po): ?>
                    <tr>
                        <td>
                            <a href="view.php?id=<?= $po['id'] ?>">
                                <strong><?= e($po['po_number']) ?></strong>
                            </a>
                        </td>
                        <td>
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
                        </td>
                        <td><?= e($po['supplier_name']) ?></td>
                        <td>
                            <?php if ($po['pr_number']): ?>
                            <a href="../pr/view.php?id=<?= $po['pr_id'] ?>" class="badge bg-secondary">
                                <?= e($po['pr_number']) ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= formatNumber($po['grand_total']) ?></td>
                        <td>
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
                        </td>
                        <td><?= formatDate($po['order_date']) ?></td>
                        <td>
                            <a href="view.php?id=<?= $po['id'] ?>" class="btn btn-sm btn-outline-primary">
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
