<?php
/**
 * Job List / Dashboard
 * ERP v2 - Phase 2
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/StatusMachine.php';
require_once __DIR__ . '/../../core/Job.php';

$auth = new Auth();
$auth->requireAuth();

$job = new Job();

// Filters
$filters = [
    'status' => get('status'),
    'search' => get('search'),
    'customer_id' => get('customer_id'),
];

$jobs = $job->getList(array_filter($filters));
$stats = $job->getStats();

$pageTitle = 'Jobs - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-briefcase me-2"></i>Jobs
            </h2>
            <p class="text-muted mb-0">รายการงานทั้งหมด</p>
        </div>
        <a href="create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>สร้างงานใหม่
        </a>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="stat-number"><?= $stats['active'] ?? 0 ?></div>
                <div class="stat-label">งานที่กำลังดำเนินการ</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card stat-card warning">
            <div class="card-body">
                <div class="stat-number"><?= $stats['pending_approval'] ?? 0 ?></div>
                <div class="stat-label">รออนุมัติ</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card stat-card success">
            <div class="card-body">
                <div class="stat-number"><?= $stats['by_status']['Closed'] ?? 0 ?></div>
                <div class="stat-label">ปิดงานแล้ว</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card stat-card info">
            <div class="card-body">
                <div class="stat-number"><?= $stats['this_month'] ?? 0 ?></div>
                <div class="stat-label">สร้างเดือนนี้</div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">ค้นหา</label>
                <input type="text" class="form-control" name="search" 
                       value="<?= e($filters['search'] ?? '') ?>" placeholder="เลขงาน, ชื่อลูกค้า...">
            </div>
            <div class="col-md-3">
                <label class="form-label">สถานะ</label>
                <select class="form-select" name="status">
                    <option value="">-- ทั้งหมด --</option>
                    <?php foreach (StatusMachine::STATUSES as $status): ?>
                    <option value="<?= e($status) ?>" <?= ($filters['status'] ?? '') === $status ? 'selected' : '' ?>>
                        <?= StatusMachine::getStatusLabel($status) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search"></i> ค้นหา
                </button>
                <a href="index.php" class="btn btn-outline-secondary">ล้าง</a>
            </div>
        </form>
    </div>
</div>

<!-- Job List -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($jobs)): ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
            <p class="text-muted mt-3">ไม่พบข้อมูลงาน</p>
            <a href="create.php" class="btn btn-primary">สร้างงานใหม่</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>เลขงาน</th>
                        <th>ลูกค้า</th>
                        <th>รายละเอียด</th>
                        <th>ประเภท</th>
                        <th>วันที่</th>
                        <th>สถานะ</th>
                        <th>Sale</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $j): ?>
                    <tr>
                        <td>
                            <a href="view.php?id=<?= $j['id'] ?>" class="fw-bold">
                                <?= e($j['job_number']) ?>
                            </a>
                        </td>
                        <td><?= e($j['customer_name']) ?></td>
                        <td>
                            <span class="text-truncate d-inline-block" style="max-width: 200px;">
                                <?= e($j['scope_short']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?= e($j['job_type']) ?></span>
                        </td>
                        <td>
                            <?= formatDate($j['plan_start_date']) ?> - <?= formatDate($j['plan_end_date']) ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= StatusMachine::getStatusBadgeClass($j['status']) ?>">
                                <?= StatusMachine::getStatusLabel($j['status']) ?>
                            </span>
                        </td>
                        <td><?= e($j['owner_sale_name']) ?></td>
                        <td>
                            <a href="view.php?id=<?= $j['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
