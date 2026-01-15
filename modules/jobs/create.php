<?php
/**
 * Create Job
 * ERP v2 - Phase 2
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/StatusMachine.php';
require_once __DIR__ . '/../../core/Job.php';

$auth = new Auth();
$auth->requireAuth();

// Check permission
$rbac = new RBAC();
if (!$rbac->can('create', 'JOB')) {
    setFlash('error', 'คุณไม่มีสิทธิ์สร้างงาน');
    redirect('index.php');
}

$db = getDB();
$jobModel = new Job();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('create.php');
    }
    
    $data = [
        'customer_id' => (int) post('customer_id'),
        'site_id' => post('site_id') ?: null,
        'job_type' => post('job_type'),
        'scope_short' => sanitize(post('scope_short')),
        'scope_detail' => post('scope_detail'),
        'quotation_no' => sanitize(post('quotation_no')),
        'plan_start_date' => post('plan_start_date'),
        'plan_end_date' => post('plan_end_date'),
        'owner_sale_id' => (int) post('owner_sale_id'),
        'owner_planner_id' => post('owner_planner_id') ?: null,
        'contract_value' => (float) post('contract_value', 0),
        'budget' => (float) post('budget', 0),
    ];
    
    $result = $jobModel->create($data);
    
    if ($result['success']) {
        setFlash('success', 'สร้างงาน ' . $result['job_number'] . ' เรียบร้อย');
        redirect('view.php?id=' . $result['id']);
    } else {
        setFlash('error', $result['error']);
    }
}

// Get dropdown data
$customers = $db->query("SELECT id, code, name FROM customers WHERE is_active = 1 ORDER BY name")->fetchAll();
$sales = $db->query("
    SELECT u.id, u.full_name 
    FROM users u 
    JOIN user_roles ur ON u.id = ur.user_id 
    JOIN roles r ON ur.role_id = r.id 
    WHERE r.code IN ('SAL', 'ADM') AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();
$planners = $db->query("
    SELECT u.id, u.full_name 
    FROM users u 
    JOIN user_roles ur ON u.id = ur.user_id 
    JOIN roles r ON ur.role_id = r.id 
    WHERE r.code IN ('PLN', 'ADM') AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

$pageTitle = 'Create Job - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-plus-circle me-2"></i>สร้างงานใหม่
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Jobs</a></li>
                    <li class="breadcrumb-item active">Create</li>
                </ol>
            </nav>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    
    <div class="row">
        <div class="col-lg-8">
            <!-- Main Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-info-circle me-2"></i>ข้อมูลหลัก
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ลูกค้า <span class="text-danger">*</span></label>
                            <select class="form-select" name="customer_id" id="customer_id" required>
                                <option value="">-- เลือกลูกค้า --</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>">
                                    <?= e($c['code']) ?> - <?= e($c['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Site</label>
                            <select class="form-select" name="site_id" id="site_id">
                                <option value="">-- เลือก site --</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ประเภทงาน <span class="text-danger">*</span></label>
                            <select class="form-select" name="job_type" required>
                                <option value="">-- เลือก --</option>
                                <option value="Lumpsum">Lumpsum (เหมา)</option>
                                <option value="Dayrent">Dayrent (รายวัน)</option>
                                <option value="Manpower">Manpower (คนงาน)</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">อ้างอิงใบเสนอราคา</label>
                            <input type="text" class="form-control" name="quotation_no" placeholder="Q-2026-XXXXX">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">รายละเอียดงาน (สั้น) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="scope_short" maxlength="500" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">รายละเอียดเพิ่มเติม</label>
                        <textarea class="form-control" name="scope_detail" rows="4"></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Dates -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-calendar me-2"></i>ระยะเวลา
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันเริ่มงาน <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="plan_start_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันสิ้นสุด <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="plan_end_date" required>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <!-- Owners -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-people me-2"></i>ผู้รับผิดชอบ
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Sale <span class="text-danger">*</span></label>
                        <select class="form-select" name="owner_sale_id" required>
                            <option value="">-- เลือก --</option>
                            <?php foreach ($sales as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $s['id'] == $auth->getCurrentUserId() ? 'selected' : '' ?>>
                                <?= e($s['full_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Planner</label>
                        <select class="form-select" name="owner_planner_id">
                            <option value="">-- ยังไม่กำหนด --</option>
                            <?php foreach ($planners as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= e($p['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Financial -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-currency-exchange me-2"></i>มูลค่า
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">มูลค่าสัญญา</label>
                        <input type="number" class="form-control" name="contract_value" step="0.01" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">งบประมาณ</label>
                        <input type="number" class="form-control" name="budget" step="0.01" value="0">
                    </div>
                </div>
            </div>
            
            <!-- Submit -->
            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-check-circle me-2"></i>สร้างงาน
                </button>
            </div>
        </div>
    </div>
</form>

<script>
// Load sites when customer changes
document.getElementById('customer_id').addEventListener('change', function() {
    const customerId = this.value;
    const siteSelect = document.getElementById('site_id');
    
    siteSelect.innerHTML = '<option value="">-- กำลังโหลด... --</option>';
    
    if (!customerId) {
        siteSelect.innerHTML = '<option value="">-- เลือก site --</option>';
        return;
    }
    
    fetch('/4erpv2/modules/jobs/api.php?action=get_sites&customer_id=' + customerId)
        .then(r => r.json())
        .then(data => {
            siteSelect.innerHTML = '<option value="">-- เลือก site --</option>';
            data.forEach(site => {
                siteSelect.innerHTML += `<option value="${site.id}">${site.name}</option>`;
            });
        });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
