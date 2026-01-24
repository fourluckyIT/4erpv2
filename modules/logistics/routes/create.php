<?php
/**
 * Create Route
 * ERP v2 - Phase 5 v2
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/Route.php';
require_once __DIR__ . '/../../../core/Plan.php';

$auth = new Auth();
$auth->requireAuth();

$routeModel = new Route();
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
    setFlash('error', 'Plan ต้องอยู่ในสถานะ Confirmed เท่านั้นจึงจะสร้าง Route ได้');
    redirect('../../planning/view.php?id=' . $planId);
}

// Get available vehicles (serials with item_type = Vehicle and status = Available or Allocated to this plan)
$vehicles = $db->query("
    SELECT s.*, i.name as item_name, i.code as item_code
    FROM serials s
    JOIN items i ON s.item_id = i.id
    WHERE i.item_type = 'Vehicle'
    AND (s.status = 'Available' OR s.current_job_id = {$plan['job_id']})
    ORDER BY i.name, s.serial_number
")->fetchAll();

// Get suppliers
$suppliers = $db->query("
    SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name
")->fetchAll();

// Get plan assignments for this plan
$planAssignments = $planModel->getAssignments($planId);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'route_date' => post('route_date', date('Y-m-d')),
        'vehicle_serial_id' => post('vehicle_serial_id') ?: null,
        'supplier_id' => post('supplier_id') ?: null,
        'driver_name' => post('driver_name', ''),
        'driver_phone' => post('driver_phone', ''),
        'destination' => post('destination', ''),
        'notes' => post('notes', '')
    ];
    
    $result = $routeModel->create($planId, $data);
    
    if ($result['success']) {
        // Add selected serials
        $selectedSerials = post('serials', []);
        foreach ($selectedSerials as $serialId) {
            // Find item type for this serial
            $stmt = $db->prepare("
                SELECT i.item_type FROM serials s 
                JOIN items i ON s.item_id = i.id 
                WHERE s.id = ?
            ");
            $stmt->execute([$serialId]);
            $itemType = $stmt->fetchColumn();
            
            $condition = post('condition_' . $serialId, 'Good');
            $routeModel->addSerial($result['id'], (int)$serialId, $itemType, $condition);
        }
        
        // Add selected people
        $selectedPeople = post('people', []);
        foreach ($selectedPeople as $peopleId) {
            $routeModel->addPeople($result['id'], (int)$peopleId);
        }
        
        setFlash('success', 'สร้าง Route สำเร็จ: ' . $result['route_number']);
        redirect('view.php?id=' . $result['id']);
    } else {
        setFlash('error', $result['error']);
    }
}

$pageTitle = 'สร้าง Route - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0"><i class="bi bi-plus-circle me-2"></i>สร้าง Route</h2>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php">Routes</a></li>
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
        <!-- Route Info -->
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-truck me-2"></i>ข้อมูลเส้นทาง
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">วันที่ส่ง <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="route_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ปลายทาง</label>
                        <input type="text" class="form-control" name="destination" placeholder="สถานที่ส่งของ">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รถ/ยานพาหนะ</label>
                        <div class="input-group">
                            <select class="form-select" name="vehicle_serial_id" id="vehicleSelect">
                                <option value="">-- เลือกรถ --</option>
                                <?php foreach ($vehicles as $v): ?>
                                <option value="<?= $v['id'] ?>"><?= e($v['serial_number']) ?> - <?= e($v['item_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" onclick="showNewVehicleModal()" title="เพิ่มรถใหม่">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Supplier (ถ้าใช้รถภายนอก)</label>
                        <div class="input-group">
                            <select class="form-select" name="supplier_id" id="supplierSelect">
                                <option value="">-- ไม่ระบุ --</option>
                                <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= e($s['code']) ?> - <?= e($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" onclick="showNewSupplierModal()" title="เพิ่ม Supplier ใหม่">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
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
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Assign Items -->
        <div class="col-md-6">
            <!-- Serials -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-upc-scan me-2"></i>เลือก Serial Numbers
                </div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                    <?php 
                    $serialAssignments = array_filter($planAssignments, fn($a) => $a['serial_id'] !== null);
                    if (empty($serialAssignments)): 
                    ?>
                    <p class="text-muted">ไม่มี Serial ใน Plan นี้</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAllSerials" class="form-check-input"></th>
                                    <th>Serial</th>
                                    <th>Item</th>
                                    <th>สภาพ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($serialAssignments as $a): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="form-check-input serial-check" 
                                               name="serials[]" value="<?= $a['serial_id'] ?>" checked>
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
            
            <!-- People -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-people me-2"></i>เลือกบุคลากร
                </div>
                <div class="card-body" style="max-height: 200px; overflow-y: auto;">
                    <?php 
                    $peopleAssignments = array_filter($planAssignments, fn($a) => $a['people_id'] !== null);
                    if (empty($peopleAssignments)): 
                    ?>
                    <p class="text-muted">ไม่มีบุคลากรใน Plan นี้</p>
                    <?php else: ?>
                    <?php foreach ($peopleAssignments as $a): ?>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="people[]" 
                               value="<?= $a['people_id'] ?>" id="person_<?= $a['people_id'] ?>" checked>
                        <label class="form-check-label" for="person_<?= $a['people_id'] ?>">
                            <strong><?= e($a['people_code']) ?></strong> - <?= e($a['people_name']) ?>
                            <?php if ($a['position']): ?>
                            <small class="text-muted">(<?= e($a['position']) ?>)</small>
                            <?php endif; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-success btn-lg">
            <i class="bi bi-check-circle me-1"></i>สร้าง Route
        </button>
        <a href="../../planning/view.php?id=<?= $planId ?>" class="btn btn-outline-secondary btn-lg">ยกเลิก</a>
    </div>
</form>

<script>
document.getElementById('selectAllSerials')?.addEventListener('change', function() {
    document.querySelectorAll('.serial-check').forEach(cb => cb.checked = this.checked);
});

// New Supplier Modal
function showNewSupplierModal() {
    document.getElementById('newSupCode').value = '';
    document.getElementById('newSupName').value = '';
    document.getElementById('newSupContact').value = '';
    document.getElementById('newSupPhone').value = '';
    const modal = new bootstrap.Modal(document.getElementById('newSupplierModal'));
    modal.show();
}

function saveNewSupplier() {
    const code = document.getElementById('newSupCode').value.trim();
    const name = document.getElementById('newSupName').value.trim();
    const contact = document.getElementById('newSupContact').value.trim();
    const phone = document.getElementById('newSupPhone').value.trim();
    
    if (!code || !name) {
        alert('กรุณาระบุรหัสและชื่อผู้ขาย');
        return;
    }
    
    fetch('<?= BASE_URL ?>/modules/master/api/supplier_create.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({code, name, contact_person: contact, phone})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const select = document.getElementById('supplierSelect');
            const option = new Option(`${code} - ${name}`, data.id, true, true);
            select.add(option);
            bootstrap.Modal.getInstance(document.getElementById('newSupplierModal')).hide();
        } else {
            alert(data.error || 'เกิดข้อผิดพลาด');
        }
    })
    .catch(err => alert('เกิดข้อผิดพลาด: ' + err));
}

// New Vehicle Modal
function showNewVehicleModal() {
    document.getElementById('newVehSerial').value = '';
    document.getElementById('newVehName').value = '';
    document.getElementById('newVehPlate').value = '';
    const modal = new bootstrap.Modal(document.getElementById('newVehicleModal'));
    modal.show();
}

function saveNewVehicle() {
    const serial = document.getElementById('newVehSerial').value.trim();
    const name = document.getElementById('newVehName').value.trim();
    const plate = document.getElementById('newVehPlate').value.trim();
    
    if (!serial || !name) {
        alert('กรุณาระบุทะเบียนและชื่อรถ');
        return;
    }
    
    fetch('<?= BASE_URL ?>/modules/master/api/vehicle_create.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({serial_number: serial, name: name, license_plate: plate})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const select = document.getElementById('vehicleSelect');
            const option = new Option(`${serial} - ${name}`, data.id, true, true);
            select.add(option);
            bootstrap.Modal.getInstance(document.getElementById('newVehicleModal')).hide();
        } else {
            alert(data.error || 'เกิดข้อผิดพลาด');
        }
    })
    .catch(err => alert('เกิดข้อผิดพลาด: ' + err));
}
</script>

<!-- New Supplier Modal -->
<div class="modal fade" id="newSupplierModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-building-add me-2"></i>เพิ่ม Supplier ใหม่</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">รหัส <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="newSupCode" placeholder="เช่น SUP001">
                </div>
                <div class="mb-3">
                    <label class="form-label">ชื่อ <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="newSupName" placeholder="ชื่อบริษัท/ร้านค้า">
                </div>
                <div class="mb-3">
                    <label class="form-label">ผู้ติดต่อ</label>
                    <input type="text" class="form-control" id="newSupContact">
                </div>
                <div class="mb-3">
                    <label class="form-label">เบอร์โทร</label>
                    <input type="text" class="form-control" id="newSupPhone">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-primary" onclick="saveNewSupplier()">
                    <i class="bi bi-check me-1"></i>บันทึก
                </button>
            </div>
        </div>
    </div>
</div>

<!-- New Vehicle Modal -->
<div class="modal fade" id="newVehicleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-truck me-2"></i>เพิ่มรถใหม่</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">ทะเบียน/Serial <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="newVehSerial" placeholder="เช่น กข-1234">
                </div>
                <div class="mb-3">
                    <label class="form-label">ชื่อ/รุ่นรถ <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="newVehName" placeholder="เช่น รถบรรทุก 6 ล้อ">
                </div>
                <div class="mb-3">
                    <label class="form-label">ป้ายทะเบียน</label>
                    <input type="text" class="form-control" id="newVehPlate" placeholder="เช่น 1กก 1234 กทม">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-primary" onclick="saveNewVehicle()">
                    <i class="bi bi-check me-1"></i>บันทึก
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
