<?php
/**
 * PO List
 * 4ERP - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
$db = getDB();
$audit = new AuditLog();

// Filters
$statusFilter = get('status', '');
$typeFilter = get('type', '');
$search = get('search', '');
$monthFilter = get('month', '');

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
if ($monthFilter) {
    $monthDate = DateTime::createFromFormat('Y-m', $monthFilter);
    if ($monthDate) {
        $startDate = $monthDate->format('Y-m-01');
        $endDate = $monthDate->modify('first day of next month')->format('Y-m-01');
        $where .= ' AND po.order_date >= ? AND po.order_date < ?';
        $params[] = $startDate;
        $params[] = $endDate;
    } else {
        $monthFilter = '';
    }
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

// Sync Manpower PO status based on registered manpower
$manpowerIds = [];
foreach ($pos as $row) {
    if (($row['po_type'] ?? '') === 'Manpower' && in_array($row['status'], ['Approved', 'Partially Received', 'Received'], true)) {
        $manpowerIds[] = (int) $row['id'];
    }
}

if (!empty($manpowerIds)) {
    $placeholders = implode(',', array_fill(0, count($manpowerIds), '?'));
    $requiredMap = [];
    $countMap = [];

    $stmt = $db->prepare("SELECT po_id, COALESCE(SUM(qty), 0) AS required FROM po_items WHERE po_id IN ($placeholders) GROUP BY po_id");
    $stmt->execute($manpowerIds);
    foreach ($stmt->fetchAll() as $r) {
        $requiredMap[(int) $r['po_id']] = (int) $r['required'];
    }

    $stmt = $db->prepare("SELECT po_id, COUNT(*) AS cnt FROM po_manpower WHERE po_id IN ($placeholders) AND status <> 'Cancelled' GROUP BY po_id");
    $stmt->execute($manpowerIds);
    foreach ($stmt->fetchAll() as $r) {
        $countMap[(int) $r['po_id']] = (int) $r['cnt'];
    }

    $synced = [];
    foreach ($pos as $row) {
        $poId = (int) $row['id'];
        if (!in_array($poId, $manpowerIds, true)) {
            $synced[] = $row;
            continue;
        }

        $required = $requiredMap[$poId] ?? 0;
        if ($required <= 0) {
            $synced[] = $row;
            continue;
        }

        $confirmed = $countMap[$poId] ?? 0;
        if ($confirmed <= 0) {
            $newStatus = 'Approved';
        } elseif ($confirmed < $required) {
            $newStatus = 'Partially Received';
        } else {
            $newStatus = 'Received';
        }

        if ($newStatus !== $row['status']) {
            $db->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?")->execute([$newStatus, $poId]);
            $audit->log('update', 'PO', $poId, ['status' => $row['status']], ['status' => $newStatus], 'Manpower registration sync');
            $row['status'] = $newStatus;
        }

        $synced[] = $row;
    }

    $pos = $synced;
}

if ($statusFilter) {
    $pos = array_values(array_filter($pos, fn($row) => ($row['status'] ?? '') === $statusFilter));
}

$pageTitle = 'Purchase Orders - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
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
                <input type="month" class="form-control" name="month" value="<?= e($monthFilter) ?>">
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
                    <option value="Cancelled" <?= $statusFilter === 'Cancelled' ? 'selected' : '' ?>>ยกเลิก</option>
                    <option value="Voided" <?= $statusFilter === 'Voided' ? 'selected' : '' ?>>ยกเลิกหลังอนุมัติ</option>
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
                                'Voided' => 'danger',
                                default => 'secondary'
                            } ?>"><?= match($po['status']) {
                                'Draft' => 'แบบร่าง',
                                'Submitted' => 'รออนุมัติ',
                                'Approved' => 'รอรับของ',
                                'Partially Received' => 'รับบางส่วน',
                                'Received' => 'รับครบ',
                                'Cancelled' => 'ยกเลิก',
                                'Voided' => 'ยกเลิกหลังอนุมัติ',
                                default => $po['status']
                            } ?></span>
                        </td>
                        <td><?= formatDate($po['order_date']) ?></td>
                        <td>
                            <a href="view.php?id=<?= $po['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (in_array($po['status'], ['Draft', 'Submitted']) && $rbac->can('void', 'PO', $po['status'])): ?>
                                <form method="POST" action="view.php?id=<?= $po['id'] ?>" class="d-inline" id="cancel-form-<?= $po['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="cancel_reason" value="">
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="cancelPoFromList(<?= $po['id'] ?>, '<?= e($po['po_number']) ?>')">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>

<script>
function cancelPoFromList(poId, poNumber) {
    const reason = prompt(`ระบุเหตุผลยกเลิก PO ${poNumber}`);
    if (reason === null) return;
    if (!reason.trim()) {
        alert('กรุณาระบุเหตุผล');
        return;
    }
    const form = document.getElementById(`cancel-form-${poId}`);
    if (!form) return;
    form.querySelector('[name="cancel_reason"]').value = reason.trim();
    form.submit();
}
</script>
