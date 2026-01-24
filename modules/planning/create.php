<?php
/**
 * Create Plan
 * ERP v2 - Phase 5
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/Plan.php';

$auth = new Auth();
$auth->requireAuth();

$planModel = new Plan();
$db = getDB();

// Get job_id from URL
$jobId = (int) get('job_id', 0);

if (!$jobId) {
    setFlash('error', 'กรุณาระบุ Job');
    redirect('index.php');
}

// Get job
$stmt = $db->prepare("
    SELECT j.*, c.name as customer_name
    FROM jobs j
    LEFT JOIN customers c ON j.customer_id = c.id
    WHERE j.id = ?
");
$stmt->execute([$jobId]);
$job = $stmt->fetch();

if (!$job) {
    setFlash('error', 'ไม่พบ Job');
    redirect('index.php');
}

if ($job['status'] !== 'Approved') {
    setFlash('error', 'Job ต้องอยู่ในสถานะ Approved เท่านั้นจึงจะสร้าง Plan ได้');
    redirect('index.php');
}

// Check existing plan
$existingPlan = $planModel->getActiveByJobId($jobId);
if ($existingPlan) {
    setFlash('error', 'Job นี้มี Plan ที่ยังใช้งานอยู่แล้ว');
    redirect('view.php?id=' . $existingPlan['id']);
}

// Get available items by type
$devices = $db->query("
    SELECT s.*, i.name as item_name, i.code as item_code, i.item_type
    FROM serials s
    JOIN items i ON s.item_id = i.id
    WHERE s.status = 'Available' AND i.item_type = 'Device'
    ORDER BY i.name, s.serial_number
")->fetchAll();

$equipment = $db->query("
    SELECT s.*, i.name as item_name, i.code as item_code, i.item_type
    FROM serials s
    JOIN items i ON s.item_id = i.id
    WHERE s.status = 'Available' AND i.item_type = 'Equipment'
    ORDER BY i.name, s.serial_number
")->fetchAll();

$consumables = $db->query("
    SELECT i.*, COALESCE(i.quantity, 0) as available_qty
    FROM items i
    WHERE i.item_type = 'Consumable' AND i.is_active = 1
    ORDER BY i.name
")->fetchAll();

// Get active people (Manpower)
$people = $db->query("
    SELECT * FROM people WHERE is_active = 1 ORDER BY full_name
")->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action', '');
    
    if ($action === 'create_plan') {
        $data = [
            'plan_date' => post('plan_date', date('Y-m-d')),
            'notes' => post('notes', '')
        ];
        
        $result = $planModel->create($jobId, $data);
        
        if ($result['success']) {
            $planId = $result['id'];
            
            // Add people (Manpower) assignments
            $selectedPeople = post('people', []);
            foreach ($selectedPeople as $peopleId) {
                $planModel->addPeople($planId, (int)$peopleId);
            }
            
            // Add Device serial assignments
            $selectedDevices = post('devices', []);
            foreach ($selectedDevices as $serialId) {
                $planModel->addSerial($planId, (int)$serialId, 'Device');
            }
            
            // Add Equipment serial assignments
            $selectedEquipment = post('equipment', []);
            foreach ($selectedEquipment as $serialId) {
                $planModel->addSerial($planId, (int)$serialId, 'Equipment');
            }
            
            // Add Consumable assignments
            $consumables = post('consumables', []);
            foreach ($consumables as $itemId => $qty) {
                $qty = (int)$qty;
                if ($qty > 0) {
                    $planModel->addConsumable($planId, (int)$itemId, $qty);
                }
            }
            
            setFlash('success', 'สร้าง Plan สำเร็จ: ' . $result['plan_number'] . ' - กรุณา Confirm Plan แล้วจัด Route รถ');
            // Redirect to Plan view page (need to confirm before creating routes)
            redirect(BASE_URL . '/modules/planning/view.php?id=' . $planId);
        } else {
            setFlash('error', $result['error']);
        }
    }
}

$pageTitle = 'สร้าง Plan - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0"><i class="bi bi-plus-circle me-2"></i>สร้าง Plan</h2>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php">Planning</a></li>
                <li class="breadcrumb-item active">สร้างใหม่</li>
            </ol>
        </nav>
    </div>
</div>

<!-- Job Info -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <i class="bi bi-briefcase me-2"></i>ข้อมูล Job
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <strong>Job Number:</strong><br>
                <a href="../jobs/view.php?id=<?= $job['id'] ?>"><?= e($job['job_number']) ?></a>
            </div>
            <div class="col-md-3">
                <strong>ลูกค้า:</strong><br>
                <?= e($job['customer_name']) ?>
            </div>
            <div class="col-md-3">
                <strong>วันเริ่มงาน:</strong><br>
                <?= formatDate($job['plan_start_date']) ?>
            </div>
            <div class="col-md-3">
                <strong>วันสิ้นสุด:</strong><br>
                <?= formatDate($job['plan_end_date']) ?>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-12">
                <strong>รายละเอียด:</strong><br>
                <?= e($job['scope_short']) ?>
            </div>
        </div>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="action" value="create_plan">
    
    <!-- Plan Info -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-info-circle me-2"></i>ข้อมูล Plan
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันที่วางแผน <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="plan_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Resource Selection Tabs -->
    <ul class="nav nav-tabs mb-3" id="resourceTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="manpower-tab" data-bs-toggle="tab" data-bs-target="#manpower" type="button" role="tab">
                <i class="bi bi-people me-1"></i>Manpower
                <span class="badge bg-secondary ms-1" id="manpower-count">0</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="device-tab" data-bs-toggle="tab" data-bs-target="#device" type="button" role="tab">
                <i class="bi bi-cpu me-1"></i>Device
                <span class="badge bg-secondary ms-1" id="device-count">0</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="equipment-tab" data-bs-toggle="tab" data-bs-target="#equipment" type="button" role="tab">
                <i class="bi bi-tools me-1"></i>Equipment
                <span class="badge bg-secondary ms-1" id="equipment-count">0</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="consumable-tab" data-bs-toggle="tab" data-bs-target="#consumable" type="button" role="tab">
                <i class="bi bi-box me-1"></i>Consumable
                <span class="badge bg-secondary ms-1" id="consumable-count">0</span>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="resourceTabsContent">
        <!-- Manpower Tab -->
        <div class="tab-pane fade show active" id="manpower" role="tabpanel">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people me-2"></i>เลือกบุคลากร (Manpower)</span>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll('people')">เลือกทั้งหมด</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll('people')">ยกเลิกทั้งหมด</button>
                    </div>
                </div>
                <div class="card-body" style="max-height: 350px; overflow-y: auto;">
                    <?php if (empty($people)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-person-x text-muted" style="font-size: 2rem;"></i>
                        <p class="text-muted mt-2">ยังไม่มีบุคลากรในระบบ</p>
                        <a href="<?= BASE_URL ?>/modules/admin/people.php" class="btn btn-sm btn-outline-primary">เพิ่มบุคลากร</a>
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <?php foreach ($people as $person): ?>
                        <div class="col-md-4 col-lg-3 mb-2">
                            <div class="form-check">
                                <input class="form-check-input resource-check" type="checkbox" name="people[]" 
                                       value="<?= $person['id'] ?>" id="person_<?= $person['id'] ?>" data-type="manpower">
                                <label class="form-check-label" for="person_<?= $person['id'] ?>">
                                    <strong><?= e($person['code'] ?? '') ?></strong> <?= e($person['full_name']) ?>
                                    <?php if (!empty($person['position'])): ?>
                                    <br><small class="text-muted"><?= e($person['position']) ?></small>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Device Tab -->
        <div class="tab-pane fade" id="device" role="tabpanel">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-cpu me-2"></i>เลือกอุปกรณ์ (Device) - ต้องมี Serial</span>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll('devices')">เลือกทั้งหมด</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll('devices')">ยกเลิกทั้งหมด</button>
                    </div>
                </div>
                <div class="card-body" style="max-height: 350px; overflow-y: auto;">
                    <?php if (empty($devices)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-cpu text-muted" style="font-size: 2rem;"></i>
                        <p class="text-muted mt-2">ยังไม่มี Device ที่ว่าง</p>
                        <a href="<?= BASE_URL ?>/modules/admin/items.php" class="btn btn-sm btn-outline-primary">เพิ่มอุปกรณ์</a>
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <?php foreach ($devices as $d): ?>
                        <div class="col-md-4 col-lg-3 mb-2">
                            <div class="form-check">
                                <input class="form-check-input resource-check" type="checkbox" name="devices[]" 
                                       value="<?= $d['id'] ?>" id="device_<?= $d['id'] ?>" data-type="device">
                                <label class="form-check-label" for="device_<?= $d['id'] ?>">
                                    <strong><?= e($d['serial_number']) ?></strong>
                                    <br><small class="text-muted"><?= e($d['item_name']) ?></small>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Equipment Tab -->
        <div class="tab-pane fade" id="equipment" role="tabpanel">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-tools me-2"></i>เลือกอุปกรณ์ (Equipment) - ต้องมี Serial</span>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll('equipment')">เลือกทั้งหมด</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll('equipment')">ยกเลิกทั้งหมด</button>
                    </div>
                </div>
                <div class="card-body" style="max-height: 350px; overflow-y: auto;">
                    <?php if (empty($equipment)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-tools text-muted" style="font-size: 2rem;"></i>
                        <p class="text-muted mt-2">ยังไม่มี Equipment ที่ว่าง</p>
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <?php foreach ($equipment as $eq): ?>
                        <div class="col-md-4 col-lg-3 mb-2">
                            <div class="form-check">
                                <input class="form-check-input resource-check" type="checkbox" name="equipment[]" 
                                       value="<?= $eq['id'] ?>" id="equip_<?= $eq['id'] ?>" data-type="equipment">
                                <label class="form-check-label" for="equip_<?= $eq['id'] ?>">
                                    <strong><?= e($eq['serial_number']) ?></strong>
                                    <br><small class="text-muted"><?= e($eq['item_name']) ?></small>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Consumable Tab -->
        <div class="tab-pane fade" id="consumable" role="tabpanel">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-box me-2"></i>เลือกวัสดุสิ้นเปลือง (Consumable) - ระบุจำนวน
                </div>
                <div class="card-body" style="max-height: 350px; overflow-y: auto;">
                    <?php if (empty($consumables)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-box text-muted" style="font-size: 2rem;"></i>
                        <p class="text-muted mt-2">ยังไม่มีวัสดุสิ้นเปลือง</p>
                    </div>
                    <?php else: ?>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>รหัส</th>
                                <th>ชื่อ</th>
                                <th>คงเหลือ</th>
                                <th>จำนวนที่ต้องการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($consumables as $c): ?>
                            <tr>
                                <td><?= e($c['code']) ?></td>
                                <td><?= e($c['name']) ?></td>
                                <td><?= formatNumber($c['available_qty'], 0) ?> <?= e($c['unit'] ?? '') ?></td>
                                <td style="width: 120px;">
                                    <input type="number" class="form-control form-control-sm consumable-qty" 
                                           name="consumables[<?= $c['id'] ?>]" min="0" max="<?= $c['available_qty'] ?>" 
                                           value="0" data-type="consumable">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-success btn-lg">
            <i class="bi bi-check-circle me-1"></i>สร้าง Plan และไปจัด Route
        </button>
        <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $jobId ?>" class="btn btn-outline-secondary btn-lg">ยกเลิก</a>
    </div>
</form>

<script>
// Update badge counts
function updateCounts() {
    document.getElementById('manpower-count').textContent = document.querySelectorAll('input[name="people[]"]:checked').length;
    document.getElementById('device-count').textContent = document.querySelectorAll('input[name="devices[]"]:checked').length;
    document.getElementById('equipment-count').textContent = document.querySelectorAll('input[name="equipment[]"]:checked').length;
    
    let consumableCount = 0;
    document.querySelectorAll('.consumable-qty').forEach(input => {
        if (parseInt(input.value) > 0) consumableCount++;
    });
    document.getElementById('consumable-count').textContent = consumableCount;
}

// Select/Deselect all helpers
function selectAll(name) {
    document.querySelectorAll(`input[name="${name}[]"]`).forEach(cb => cb.checked = true);
    updateCounts();
}

function deselectAll(name) {
    document.querySelectorAll(`input[name="${name}[]"]`).forEach(cb => cb.checked = false);
    updateCounts();
}

// Event listeners
document.querySelectorAll('.resource-check').forEach(cb => {
    cb.addEventListener('change', updateCounts);
});

document.querySelectorAll('.consumable-qty').forEach(input => {
    input.addEventListener('change', updateCounts);
});

updateCounts();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
