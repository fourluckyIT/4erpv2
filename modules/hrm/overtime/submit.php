<?php
/**
 * Submit OT Request
 * ERP v2 - HR Module
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();

// Get active people
$people = $db->query("SELECT id, code, full_name, position FROM people WHERE is_active = 1 ORDER BY full_name")->fetchAll();

// Get jobs for linking
$jobs = $db->query("SELECT id, job_number, scope_short FROM jobs WHERE status NOT IN ('Closed', 'Voided') ORDER BY job_number DESC LIMIT 50")->fetchAll();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('submit.php');
    }
    
    try {
        $peopleId = (int) post('people_id');
        $workDate = post('work_date');
        $otType = post('ot_type');
        $startTime = post('start_time');
        $endTime = post('end_time');
        $breakMinutes = (int) post('break_minutes', 0);
        
        // Calculate hours
        $start = strtotime("$workDate $startTime");
        $end = strtotime("$workDate $endTime");
        if ($end < $start) $end += 86400; // Next day
        $totalMinutes = ($end - $start) / 60 - $breakMinutes;
        $totalHours = max(0, $totalMinutes / 60);
        
        // Get salary info for rate calculation
        $stmt = $db->prepare("SELECT * FROM people_salary_history WHERE people_id = ? AND is_active = 1");
        $stmt->execute([$peopleId]);
        $salary = $stmt->fetch();
        
        if (!$salary) {
            throw new Exception('ไม่พบข้อมูลเงินเดือน กรุณาเพิ่มข้อมูลเงินเดือนก่อน');
        }
        
        // Calculate base rate (hourly)
        $baseRate = 0;
        if ($salary['salary_type'] === 'Monthly') {
            $baseRate = $salary['base_salary'] / ($salary['standard_days_per_month'] * $salary['standard_hours_per_day']);
        } elseif ($salary['salary_type'] === 'Daily') {
            $baseRate = $salary['daily_rate'] / $salary['standard_hours_per_day'];
        } else {
            $baseRate = $salary['hourly_rate'];
        }
        
        // Get multiplier
        $multiplier = $otType === 'Holiday' ? $salary['holiday_rate_multiplier'] : $salary['ot_rate_multiplier'];
        
        // Calculate OT amount
        $otAmount = $baseRate * $totalHours * $multiplier;
        
        $stmt = $db->prepare("
            INSERT INTO people_overtime (
                people_id, work_date, ot_type, start_time, end_time, break_minutes,
                total_hours, base_rate, multiplier, ot_amount, job_id, notes, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)
        ");
        
        $stmt->execute([
            $peopleId,
            $workDate,
            $otType,
            $startTime,
            $endTime,
            $breakMinutes,
            $totalHours,
            $baseRate,
            $multiplier,
            $otAmount,
            post('job_id') ?: null,
            post('notes'),
            $_SESSION['user_id']
        ]);
        
        $otId = $db->lastInsertId();
        $audit->log('create', 'OVERTIME', $otId);
        
        setFlash('success', 'บันทึก OT เรียบร้อย (รออนุมัติ)');
        redirect('index.php?status=Pending');
        
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }
}

$pageTitle = 'บันทึก OT - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-clock me-2"></i>บันทึก OT</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../people/">HR</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Overtime</a></li>
                    <li class="breadcrumb-item active">บันทึก</li>
                </ol>
            </nav>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    
    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">ข้อมูล OT</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">พนักงาน <span class="text-danger">*</span></label>
                            <select class="form-select" name="people_id" required>
                                <option value="">-- เลือก --</option>
                                <?php foreach ($people as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['full_name']) ?> (<?= e($p['position'] ?: $p['code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันที่ทำ OT <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="work_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">ประเภท OT <span class="text-danger">*</span></label>
                            <select class="form-select" name="ot_type" required>
                                <option value="Weekday">วันธรรมดา (1.5x)</option>
                                <option value="Weekend">วันหยุดสัปดาห์ (1.5x)</option>
                                <option value="Holiday">วันหยุดนักขัตฤกษ์ (2x)</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">เวลาเริ่ม <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="start_time" value="18:00" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">เวลาสิ้นสุด <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="end_time" value="21:00" required>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">พัก (นาที)</label>
                            <input type="number" class="form-control" name="break_minutes" value="0" min="0">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Job (ถ้ามี)</label>
                            <select class="form-select" name="job_id">
                                <option value="">-- ไม่ระบุ --</option>
                                <?php foreach ($jobs as $j): ?>
                                <option value="<?= $j['id'] ?>"><?= e($j['job_number']) ?> - <?= e(mb_substr($j['scope_short'], 0, 40)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">หมายเหตุ</label>
                            <input type="text" class="form-control" name="notes" placeholder="รายละเอียดงาน OT">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mb-4">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle me-1"></i>บันทึก
                </button>
                <a href="index.php" class="btn btn-outline-secondary">ยกเลิก</a>
            </div>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
