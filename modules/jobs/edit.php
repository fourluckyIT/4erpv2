<?php
/**
 * Edit Job
 * 4ERP - Phase 2
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/StatusMachine.php';
require_once __DIR__ . '/../../core/Job.php';

$auth = new Auth();
$auth->requireAuth();

$jobId = (int) get('id');
if (!$jobId) {
    setFlash('error', 'Invalid job ID');
    redirect('index.php');
}

$jobModel = new Job();
$job = $jobModel->getById($jobId);

if (!$job) {
    setFlash('error', 'ไม่พบงานนี้');
    redirect('index.php');
}

// Check if anything is editable at this status
$canEdit = StatusMachine::isFieldEditable($job['status'], 'scope_short');
if (!$canEdit) {
    setFlash('error', 'ไม่สามารถแก้ไขงานที่สถานะ ' . StatusMachine::getStatusLabel($job['status']));
    redirect("view.php?id=$jobId");
}

$db = getDB();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("edit.php?id=$jobId");
    }
    
    $data = [
        'scope_short' => sanitize(post('scope_short')),
        'scope_detail' => post('scope_detail'),
        'plan_start_date' => post('plan_start_date'),
        'plan_end_date' => post('plan_end_date'),
        'budget' => (float) post('budget', 0),
        'owner_planner_id' => post('owner_planner_id') ?: null,
    ];
    
    // Only include fields that can be edited
    $editableData = [];
    foreach ($data as $field => $value) {
        if (StatusMachine::isFieldEditable($job['status'], $field)) {
            $editableData[$field] = $value;
        }
    }
    
    $result = $jobModel->update($jobId, $editableData);
    
    if ($result['success']) {
        setFlash('success', 'บันทึกเรียบร้อย');
        redirect("view.php?id=$jobId");
    } else {
        setFlash('error', $result['error']);
    }
}

// Get dropdown data
$planners = $db->query("
    SELECT u.id, u.full_name 
    FROM users u 
    JOIN user_roles ur ON u.id = ur.user_id 
    JOIN roles r ON ur.role_id = r.id 
    WHERE r.code IN ('PLN', 'ADM') AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

$pageTitle = 'Edit ' . $job['job_number'] . ' - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-pencil me-2"></i>แก้ไข <?= e($job['job_number']) ?>
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Jobs</a></li>
                    <li class="breadcrumb-item"><a href="view.php?id=<?= $jobId ?>"><?= e($job['job_number']) ?></a></li>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </nav>
        </div>
        <a href="view.php?id=<?= $jobId ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    สถานะปัจจุบัน: <strong><?= StatusMachine::getStatusLabel($job['status']) ?></strong>
    - บางฟิลด์อาจถูกล็อกไม่สามารถแก้ไขได้
</div>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    
    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-info-circle me-2"></i>ข้อมูลงาน
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ลูกค้า</label>
                            <input type="text" class="form-control" value="<?= e($job['customer_name']) ?>" disabled>
                            <small class="text-muted">ไม่สามารถเปลี่ยนลูกค้าได้</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ประเภทงาน</label>
                            <input type="text" class="form-control" value="<?= e($job['job_type']) ?>" disabled>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">รายละเอียดงาน (สั้น)</label>
                        <?php if (StatusMachine::isFieldEditable($job['status'], 'scope_short')): ?>
                        <input type="text" class="form-control" name="scope_short" value="<?= e($job['scope_short']) ?>" required>
                        <?php else: ?>
                        <input type="text" class="form-control" value="<?= e($job['scope_short']) ?>" disabled>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">รายละเอียดเพิ่มเติม</label>
                        <?php if (StatusMachine::isFieldEditable($job['status'], 'scope_detail')): ?>
                        <textarea class="form-control" name="scope_detail" rows="4"><?= e($job['scope_detail']) ?></textarea>
                        <?php else: ?>
                        <textarea class="form-control" rows="4" disabled><?= e($job['scope_detail']) ?></textarea>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-calendar me-2"></i>ระยะเวลา
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันเริ่มงาน</label>
                            <?php if (StatusMachine::isFieldEditable($job['status'], 'plan_start_date')): ?>
                            <input type="date" class="form-control" name="plan_start_date" value="<?= $job['plan_start_date'] ?>">
                            <?php else: ?>
                            <input type="date" class="form-control" value="<?= $job['plan_start_date'] ?>" disabled>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันสิ้นสุด</label>
                            <?php if (StatusMachine::isFieldEditable($job['status'], 'plan_end_date')): ?>
                            <input type="date" class="form-control" name="plan_end_date" value="<?= $job['plan_end_date'] ?>">
                            <?php else: ?>
                            <input type="date" class="form-control" value="<?= $job['plan_end_date'] ?>" disabled>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-people me-2"></i>ผู้รับผิดชอบ
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Sale</label>
                        <input type="text" class="form-control" value="<?= e($job['owner_sale_name']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Planner</label>
                        <?php if (StatusMachine::isFieldEditable($job['status'], 'owner_planner_id')): ?>
                        <select class="form-select" name="owner_planner_id">
                            <option value="">-- ยังไม่กำหนด --</option>
                            <?php foreach ($planners as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $job['owner_planner_id'] == $p['id'] ? 'selected' : '' ?>>
                                <?= e($p['full_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="text" class="form-control" value="<?= e($job['owner_planner_name'] ?? '-') ?>" disabled>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-currency-exchange me-2"></i>มูลค่า
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">มูลค่าสัญญา</label>
                        <input type="text" class="form-control" value="<?= formatNumber($job['contract_value']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">งบประมาณ</label>
                        <?php if (StatusMachine::isFieldEditable($job['status'], 'budget')): ?>
                        <input type="number" class="form-control" name="budget" step="0.01" value="<?= $job['budget'] ?>">
                        <?php else: ?>
                        <input type="text" class="form-control" value="<?= formatNumber($job['budget']) ?>" disabled>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-check-circle me-2"></i>บันทึก
                </button>
            </div>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
