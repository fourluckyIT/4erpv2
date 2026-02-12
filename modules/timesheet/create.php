<?php
/**
 * Create Timesheet
 * 4ERP - M6: Timesheet Module
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
if (!$rbac->can('create', 'TIMESHEET')) {
    setFlash('error', 'คุณไม่มีสิทธิ์สร้าง Timesheet');
    redirect(BASE_URL . '/modules/timesheet/');
}

$db = getDB();
$timesheet = new Timesheet();
$errors = [];

// Get active jobs (In Progress or Dispatched)
$jobs = $db->query("
    SELECT j.id, j.job_number, j.scope_short, j.site_id, 
           c.name as customer_name, s.name as site_name
    FROM jobs j
    JOIN customers c ON j.customer_id = c.id
    LEFT JOIN sites s ON j.site_id = s.id
    WHERE j.status IN ('Dispatched', 'In Progress', 'Waiting for Return', 'Planned', 'Approved')
    ORDER BY j.job_number DESC
")->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jobId = (int)($_POST['job_id'] ?? 0);
    $workDate = $_POST['work_date'] ?? '';
    $siteId = !empty($_POST['site_id']) ? (int)$_POST['site_id'] : null;
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if (!$jobId) {
        $errors[] = 'กรุณาเลือก Job';
    }
    if (!$workDate) {
        $errors[] = 'กรุณาระบุวันที่ทำงาน';
    }
    
    if (empty($errors)) {
        $result = $timesheet->create($jobId, $workDate, $siteId, $notes);
        
        if ($result['success']) {
            $msg = "สร้าง Timesheet {$result['ts_number']} สำเร็จ";
            if ($result['entries_added'] > 0) {
                $msg .= " (เพิ่มคนอัตโนมัติจาก Plan: {$result['entries_added']} คน)";
            }
            setFlash('success', $msg);
            redirect(BASE_URL . '/modules/timesheet/edit.php?id=' . $result['id']);
        } else {
            $errors[] = $result['error'];
        }
    }
}

$pageTitle = 'สร้าง Timesheet - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="index.php">Timesheet</a></li>
                <li class="breadcrumb-item active">สร้างใหม่</li>
            </ol>
        </nav>
        <h2 class="mb-0">
            <i class="bi bi-plus-circle me-2"></i>สร้าง Timesheet
        </h2>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Job <span class="text-danger">*</span></label>
                    <select name="job_id" id="job_id" class="form-select" required>
                        <option value="">-- เลือก Job --</option>
                        <?php foreach ($jobs as $j): ?>
                        <option value="<?= $j['id'] ?>" 
                                data-site-id="<?= $j['site_id'] ?>"
                                data-site-name="<?= e($j['site_name']) ?>"
                                <?= ($_POST['job_id'] ?? '') == $j['id'] ? 'selected' : '' ?>>
                            <?= e($j['job_number']) ?> - <?= e($j['customer_name']) ?> - <?= e(mb_substr($j['scope_short'], 0, 40)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">เฉพาะ Job ที่อยู่ในสถานะ Approved, Planned, Dispatched, In Progress หรือ Waiting for Return</div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">วันที่ทำงาน <span class="text-danger">*</span></label>
                    <input type="date" name="work_date" class="form-control" required
                           value="<?= e($_POST['work_date'] ?? date('Y-m-d')) ?>">
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Site</label>
                    <input type="text" id="site_display" class="form-control" readonly placeholder="(จาก Job)">
                    <input type="hidden" name="site_id" id="site_id">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">หมายเหตุ</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>
            
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Auto-populate:</strong> ระบบจะเพิ่มรายชื่อคนจาก Plan ที่ถูก Confirm ไว้สำหรับ Job นี้โดยอัตโนมัติ
            </div>
            
            <hr>
            
            <div class="d-flex justify-content-between">
                <a href="javascript:history.back()" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>ยกเลิก
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i>สร้าง Timesheet
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('job_id').addEventListener('change', function() {
    const option = this.options[this.selectedIndex];
    const siteId = option.dataset.siteId || '';
    const siteName = option.dataset.siteName || '(ไม่ระบุ)';
    
    document.getElementById('site_id').value = siteId;
    document.getElementById('site_display').value = siteName;
});

// Trigger on load if job is pre-selected
if (document.getElementById('job_id').value) {
    document.getElementById('job_id').dispatchEvent(new Event('change'));
}
</script>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
