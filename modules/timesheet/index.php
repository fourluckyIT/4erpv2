<?php
/**
 * Timesheet List
 * ERP v2 - M6: Timesheet Module
 * 
 * Daily attendance check-in for job workers
 * Flow: Site Lead check-in → HR approve → Manager final approve
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

// Filters
$filterJob = (int) get('job_id', 0);
$filterStatus = get('status', '');
$filterDate = get('work_date', '');

// Build query
$where = ['1=1'];
$params = [];

if ($filterJob) {
    $where[] = 't.job_id = ?';
    $params[] = $filterJob;
}
if ($filterStatus) {
    $where[] = 't.status = ?';
    $params[] = $filterStatus;
}
if ($filterDate) {
    $where[] = 't.work_date = ?';
    $params[] = $filterDate;
}

$sql = "
    SELECT t.*, j.job_number, j.scope_short, c.name as customer_name,
           s.name as site_name, u.full_name as created_by_name,
           (SELECT COUNT(*) FROM timesheet_entries te WHERE te.timesheet_id = t.id) as entry_count,
           (SELECT COUNT(*) FROM timesheet_entries te WHERE te.timesheet_id = t.id AND te.is_present = 1) as present_count
    FROM timesheets t
    JOIN jobs j ON t.job_id = j.id
    JOIN customers c ON j.customer_id = c.id
    LEFT JOIN sites s ON t.site_id = s.id
    LEFT JOIN users u ON t.created_by = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY t.work_date DESC, t.created_at DESC
    LIMIT 100
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$timesheets = $stmt->fetchAll();

// Get jobs for filter dropdown
$jobs = $db->query("
    SELECT j.id, j.job_number, c.name as customer_name
    FROM jobs j
    JOIN customers c ON j.customer_id = c.id
    WHERE j.status IN ('Approved', 'Planned', 'Dispatched', 'In Progress')
    ORDER BY j.job_number DESC
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
                <p class="text-muted mb-0">บันทึกการเข้างานของพนักงานในแต่ละ Job</p>
            </div>
            <?php if ($rbac->can('create', 'TIMESHEET')): ?>
            <a href="create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>สร้าง Timesheet
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Job</label>
                <select name="job_id" class="form-select">
                    <option value="">-- ทั้งหมด --</option>
                    <?php foreach ($jobs as $j): ?>
                    <option value="<?= $j['id'] ?>" <?= $filterJob == $j['id'] ? 'selected' : '' ?>>
                        <?= e($j['job_number']) ?> - <?= e($j['customer_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">วันที่</label>
                <input type="date" name="work_date" class="form-control" value="<?= e($filterDate) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">สถานะ</label>
                <select name="status" class="form-select">
                    <option value="">-- ทั้งหมด --</option>
                    <option value="Draft" <?= $filterStatus === 'Draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="Confirmed" <?= $filterStatus === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="Submitted" <?= $filterStatus === 'Submitted' ? 'selected' : '' ?>>Submitted</option>
                    <option value="PayrollReady" <?= $filterStatus === 'PayrollReady' ? 'selected' : '' ?>>PayrollReady</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i>ค้นหา
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Timesheet List -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($timesheets)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-calendar-x display-4"></i>
            <p class="mt-2">ไม่พบ Timesheet</p>
            <?php if ($rbac->can('create', 'TIMESHEET')): ?>
            <a href="create.php" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle me-1"></i>สร้าง Timesheet ใหม่
            </a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่</th>
                        <th>วันที่</th>
                        <th>Job</th>
                        <th>Site</th>
                        <th class="text-center">จำนวนคน</th>
                        <th class="text-center">มาทำงาน</th>
                        <th>สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timesheets as $ts): ?>
                    <tr>
                        <td>
                            <a href="view.php?id=<?= $ts['id'] ?>" class="fw-bold text-decoration-none">
                                <?= e($ts['ts_number']) ?>
                            </a>
                        </td>
                        <td><?= formatDate($ts['work_date']) ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $ts['job_id'] ?>" class="text-decoration-none">
                                <?= e($ts['job_number']) ?>
                            </a>
                            <br><small class="text-muted"><?= e(mb_substr($ts['scope_short'], 0, 30)) ?></small>
                        </td>
                        <td><?= e($ts['site_name'] ?? '-') ?></td>
                        <td class="text-center"><?= $ts['entry_count'] ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?= $ts['present_count'] > 0 ? 'success' : 'secondary' ?>">
                                <?= $ts['present_count'] ?>/<?= $ts['entry_count'] ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?= match($ts['status']) {
                                'Draft' => 'secondary',
                                'Confirmed' => 'info',
                                'Submitted' => 'primary',
                                'PayrollReady' => 'success',
                                'Returned' => 'warning',
                                'Voided' => 'danger',
                                default => 'secondary'
                            } ?>"><?= e($ts['status']) ?></span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="view.php?id=<?= $ts['id'] ?>" class="btn btn-outline-primary" title="ดู">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if ($ts['status'] === 'Draft' && $rbac->can('edit', 'TIMESHEET')): ?>
                                <a href="edit.php?id=<?= $ts['id'] ?>" class="btn btn-outline-warning" title="แก้ไข/เช็คชื่อ">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                            </div>
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
