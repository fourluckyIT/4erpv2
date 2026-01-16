<?php
/**
 * Create Dispatch Note
 * ERP v2 - Phase 5
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/Dispatch.php';
require_once __DIR__ . '/../../../core/Plan.php';

$auth = new Auth();
$auth->requireAuth();

$dispatchModel = new Dispatch();
$planModel = new Plan();
$db = getDB();

// Get plan_id from URL
$planId = (int) get('plan_id', 0);

if (!$planId) {
    setFlash('error', 'กรุณาระบุ Plan');
    redirect('index.php');
}

// Get plan
$plan = $planModel->getById($planId);
if (!$plan) {
    setFlash('error', 'ไม่พบ Plan');
    redirect('index.php');
}

if ($plan['status'] !== 'Confirmed') {
    setFlash('error', 'Plan ต้องอยู่ในสถานะ Confirmed เท่านั้นจึงจะสร้าง Dispatch Note ได้');
    redirect('../planning/view.php?id=' . $planId);
}

// Get plan assignments (serials only)
$assignments = $planModel->getAssignments($planId);
$serialAssignments = array_filter($assignments, fn($a) => $a['serial_id'] !== null);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'dispatch_date' => post('dispatch_date', date('Y-m-d')),
        'vehicle_info' => post('vehicle_info', ''),
        'driver_name' => post('driver_name', ''),
        'driver_phone' => post('driver_phone', ''),
        'notes' => post('notes', '')
    ];
    
    $result = $dispatchModel->create($planId, $data);
    
    if ($result['success']) {
        // Add selected serials
        $selectedSerials = post('serials', []);
        foreach ($selectedSerials as $serialId) {
            $condition = post('condition_' . $serialId, 'Good');
            $dispatchModel->addItem($result['id'], (int)$serialId, $condition);
        }
        
        setFlash('success', 'สร้าง Dispatch Note สำเร็จ: ' . $result['do_number']);
        redirect('view.php?id=' . $result['id']);
    } else {
        setFlash('error', $result['error']);
    }
}

$pageTitle = 'สร้าง Dispatch Note - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0"><i class="bi bi-truck me-2"></i>สร้าง Dispatch Note</h2>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php">Dispatch</a></li>
                <li class="breadcrumb-item active">สร้างใหม่</li>
            </ol>
        </nav>
    </div>
</div>

<!-- Plan & Job Info -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-info text-white">
                <i class="bi bi-calendar-check me-2"></i>ข้อมูล Plan
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="35%">Plan Number:</th>
                        <td><a href="../../planning/view.php?id=<?= $plan['id'] ?>"><?= e($plan['plan_number']) ?></a></td>
                    </tr>
                    <tr>
                        <th>วันที่วางแผน:</th>
                        <td><?= formatDate($plan['plan_date']) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-briefcase me-2"></i>ข้อมูล Job
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="35%">Job Number:</th>
                        <td><a href="../../jobs/view.php?id=<?= $plan['job_id'] ?>"><?= e($plan['job_number']) ?></a></td>
                    </tr>
                    <tr>
                        <th>ลูกค้า:</th>
                        <td><?= e($plan['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <th>รายละเอียด:</th>
                        <td><?= e($plan['scope_short']) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<form method="POST">
    <div class="row">
        <!-- Dispatch Info -->
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-info-circle me-2"></i>ข้อมูลการจัดส่ง
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">วันที่จัดส่ง <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="dispatch_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ข้อมูลรถ/ยานพาหนะ</label>
                        <input type="text" class="form-control" name="vehicle_info" placeholder="ทะเบียนรถ หรือ รายละเอียด">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">ชื่อคนขับ</label>
                                <input type="text" class="form-control" name="driver_name">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">เบอร์โทร</label>
                                <input type="text" class="form-control" name="driver_phone">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Serial Selection -->
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-upc-scan me-2"></i>เลือก Serial Numbers ที่จะจัดส่ง
                </div>
                <div class="card-body">
                    <?php if (empty($serialAssignments)): ?>
                    <p class="text-muted">ไม่มี Serial ใน Plan นี้</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAll" class="form-check-input"></th>
                                    <th>Serial</th>
                                    <th>Item</th>
                                    <th>สภาพ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($serialAssignments as $a): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="form-check-input serial-check" name="serials[]" value="<?= $a['serial_id'] ?>" checked>
                                    </td>
                                    <td><strong><?= e($a['serial_number']) ?></strong></td>
                                    <td><?= e($a['item_code']) ?> - <?= e($a['item_name']) ?></td>
                                    <td>
                                        <select class="form-select form-select-sm" name="condition_<?= $a['serial_id'] ?>">
                                            <option value="Good">ดี</option>
                                            <option value="Fair">พอใช้</option>
                                            <option value="Damaged">เสียหาย</option>
                                        </select>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-truck me-1"></i>สร้าง Dispatch Note
        </button>
        <a href="index.php" class="btn btn-outline-secondary btn-lg">ยกเลิก</a>
    </div>
</form>

<script>
document.getElementById('selectAll').addEventListener('change', function() {
    document.querySelectorAll('.serial-check').forEach(cb => cb.checked = this.checked);
});
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
