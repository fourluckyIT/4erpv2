<?php
/**
 * Job Timesheet Calendar View
 * ERP v2 - M6: Timesheet Module
 * 
 * Shows all timesheets for a job in calendar format
 * Rows: People, Columns: Dates
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
if (!$rbac->can('view', 'TIMESHEET')) {
    setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    redirect(BASE_URL);
}

$jobId = (int)($_GET['job_id'] ?? 0);
if (!$jobId) {
    setFlash('error', 'กรุณาระบุ Job');
    redirect(BASE_URL . '/modules/timesheet/');
}

$db = getDB();

// Get job info
$stmt = $db->prepare("
    SELECT j.*, c.name as customer_name, s.name as site_name
    FROM jobs j
    JOIN customers c ON j.customer_id = c.id
    LEFT JOIN sites s ON j.site_id = s.id
    WHERE j.id = ?
");
$stmt->execute([$jobId]);
$job = $stmt->fetch();

if (!$job) {
    setFlash('error', 'ไม่พบ Job');
    redirect(BASE_URL . '/modules/timesheet/');
}

// Get date range from job or timesheets
$stmt = $db->prepare("
    SELECT MIN(work_date) as min_date, MAX(work_date) as max_date
    FROM timesheets
    WHERE job_id = ?
");
$stmt->execute([$jobId]);
$dateRange = $stmt->fetch();

// Use job dates if available, otherwise use timesheet dates
$startDate = $job['plan_start_date'] ?? $dateRange['min_date'] ?? date('Y-m-d');
$endDate = $job['plan_end_date'] ?? $dateRange['max_date'] ?? date('Y-m-d');

// Generate date array
$dates = [];
$current = new DateTime($startDate);
$end = new DateTime($endDate);
while ($current <= $end) {
    $dates[] = $current->format('Y-m-d');
    $current->modify('+1 day');
}

// Get all people assigned to this job from plans
$stmt = $db->prepare("
    SELECT DISTINCT p.id, p.code, p.full_name, p.position
    FROM plan_assignments pa
    JOIN plans pl ON pa.plan_id = pl.id
    JOIN people p ON pa.people_id = p.id
    WHERE pl.job_id = ? AND pa.people_id IS NOT NULL AND p.is_active = 1
    ORDER BY p.full_name
");
$stmt->execute([$jobId]);
$people = $stmt->fetchAll();

// Get all timesheet entries for this job, indexed by date and people_id
$stmt = $db->prepare("
    SELECT t.work_date, te.people_id, te.is_present, te.check_in, te.check_out, 
           te.work_hours, te.ot_hours, te.is_late, te.absence_reason, t.id as timesheet_id, t.status
    FROM timesheets t
    JOIN timesheet_entries te ON t.id = te.timesheet_id
    WHERE t.job_id = ?
");
$stmt->execute([$jobId]);
$entriesRaw = $stmt->fetchAll();

// Index entries by date and people_id
$entries = [];
$timesheetsByDate = [];
foreach ($entriesRaw as $e) {
    $entries[$e['work_date']][$e['people_id']] = $e;
    $timesheetsByDate[$e['work_date']] = [
        'id' => $e['timesheet_id'],
        'status' => $e['status']
    ];
}

// Calculate summary
$totalDays = count($dates);
$totalPeople = count($people);

$pageTitle = 'Timesheet: ' . $job['job_number'] . ' - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-8">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="index.php">Timesheet</a></li>
                <li class="breadcrumb-item active"><?= e($job['job_number']) ?></li>
            </ol>
        </nav>
        <h2 class="mb-0">
            <i class="bi bi-calendar-check me-2"></i>Timesheet: <?= e($job['job_number']) ?>
        </h2>
        <p class="text-muted mb-0">
            <?= e($job['scope_short']) ?> | <?= e($job['customer_name']) ?>
            <?php if ($job['site_name']): ?> | <?= e($job['site_name']) ?><?php endif; ?>
        </p>
    </div>
    <div class="col-md-4 text-end">
        <?php if ($rbac->can('create', 'TIMESHEET')): ?>
        <a href="create.php?job_id=<?= $jobId ?>" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>สร้าง Timesheet วันใหม่
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Job Period Info -->
<div class="card mb-4">
    <div class="card-body py-2">
        <div class="row align-items-center">
            <div class="col-md-4">
                <strong>ช่วงเวลา Job:</strong> 
                <?= formatDate($startDate) ?> - <?= formatDate($endDate) ?>
                <span class="badge bg-secondary ms-2"><?= $totalDays ?> วัน</span>
            </div>
            <div class="col-md-4">
                <strong>จำนวนคนใน Job:</strong> 
                <span class="badge bg-primary"><?= $totalPeople ?> คน</span>
            </div>
            <div class="col-md-4 text-end">
                <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $jobId ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-briefcase me-1"></i>ดู Job
                </a>
            </div>
        </div>
    </div>
</div>

<?php if (empty($people)): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    ยังไม่มีคนถูก assign ใน Plan ของ Job นี้
</div>
<?php elseif (empty($dates)): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>
    ยังไม่มี Timesheet สำหรับ Job นี้
</div>
<?php else: ?>

<!-- Calendar Matrix Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-1"></i>ตารางเช็คชื่อ
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0 timesheet-calendar">
                <thead class="table-light sticky-top">
                    <tr>
                        <th class="sticky-col" style="min-width: 50px;">#</th>
                        <th class="sticky-col" style="min-width: 150px;">ชื่อ</th>
                        <th class="sticky-col" style="min-width: 100px;">ตำแหน่ง</th>
                        <?php foreach ($dates as $date): 
                            $d = new DateTime($date);
                            $isWeekend = in_array($d->format('N'), [6, 7]);
                            $isToday = $date === date('Y-m-d');
                            $tsInfo = $timesheetsByDate[$date] ?? null;
                        ?>
                        <th class="text-center date-col <?= $isWeekend ? 'bg-light' : '' ?> <?= $isToday ? 'table-warning' : '' ?>" 
                            style="min-width: 90px;">
                            <div class="small"><?= $d->format('D') ?></div>
                            <div><?= $d->format('j/n') ?></div>
                            <?php if ($tsInfo): ?>
                            <a href="view.php?id=<?= $tsInfo['id'] ?>" class="badge bg-<?= $tsInfo['status'] === 'Draft' ? 'secondary' : 'success' ?> text-decoration-none" title="<?= $tsInfo['status'] ?>">
                                <i class="bi bi-file-text"></i>
                            </a>
                            <?php endif; ?>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($people as $i => $person): ?>
                    <tr>
                        <td class="sticky-col text-center"><?= $i + 1 ?></td>
                        <td class="sticky-col fw-medium"><?= e($person['full_name']) ?></td>
                        <td class="sticky-col text-muted small"><?= e($person['position'] ?? '-') ?></td>
                        <?php foreach ($dates as $date): 
                            $entry = $entries[$date][$person['id']] ?? null;
                            $d = new DateTime($date);
                            $isWeekend = in_array($d->format('N'), [6, 7]);
                            $isToday = $date === date('Y-m-d');
                        ?>
                        <td class="text-center small <?= $isWeekend ? 'bg-light' : '' ?> <?= $isToday ? 'table-warning' : '' ?>">
                            <?php if ($entry): ?>
                                <?php if ($entry['is_present']): ?>
                                    <div class="text-success">
                                        <?= $entry['check_in'] ? substr($entry['check_in'], 0, 5) : '✓' ?>
                                    </div>
                                    <div class="text-danger">
                                        <?= $entry['check_out'] ? substr($entry['check_out'], 0, 5) : '-' ?>
                                    </div>
                                    <?php if ($entry['ot_hours'] > 0): ?>
                                    <div class="badge bg-warning text-dark" style="font-size: 0.65rem;">OT <?= number_format($entry['ot_hours'], 1) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary" title="<?= e($entry['absence_reason'] ?? 'ขาด') ?>">ขาด</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.timesheet-calendar {
    font-size: 0.85rem;
}
.timesheet-calendar .sticky-col {
    position: sticky;
    background: white;
    z-index: 1;
}
.timesheet-calendar .sticky-col:nth-child(1) { left: 0; }
.timesheet-calendar .sticky-col:nth-child(2) { left: 50px; }
.timesheet-calendar .sticky-col:nth-child(3) { left: 200px; }
.timesheet-calendar thead th.sticky-col {
    z-index: 2;
}
.timesheet-calendar td, .timesheet-calendar th {
    vertical-align: middle;
}
.date-col {
    white-space: nowrap;
}
</style>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
