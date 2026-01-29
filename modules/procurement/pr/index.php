<?php
/**
 * PR List
 * 4ERP - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();

// Filters
$statusFilter = get('status', '');
$search = get('search', '');
$monthFilter = get('month', '');

$where = '1=1';
$params = [];

if ($statusFilter) {
    $where .= ' AND pr.status = ?';
    $params[] = $statusFilter;
}
if ($search) {
    $where .= ' AND (pr.pr_number LIKE ? OR pr.purpose LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($monthFilter) {
    $monthDate = DateTime::createFromFormat('Y-m', $monthFilter);
    if ($monthDate) {
        $startDate = $monthDate->format('Y-m-01');
        $endDate = $monthDate->modify('first day of next month')->format('Y-m-01');
        $where .= ' AND pr.created_at >= ? AND pr.created_at < ?';
        $params[] = $startDate;
        $params[] = $endDate;
    } else {
        $monthFilter = '';
    }
}

$prs = $db->prepare("
    SELECT pr.*, u.full_name as requester_name, j.job_number
    FROM purchase_requests pr
    JOIN users u ON pr.requester_id = u.id
    LEFT JOIN jobs j ON pr.job_id = j.id
    WHERE $where
    ORDER BY pr.created_at DESC
");
$prs->execute($params);
$prs = $prs->fetchAll();

$pageTitle = 'Purchase Requests - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-file-text me-2"></i>Purchase Request (PR)</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item active">PR</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>สร้าง PR
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
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">-- ทุกสถานะ --</option>
                    <option value="Draft" <?= $statusFilter === 'Draft' ? 'selected' : '' ?>>แบบร่าง</option>
                    <option value="Submitted" <?= $statusFilter === 'Submitted' ? 'selected' : '' ?>>รออนุมัติ</option>
                    <option value="Approved" <?= $statusFilter === 'Approved' ? 'selected' : '' ?>>อนุมัติแล้ว</option>
                    <option value="Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>ไม่อนุมัติ</option>
                    <option value="Cancelled" <?= $statusFilter === 'Cancelled' ? 'selected' : '' ?>>ยกเลิก</option>
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
                        <th>เลขที่ PR</th>
                        <th>วัตถุประสงค์</th>
                        <th>Job</th>
                        <th>ผู้ขอ</th>
                        <th>ยอดรวม</th>
                        <th>สถานะ</th>
                        <th>วันที่</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($prs)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
                    <?php else: ?>
                    <?php foreach ($prs as $pr): ?>
                    <tr>
                        <td>
                            <a href="view.php?id=<?= $pr['id'] ?>">
                                <strong><?= e($pr['pr_number']) ?></strong>
                            </a>
                        </td>
                        <td><?= e(mb_substr($pr['purpose'], 0, 40)) ?><?= mb_strlen($pr['purpose']) > 40 ? '...' : '' ?></td>
                        <td>
                            <?php if ($pr['job_number']): ?>
                            <span class="badge bg-secondary"><?= e($pr['job_number']) ?></span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($pr['requester_name']) ?></td>
                        <td class="text-end"><?= formatNumber($pr['total_amount']) ?></td>
                        <td>
                            <span class="badge bg-<?= match($pr['status']) {
                                'Draft' => 'secondary',
                                'Submitted' => 'warning text-dark',
                                'Approved' => 'success',
                                'Rejected' => 'danger',
                                'Cancelled' => 'dark',
                                default => 'secondary'
                            } ?>"><?= match($pr['status']) {
                                'Draft' => 'แบบร่าง',
                                'Submitted' => 'รออนุมัติ',
                                'Approved' => 'อนุมัติแล้ว',
                                'Rejected' => 'ไม่อนุมัติ',
                                'Cancelled' => 'ยกเลิก',
                                default => $pr['status']
                            } ?></span>
                        </td>
                        <td><?= formatDate($pr['created_at']) ?></td>
                        <td>
                            <a href="view.php?id=<?= $pr['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if ($pr['status'] === 'Draft'): ?>
                            <a href="edit.php?id=<?= $pr['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-pencil"></i>
                            </a>
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
