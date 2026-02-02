<?php
/**
 * Dispatch Dashboard
 * 4ERP - Phase 5
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();

// Get routes ready for release (status = Confirmed)
$routesReady = $db->query("
    SELECT r.id, r.route_number, r.route_date, r.driver_name,
           p.job_id, j.job_number, j.scope_short, 
           c.name as customer_name, s.name as site_name
    FROM routes r 
    JOIN plans p ON r.plan_id = p.id
    JOIN jobs j ON p.job_id = j.id 
    JOIN customers c ON j.customer_id = c.id 
    LEFT JOIN sites s ON j.site_id = s.id
    WHERE r.status = 'Confirmed' 
    ORDER BY r.route_date ASC
    LIMIT 10
")->fetchAll();

// Get dispatched routes
$statusFilter = get('status', '');
$whereClause = "r.status IN ('Dispatched', 'InProgress', 'Returned', 'WHReceived', 'Cancelled')";
if ($statusFilter) {
    $whereClause = "r.status = " . $db->quote($statusFilter);
}
$dispatchedRoutes = $db->query("
    SELECT r.id, r.route_number, r.route_date, r.status, r.dispatched_at,
           p.job_id, j.job_number, c.name as customer_name
    FROM routes r 
    JOIN plans p ON r.plan_id = p.id
    JOIN jobs j ON p.job_id = j.id 
    JOIN customers c ON j.customer_id = c.id 
    WHERE {$whereClause}
    ORDER BY r.dispatched_at DESC
    LIMIT 50
")->fetchAll();

$pageTitle = 'Dispatch - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-send me-2"></i>ปล่อย Route</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">หน้าหลัก</a></li>
                    <li class="breadcrumb-item active">ปล่อย Route</li>
                </ol>
            </nav>
        </div>
        <a href="<?= BASE_URL ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับหน้าหลัก
        </a>
    </div>
</div>

<!-- Routes Ready for Release -->
<?php if (!empty($routesReady)): ?>
<div class="card mb-4 border-success">
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-signpost-2 me-2"></i>Route รอปล่อย (<?= count($routesReady) ?>)</span>
        <a href="release.php" class="btn btn-sm btn-light">ปล่อยทั้งหมด</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Route</th>
                        <th>Job</th>
                        <th>ลูกค้า</th>
                        <th>วันที่</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($routesReady as $route): ?>
                    <tr>
                        <td>
                            <a href="../routes/view.php?id=<?= $route['id'] ?>">
                                <strong><?= e($route['route_number']) ?></strong>
                            </a>
                        </td>
                        <td>
                            <a href="../../jobs/view.php?id=<?= $route['job_id'] ?>">
                                <?= e($route['job_number']) ?>
                            </a>
                        </td>
                        <td><?= e($route['customer_name']) ?></td>
                        <td><?= formatDate($route['route_date']) ?></td>
                        <td>
                            <a href="release.php" class="btn btn-sm btn-success">
                                <i class="bi bi-send me-1"></i>ปล่อย
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

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">-- ทุกสถานะ --</option>
                    <option value="Dispatched" <?= $statusFilter === 'Dispatched' ? 'selected' : '' ?>>ปล่อยแล้ว</option>
                    <option value="InProgress" <?= $statusFilter === 'InProgress' ? 'selected' : '' ?>>กำลังดำเนินการ</option>
                    <option value="Returned" <?= $statusFilter === 'Returned' ? 'selected' : '' ?>>คืนของแล้ว</option>
                    <option value="WHReceived" <?= $statusFilter === 'WHReceived' ? 'selected' : '' ?>>รับเข้าคลังแล้ว</option>
                    <option value="Cancelled" <?= $statusFilter === 'Cancelled' ? 'selected' : '' ?>>ยกเลิก</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">กรอง</button>
                <a href="index.php" class="btn btn-outline-secondary">ล้าง</a>
            </div>
        </form>
    </div>
</div>

<!-- Dispatched Routes List -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-check me-2"></i>Route ที่ปล่อยแล้ว
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Route</th>
                        <th>Job</th>
                        <th>ลูกค้า</th>
                        <th>วันที่ปล่อย</th>
                        <th>สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dispatchedRoutes)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
                    <?php else: ?>
                    <?php foreach ($dispatchedRoutes as $r): ?>
                    <tr>
                        <td>
                            <a href="../routes/view.php?id=<?= $r['id'] ?>">
                                <strong><?= e($r['route_number']) ?></strong>
                            </a>
                        </td>
                        <td>
                            <a href="../../jobs/view.php?id=<?= $r['job_id'] ?>">
                                <?= e($r['job_number']) ?>
                            </a>
                        </td>
                        <td><?= e($r['customer_name']) ?></td>
                        <td><?= $r['dispatched_at'] ? formatDate($r['dispatched_at'], 'd/m/Y H:i') : formatDate($r['route_date']) ?></td>
                        <td>
                            <span class="badge bg-<?= match($r['status']) {
                                'Dispatched' => 'primary',
                                'InProgress' => 'info',
                                'Returned' => 'warning text-dark',
                                'WHReceived' => 'success',
                                'Cancelled' => 'danger',
                                default => 'secondary'
                            } ?>"><?= match($r['status']) {
                                'Dispatched' => 'ปล่อยแล้ว',
                                'InProgress' => 'กำลังดำเนินการ',
                                'Returned' => 'คืนของแล้ว',
                                'WHReceived' => 'รับเข้าคลังแล้ว',
                                'Cancelled' => 'ยกเลิก',
                                default => $r['status']
                            } ?></span>
                        </td>
                        <td>
                            <a href="../routes/view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">
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

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
