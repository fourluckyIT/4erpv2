<?php
/**
 * Planning Dashboard
 * ERP v2 - Phase 5
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/Plan.php';

$auth = new Auth();
$auth->requireAuth();

$planModel = new Plan();

// Get jobs awaiting planning
$jobsAwaitingPlan = $planModel->getJobsAwaitingPlan();

// Get existing plans
$statusFilter = get('status', '');
$filters = [];
if ($statusFilter) {
    $filters['status'] = $statusFilter;
}
$plans = $planModel->getList($filters);

$pageTitle = 'Planning - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Planning (วางแผนทรัพยากร)</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">หน้าหลัก</a></li>
                    <li class="breadcrumb-item active">Planning</li>
                </ol>
            </nav>
        </div>
    </div>
</div>

<!-- Jobs Awaiting Plan -->
<?php if (!empty($jobsAwaitingPlan)): ?>
<div class="card mb-4 border-primary">
    <div class="card-header bg-primary text-white">
        <i class="bi bi-hourglass-split me-2"></i>Jobs รอวางแผน (<?= count($jobsAwaitingPlan) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Job Number</th>
                        <th>ลูกค้า</th>
                        <th>รายละเอียด</th>
                        <th>วันที่เริ่ม</th>
                        <th>วันที่สิ้นสุด</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobsAwaitingPlan as $job): ?>
                    <tr>
                        <td>
                            <a href="../jobs/view.php?id=<?= $job['id'] ?>">
                                <strong><?= e($job['job_number']) ?></strong>
                            </a>
                        </td>
                        <td><?= e($job['customer_name']) ?></td>
                        <td><?= e(mb_substr($job['scope_short'], 0, 50)) ?><?= mb_strlen($job['scope_short']) > 50 ? '...' : '' ?></td>
                        <td><?= formatDate($job['plan_start_date']) ?></td>
                        <td><?= formatDate($job['plan_end_date']) ?></td>
                        <td>
                            <a href="create.php?job_id=<?= $job['id'] ?>" class="btn btn-sm btn-success">
                                <i class="bi bi-plus-circle me-1"></i>สร้าง Plan
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
                    <option value="Draft" <?= $statusFilter === 'Draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="Confirmed" <?= $statusFilter === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="Cancelled" <?= $statusFilter === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">กรอง</button>
                <a href="index.php" class="btn btn-outline-secondary">ล้าง</a>
            </div>
        </form>
    </div>
</div>

<!-- Plans List -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-check me-2"></i>Plans ทั้งหมด
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Plan Number</th>
                        <th>Job</th>
                        <th>ลูกค้า</th>
                        <th>วันที่วางแผน</th>
                        <th>สถานะ</th>
                        <th>สร้างเมื่อ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
                    <?php else: ?>
                    <?php foreach ($plans as $plan): ?>
                    <tr>
                        <td>
                            <a href="view.php?id=<?= $plan['id'] ?>">
                                <strong><?= e($plan['plan_number']) ?></strong>
                            </a>
                        </td>
                        <td>
                            <a href="../jobs/view.php?id=<?= $plan['job_id'] ?>" class="text-decoration-none">
                                <?= e($plan['job_number']) ?>
                            </a>
                        </td>
                        <td><?= e($plan['customer_name']) ?></td>
                        <td><?= formatDate($plan['plan_date']) ?></td>
                        <td>
                            <span class="badge bg-<?= match($plan['status']) {
                                'Draft' => 'secondary',
                                'Confirmed' => 'success',
                                'Cancelled' => 'danger',
                                default => 'secondary'
                            } ?>"><?= match($plan['status']) {
                                'Draft' => 'แบบร่าง',
                                'Confirmed' => 'ยืนยันแล้ว',
                                'Cancelled' => 'ยกเลิก',
                                default => $plan['status']
                            } ?></span>
                        </td>
                        <td><?= formatDate($plan['created_at']) ?></td>
                        <td>
                            <a href="view.php?id=<?= $plan['id'] ?>" class="btn btn-sm btn-outline-primary">
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
