<?php
/**
 * Dispatch Dashboard
 * ERP v2 - Phase 5
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();

// Filters
$search = get('search', '');
$status = get('status', '');

$where = '1=1';
$params = [];

if ($search) {
    $where .= ' AND (dn.do_number LIKE ? OR p.plan_number LIKE ? OR j.job_number LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status) {
    $where .= ' AND dn.status = ?';
    $params[] = $status;
}

// Get stats
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Draft' THEN 1 ELSE 0 END) as draft,
        SUM(CASE WHEN status = 'Dispatched' THEN 1 ELSE 0 END) as dispatched
    FROM dispatch_notes
")->fetch();

// Get list
$list = $db->prepare("
    SELECT dn.*, p.plan_number, j.job_number, c.name as customer_name,
           v.name as vehicle_name, d.full_name as driver_name
    FROM dispatch_notes dn
    JOIN plans p ON dn.plan_id = p.id
    JOIN jobs j ON p.job_id = j.id
    JOIN customers c ON j.customer_id = c.id
    LEFT JOIN items v ON dn.vehicle_id = v.id
    LEFT JOIN people d ON dn.driver_id = d.id
    WHERE $where
    ORDER BY dn.dispatch_date DESC
");
$list->execute($params);
$list = $list->fetchAll();

$pageTitle = 'Logistics - Dispatch';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-truck me-2"></i>การจัดส่ง (Dispatch)</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/4erpv2/">Home</a></li>
                    <li class="breadcrumb-item active">Logistics</li>
                </ol>
            </nav>
        </div>
        <div>
            <!-- Create via Plan only -->
            <a href="../../planning/index.php" class="btn btn-outline-primary">
                <i class="bi bi-plus-lg me-1"></i>สร้างจากแผนงาน
            </a>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card bg-primary text-white h-100">
            <div class="card-body">
                <h6 class="card-title">ทั้งหมด</h6>
                <h2 class="mb-0"><?= formatNumber($stats['total'], 0) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-warning text-dark h-100">
            <div class="card-body">
                <h6 class="card-title">เตรียมจัดส่ง (Draft/Prepared)</h6>
                <h2 class="mb-0"><?= formatNumber($stats['draft'], 0) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-success text-white h-100">
            <div class="card-body">
                <h6 class="card-title">กำลังส่ง / ส่งแล้ว</h6>
                <h2 class="mb-0"><?= formatNumber($stats['dispatched'], 0) ?></h2>
            </div>
        </div>
    </div>
</div>

<!-- List -->
<div class="card">
    <div class="card-body">
        <form method="GET" class="row g-3 mb-4">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="ค้นหา DO, Plan, Job..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">-- All Status --</option>
                    <option value="Draft" <?= $status === 'Draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="Dispatched" <?= $status === 'Dispatched' ? 'selected' : '' ?>>Dispatched</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">ค้นหา</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>DO Number</th>
                        <th>Plan / Job</th>
                        <th>วันที่ส่ง</th>
                        <th>รถ / คนขับ</th>
                        <th>สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($list)): ?>
                    <tr><td colspan="6" class="text-center text-muted">ไม่พบข้อมูล</td></tr>
                    <?php else: ?>
                    <?php foreach ($list as $item): ?>
                    <tr>
                        <td>
                            <a href="view.php?id=<?= $item['id'] ?>" class="fw-bold">
                                <?= e($item['do_number']) ?>
                            </a>
                        </td>
                        <td>
                            <div><?= e($item['plan_number']) ?></div>
                            <small class="text-muted"><?= e($item['job_number']) ?></small>
                        </td>
                        <td><?= formatDateTime($item['dispatch_date']) ?></td>
                        <td>
                            <?php if ($item['vehicle_name']): ?>
                                <i class="bi bi-truck me-1"></i><?= e($item['vehicle_name']) ?><br>
                            <?php endif; ?>
                            <?php if ($item['driver_name']): ?>
                                <i class="bi bi-person me-1"></i><?= e($item['driver_name']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $item['status'] === 'Dispatched' ? 'success' : 'secondary' ?>">
                                <?= e($item['status']) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="view.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-outline-primary">
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
