<?php
/**
 * Planning Dashboard & List
 * ERP v2 - Phase 5
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();

// Filters
$search = get('search', '');
$status = get('status', '');

$where = '1=1';
$params = [];

if ($search) {
    $where .= ' AND (p.plan_number LIKE ? OR j.job_number LIKE ? OR c.name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status) {
    $where .= ' AND p.status = ?';
    $params[] = $status;
}

// Get stats
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Draft' THEN 1 ELSE 0 END) as draft,
        SUM(CASE WHEN status = 'Confirmed' THEN 1 ELSE 0 END) as confirmed
    FROM plans
")->fetch();

// Get plans
$plans = $db->prepare("
    SELECT p.*, j.job_number, j.job_type, c.name as customer_name, u.full_name as creator_name
    FROM plans p
    JOIN jobs j ON p.job_id = j.id
    JOIN customers c ON j.customer_id = c.id
    JOIN users u ON p.created_by = u.id
    WHERE $where
    ORDER BY p.id DESC
    LIMIT 50
");
$plans->execute($params);
$plans = $plans->fetchAll();

$pageTitle = 'Planning - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-calendar-check me-2"></i>แผนงาน (Planning)</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/4erpv2/">Home</a></li>
                    <li class="breadcrumb-item active">Planning</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="create.php" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>สร้างแผนงาน
            </a>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card bg-primary text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title mb-0">ทั้งหมด</h6>
                        <h2 class="mt-2 mb-0"><?= formatNumber($stats['total'], 0) ?></h2>
                    </div>
                    <i class="bi bi-folder2-open fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-secondary text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title mb-0">แบบร่าง (Draft)</h6>
                        <h2 class="mt-2 mb-0"><?= formatNumber($stats['draft'], 0) ?></h2>
                    </div>
                    <i class="bi bi-pencil-square fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-success text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title mb-0">ยืนยันแล้ว (Confirmed)</h6>
                        <h2 class="mt-2 mb-0"><?= formatNumber($stats['confirmed'], 0) ?></h2>
                    </div>
                    <i class="bi bi-check-circle fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="ค้นหา Job, Plan หรือ ลูกค้า..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">-- ทุกสถานะ --</option>
                    <option value="Draft" <?= $status === 'Draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="Confirmed" <?= $status === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="Cancelled" <?= $status === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">ค้นหา</button>
            </div>
            <?php if ($search || $status): ?>
            <div class="col-md-2">
                <a href="index.php" class="btn btn-outline-secondary w-100">ล้างค่า</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- List -->
<div class="card">
    <div class="card-header">รายการแผนงานล่าสุด</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>เลขที่แผน</th>
                        <th>Job</th>
                        <th>วันที่ปฏิบัติงาน</th>
                        <th>ลูกค้า</th>
                        <th>ผู้สร้าง</th>
                        <th>สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
                    <?php else: ?>
                    <?php foreach ($plans as $p): ?>
                    <tr>
                        <td>
                            <a href="view.php?id=<?= $p['id'] ?>" class="fw-bold text-primary">
                                <?= e($p['plan_number']) ?>
                            </a>
                        </td>
                        <td>
                            <span class="badge bg-info text-dark"><?= e($p['job_number']) ?></span>
                            <small class="d-block text-muted"><?= e($p['job_type']) ?></small>
                        </td>
                        <td><?= formatDate($p['plan_date']) ?></td>
                        <td><?= e($p['customer_name']) ?></td>
                        <td><?= e($p['creator_name']) ?></td>
                        <td>
                            <?php
                            $statusClass = match($p['status']) {
                                'Draft' => 'secondary',
                                'Confirmed' => 'success',
                                'Cancelled' => 'danger',
                                default => 'secondary'
                            };
                            ?>
                            <span class="badge bg-<?= $statusClass ?>">
                                <?= e($p['status']) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
