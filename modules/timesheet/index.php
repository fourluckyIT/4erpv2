<?php
/**
 * Timesheet - Job List
 * ERP v2 - M6: Timesheet Module
 * 
 * Shows jobs with timesheet activity - click to view calendar
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
if (!$rbac->can('view', 'TIMESHEET')) {
    setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    redirect(BASE_URL);
}

$db = getDB();

// Get jobs that are active (have workers assigned or have timesheets)
$jobs = $db->query("
    SELECT j.id, j.job_number, j.scope_short, j.status, j.plan_start_date, j.plan_end_date,
           c.name as customer_name, s.name as site_name,
           (SELECT COUNT(DISTINCT t.id) FROM timesheets t WHERE t.job_id = j.id) as timesheet_count,
           (SELECT COUNT(DISTINCT t.work_date) FROM timesheets t WHERE t.job_id = j.id) as days_recorded,
           (SELECT COUNT(DISTINCT pa.people_id) FROM plan_assignments pa 
            JOIN plans p ON pa.plan_id = p.id 
            WHERE p.job_id = j.id AND pa.people_id IS NOT NULL) as people_count,
           (SELECT MAX(t.work_date) FROM timesheets t WHERE t.job_id = j.id) as last_timesheet_date
    FROM jobs j
    JOIN customers c ON j.customer_id = c.id
    LEFT JOIN sites s ON j.site_id = s.id
    WHERE j.status IN ('Approved', 'Planned', 'Dispatched', 'In Progress', 'Returned', 'WH Received')
    ORDER BY 
        CASE WHEN j.status = 'In Progress' THEN 1
             WHEN j.status = 'Dispatched' THEN 2
             WHEN j.status = 'Planned' THEN 3
             ELSE 4 END,
        j.plan_start_date DESC
")->fetchAll();

$pageTitle = 'Timesheet - เช็คชื่อประจำวัน';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-0">
                    <i class="bi bi-calendar-check me-2"></i>Timesheet - เช็คชื่อประจำวัน
                </h2>
                <p class="text-muted mb-0">เลือก Job เพื่อดูตารางเช็คชื่อ</p>
            </div>
            <?php if ($rbac->can('create', 'TIMESHEET')): ?>
            <a href="create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>สร้าง Timesheet
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Job List -->
<?php if (empty($jobs)): ?>
<div class="card">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-briefcase display-4"></i>
        <p class="mt-2">ไม่พบ Job ที่กำลังดำเนินการ</p>
    </div>
</div>
<?php else: ?>
<div class="row">
    <?php foreach ($jobs as $job): ?>
    <div class="col-md-6 col-lg-4 mb-4">
        <div class="card h-100 job-card" style="cursor: pointer;" onclick="window.location='job_view.php?job_id=<?= $job['id'] ?>'">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold"><?= e($job['job_number']) ?></span>
                <span class="badge bg-<?= match($job['status']) {
                    'In Progress' => 'success',
                    'Dispatched' => 'primary',
                    'Planned' => 'info',
                    'Approved' => 'secondary',
                    default => 'secondary'
                } ?>"><?= e($job['status']) ?></span>
            </div>
            <div class="card-body">
                <h6 class="card-title text-truncate" title="<?= e($job['scope_short']) ?>">
                    <?= e($job['scope_short']) ?>
                </h6>
                <p class="card-text small text-muted mb-2">
                    <i class="bi bi-building me-1"></i><?= e($job['customer_name']) ?>
                    <?php if ($job['site_name']): ?>
                    <br><i class="bi bi-geo-alt me-1"></i><?= e($job['site_name']) ?>
                    <?php endif; ?>
                </p>
                
                <?php if ($job['plan_start_date'] && $job['plan_end_date']): ?>
                <p class="card-text small mb-2">
                    <i class="bi bi-calendar-range me-1"></i>
                    <?= formatDate($job['plan_start_date']) ?> - <?= formatDate($job['plan_end_date']) ?>
                </p>
                <?php endif; ?>
                
                <div class="d-flex justify-content-between mt-3">
                    <div class="text-center">
                        <div class="h5 mb-0 text-primary"><?= $job['people_count'] ?></div>
                        <small class="text-muted">คน</small>
                    </div>
                    <div class="text-center">
                        <div class="h5 mb-0 text-success"><?= $job['days_recorded'] ?></div>
                        <small class="text-muted">วันบันทึก</small>
                    </div>
                    <div class="text-center">
                        <div class="h5 mb-0 text-info"><?= $job['timesheet_count'] ?></div>
                        <small class="text-muted">Timesheet</small>
                    </div>
                </div>
            </div>
            <div class="card-footer text-end">
                <?php if ($job['last_timesheet_date']): ?>
                <small class="text-muted me-2">ล่าสุด: <?= formatDate($job['last_timesheet_date']) ?></small>
                <?php endif; ?>
                <a href="job_view.php?job_id=<?= $job['id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-table me-1"></i>ดูตาราง
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.job-card:hover {
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    transform: translateY(-2px);
    transition: all 0.2s ease;
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
