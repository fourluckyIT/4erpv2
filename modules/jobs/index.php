<?php
/**
 * Job List / Dashboard
 * 4ERP - Phase 2
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/StatusMachine.php';
require_once __DIR__ . '/../../core/Job.php';

$auth = new Auth();
$auth->requireAuth();
$rbac = new RBAC();

$job = new Job();

// Filters
$filters = [
    'status' => get('status'),
    'search' => get('search'),
    'customer_id' => get('customer_id'),
];

$jobs = $job->getList(array_filter($filters));
$stats = $job->getStats();

$pageTitle = 'Jobs';
$currentPage = 'jobs';
$breadcrumbs = [['label' => 'Jobs']];
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-briefcase" style="color: var(--primary);"></i> Jobs
        </h1>
        <p class="page-subtitle">รายการงานทั้งหมด</p>
    </div>
    <a href="create.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> สร้างงานใหม่
    </a>
</div>

<!-- Stats Cards -->
<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="bi bi-briefcase" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= $stats['active'] ?? 0 ?></div>
            <div class="stat-label">งานที่กำลังดำเนินการ</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="bi bi-hourglass-split" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= $stats['pending_approval'] ?? 0 ?></div>
            <div class="stat-label">รออนุมัติ</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><i class="bi bi-check-circle" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= $stats['by_status']['Closed'] ?? 0 ?></div>
            <div class="stat-label">ปิดงานแล้ว</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info"><i class="bi bi-calendar-plus" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= $stats['this_month'] ?? 0 ?></div>
            <div class="stat-label">สร้างเดือนนี้</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-lg-5 col-md-6">
                <label class="form-label">ค้นหา</label>
                <input type="text" class="form-control" name="search" 
                       value="<?= e($filters['search'] ?? '') ?>" placeholder="เลขงาน, ชื่อลูกค้า...">
            </div>
            <div class="col-lg-4 col-md-6">
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
            <div class="col-lg-3 col-md-12 d-flex flex-wrap gap-2 justify-content-lg-end">
                <button type="submit" class="btn btn-primary">
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
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="text-nowrap">เลขงาน</th>
                        <th>ลูกค้า</th>
                        <th>รายละเอียด</th>
                        <th>ประเภท</th>
                        <th class="text-nowrap">วันที่</th>
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
                            <span class="text-truncate d-inline-block" style="max-width: 260px;">
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

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
