<?php
/**
 * Add/Edit Salary Record
 * ERP v2 - HR Module
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();

$peopleId = (int) get('people_id');

if (!$peopleId) {
    setFlash('error', 'ต้องระบุบุคลากร');
    redirect('index.php');
}

// Get person
$stmt = $db->prepare("SELECT * FROM people WHERE id = ?");
$stmt->execute([$peopleId]);
$person = $stmt->fetch();

if (!$person) {
    setFlash('error', 'ไม่พบบุคลากร');
    redirect('index.php');
}

// Get current salary
$stmt = $db->prepare("SELECT * FROM people_salary_history WHERE people_id = ? AND is_active = 1");
$stmt->execute([$peopleId]);
$currentSalary = $stmt->fetch();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("edit.php?people_id=$peopleId");
    }
    
    try {
        $db->beginTransaction();
        
        // Deactivate current salary
        $db->prepare("UPDATE people_salary_history SET is_active = 0 WHERE people_id = ? AND is_active = 1")
           ->execute([$peopleId]);
        
        // Insert new salary record
        $stmt = $db->prepare("
            INSERT INTO people_salary_history (
                people_id, effective_date, salary_type,
                base_salary, daily_rate, hourly_rate,
                position_allowance, transport_allowance, meal_allowance,
                housing_allowance, other_allowance, allowance_notes,
                ot_rate_multiplier, holiday_rate_multiplier,
                bank_name, bank_account, bank_branch,
                standard_hours_per_day, standard_days_per_month,
                change_reason, notes, is_active, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ");
        
        $stmt->execute([
            $peopleId,
            post('effective_date'),
            post('salary_type'),
            (float) post('base_salary', 0),
            (float) post('daily_rate', 0),
            (float) post('hourly_rate', 0),
            (float) post('position_allowance', 0),
            (float) post('transport_allowance', 0),
            (float) post('meal_allowance', 0),
            (float) post('housing_allowance', 0),
            (float) post('other_allowance', 0),
            post('allowance_notes'),
            (float) post('ot_rate_multiplier', 1.5),
            (float) post('holiday_rate_multiplier', 2.0),
            post('bank_name'),
            post('bank_account'),
            post('bank_branch'),
            (float) post('standard_hours_per_day', 8),
            (int) post('standard_days_per_month', 22),
            post('change_reason'),
            post('notes'),
            $_SESSION['user_id']
        ]);
        
        $salaryId = $db->lastInsertId();
        
        $audit->log('create', 'SALARY', $salaryId, null, ['people_id' => $peopleId]);
        
        $db->commit();
        
        setFlash('success', 'บันทึกเงินเดือนเรียบร้อย');
        redirect("../people/view.php?id=$peopleId");
        
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
    }
}

$pageTitle = 'เพิ่มเงินเดือน - ' . e($person['full_name']);
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-cash-stack me-2"></i>เพิ่มข้อมูลเงินเดือน</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../people/">People</a></li>
                    <li class="breadcrumb-item"><a href="../people/view.php?id=<?= $peopleId ?>"><?= e($person['full_name']) ?></a></li>
                    <li class="breadcrumb-item active">Salary</li>
                </ol>
            </nav>
        </div>
        <a href="../people/view.php?id=<?= $peopleId ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    บุคลากร: <strong><?= e($person['code']) ?> - <?= e($person['full_name']) ?></strong>
    (<?= $person['people_type'] === 'Employee' ? 'พนักงานประจำ' : 'แรงงานภายนอก' ?>)
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    
    <div class="row">
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">ข้อมูลเงินเดือน/ค่าแรง</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">มีผลตั้งแต่ <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="effective_date" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ประเภท <span class="text-danger">*</span></label>
                            <select class="form-select" name="salary_type" id="salaryType" required>
                                <option value="Monthly" <?= ($currentSalary['salary_type'] ?? '') === 'Monthly' ? 'selected' : '' ?>>รายเดือน</option>
                                <option value="Daily" <?= ($currentSalary['salary_type'] ?? '') === 'Daily' ? 'selected' : '' ?>>รายวัน</option>
                                <option value="Hourly" <?= ($currentSalary['salary_type'] ?? '') === 'Hourly' ? 'selected' : '' ?>>รายชั่วโมง</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="monthlyFields">
                        <div class="mb-3">
                            <label class="form-label">เงินเดือน (บาท/เดือน)</label>
                            <input type="number" class="form-control" name="base_salary" step="0.01"
                                   value="<?= $currentSalary['base_salary'] ?? '' ?>">
                        </div>
                    </div>
                    
                    <div id="dailyFields" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label">ค่าแรง (บาท/วัน)</label>
                            <input type="number" class="form-control" name="daily_rate" step="0.01"
                                   value="<?= $currentSalary['daily_rate'] ?? '' ?>">
                        </div>
                    </div>
                    
                    <div id="hourlyFields" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label">ค่าแรง (บาท/ชั่วโมง)</label>
                            <input type="number" class="form-control" name="hourly_rate" step="0.01"
                                   value="<?= $currentSalary['hourly_rate'] ?? '' ?>">
                        </div>
                    </div>
                    
                    <hr>
                    <h6>OT Multiplier</h6>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">วันธรรมดา/วันหยุด</label>
                            <input type="number" class="form-control" name="ot_rate_multiplier" step="0.1"
                                   value="<?= $currentSalary['ot_rate_multiplier'] ?? '1.5' ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">วันหยุดนักขัตฤกษ์</label>
                            <input type="number" class="form-control" name="holiday_rate_multiplier" step="0.1"
                                   value="<?= $currentSalary['holiday_rate_multiplier'] ?? '2.0' ?>">
                        </div>
                    </div>
                    
                    <hr>
                    <h6>ชั่วโมงทำงานมาตรฐาน</h6>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">ชม./วัน</label>
                            <input type="number" class="form-control" name="standard_hours_per_day" step="0.5"
                                   value="<?= $currentSalary['standard_hours_per_day'] ?? '8' ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">วัน/เดือน</label>
                            <input type="number" class="form-control" name="standard_days_per_month"
                                   value="<?= $currentSalary['standard_days_per_month'] ?? '22' ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">เบี้ยเลี้ยง/เงินเพิ่ม</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">ค่าตำแหน่ง</label>
                        <input type="number" class="form-control" name="position_allowance" step="0.01"
                               value="<?= $currentSalary['position_allowance'] ?? '0' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ค่าเดินทาง</label>
                        <input type="number" class="form-control" name="transport_allowance" step="0.01"
                               value="<?= $currentSalary['transport_allowance'] ?? '0' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ค่าอาหาร</label>
                        <input type="number" class="form-control" name="meal_allowance" step="0.01"
                               value="<?= $currentSalary['meal_allowance'] ?? '0' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ค่าที่พัก</label>
                        <input type="number" class="form-control" name="housing_allowance" step="0.01"
                               value="<?= $currentSalary['housing_allowance'] ?? '0' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">เงินเพิ่มอื่นๆ</label>
                        <input type="number" class="form-control" name="other_allowance" step="0.01"
                               value="<?= $currentSalary['other_allowance'] ?? '0' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุเบี้ยเลี้ยง</label>
                        <input type="text" class="form-control" name="allowance_notes"
                               value="<?= e($currentSalary['allowance_notes'] ?? '') ?>">
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">ข้อมูลธนาคาร</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">ธนาคาร</label>
                        <input type="text" class="form-control" name="bank_name"
                               value="<?= e($currentSalary['bank_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">เลขบัญชี</label>
                        <input type="text" class="form-control" name="bank_account"
                               value="<?= e($currentSalary['bank_account'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">สาขา</label>
                        <input type="text" class="form-control" name="bank_branch"
                               value="<?= e($currentSalary['bank_branch'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">เหตุผลการเปลี่ยนแปลง</label>
                    <input type="text" class="form-control" name="change_reason" placeholder="เช่น ปรับขึ้นเงินเดือนประจำปี">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">หมายเหตุ</label>
                    <input type="text" class="form-control" name="notes">
                </div>
            </div>
        </div>
    </div>
    
    <div class="mb-4">
        <button type="submit" class="btn btn-success">
            <i class="bi bi-check-circle me-1"></i>บันทึก
        </button>
        <a href="../people/view.php?id=<?= $peopleId ?>" class="btn btn-outline-secondary">ยกเลิก</a>
    </div>
</form>

<script>
document.getElementById('salaryType').addEventListener('change', function() {
    const type = this.value;
    document.getElementById('monthlyFields').style.display = type === 'Monthly' ? 'block' : 'none';
    document.getElementById('dailyFields').style.display = type === 'Daily' ? 'block' : 'none';
    document.getElementById('hourlyFields').style.display = type === 'Hourly' ? 'block' : 'none';
});
// Trigger on load
document.getElementById('salaryType').dispatchEvent(new Event('change'));
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
