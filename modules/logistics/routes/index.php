<?php
/**
 * Routes Dashboard
 * ERP v2 - Phase 5 v2
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/Route.php';

$auth = new Auth();
$auth->requireAuth();

$routeModel = new Route();

// Get filters
$statusFilter = get('status', '');
$dateFrom = get('date_from', '');
$dateTo = get('date_to', '');

$filters = [];
if ($statusFilter) $filters['status'] = $statusFilter;
if ($dateFrom) $filters['date_from'] = $dateFrom;
if ($dateTo) $filters['date_to'] = $dateTo;

$routes = $routeModel->getList($filters);

$pageTitle = 'Routes - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-signpost-2 me-2"></i>Routes (เส้นทาง)</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">หน้าหลัก</a></li>
                    <li class="breadcrumb-item"><a href="../">Logistics</a></li>
                    <li class="breadcrumb-item active">Routes</li>
                </ol>
            </nav>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">สถานะ</label>
                <select class="form-select" name="status">
                    <option value="">-- ทั้งหมด --</option>
                    <option value="Draft" <?= $statusFilter === 'Draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="Confirmed" <?= $statusFilter === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="Dispatched" <?= $statusFilter === 'Dispatched' ? 'selected' : '' ?>>Dispatched</option>
                    <option value="InProgress" <?= $statusFilter === 'InProgress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="Returned" <?= $statusFilter === 'Returned' ? 'selected' : '' ?>>Returned</option>
                    <option value="WHReceived" <?= $statusFilter === 'WHReceived' ? 'selected' : '' ?>>WH Received</option>
                    <option value="Cancelled" <?= $statusFilter === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">วันที่ (จาก)</label>
                <input type="date" class="form-control" name="date_from" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">วันที่ (ถึง)</label>
                <input type="date" class="form-control" name="date_to" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">กรอง</button>
                <a href="index.php" class="btn btn-outline-secondary">ล้าง</a>
            </div>
        </form>
    </div>
</div>

<!-- Routes List -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-check me-2"></i>Routes ทั้งหมด (<?= count($routes) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Route Number</th>
                        <th>Plan</th>
                        <th>Job</th>
                        <th>ลูกค้า</th>
                        <th>วันที่</th>
                        <th>รถ</th>
                        <th>สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($routes)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
                    <?php else: ?>
                    <?php foreach ($routes as $r): ?>
                    <tr>
                        <td>
                            <a href="view.php?id=<?= $r['id'] ?>">
                                <strong><?= e($r['route_number']) ?></strong>
                            </a>
                        </td>
                        <td>
                            <a href="../../planning/view.php?id=<?= $r['plan_id'] ?>" class="text-decoration-none">
                                <?= e($r['plan_number']) ?>
                            </a>
                        </td>
                        <td><?= e($r['job_number']) ?></td>
                        <td><?= e($r['customer_name']) ?></td>
                        <td><?= formatDate($r['route_date']) ?></td>
                        <td><?= e($r['vehicle_serial'] ?? '-') ?></td>
                        <td>
                            <span class="badge bg-<?= match($r['status']) {
                                'Draft' => 'secondary',
                                'Confirmed' => 'info',
                                'Dispatched' => 'primary',
                                'InProgress' => 'warning',
                                'Returned' => 'info',
                                'WHReceived' => 'success',
                                'Cancelled' => 'danger',
                                default => 'secondary'
                            } ?>"><?= match($r['status']) {
                                'Draft' => 'แบบร่าง',
                                'Confirmed' => 'ยืนยันแล้ว',
                                'Dispatched' => 'ส่งของแล้ว',
                                'InProgress' => 'กำลังดำเนินการ',
                                'Returned' => 'รับคืนแล้ว',
                                'WHReceived' => 'คลังรับแล้ว',
                                'Cancelled' => 'ยกเลิก',
                                default => $r['status']
                            } ?></span>
                        </td>
                        <td>
                            <a href="view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">
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
