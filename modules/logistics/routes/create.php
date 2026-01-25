<?php
/**
 * Create/Manage Routes
 * ERP v2 - Phase 5 v2 - Redesigned
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

// Get existing routes for this plan
$existingRoutes = $routeModel->getByPlanId($planId);
$routesWithItems = [];
foreach ($existingRoutes as $route) {
    $route['items'] = $routeModel->getItems($route['id']);
    $routesWithItems[] = $route;
}

// Get all assigned serials/people in existing routes
$assignedSerialIds = [];
$assignedPeopleIds = [];
foreach ($routesWithItems as $route) {
    foreach ($route['items'] as $item) {
        if ($item['serial_id']) $assignedSerialIds[] = $item['serial_id'];
        if ($item['people_id']) $assignedPeopleIds[] = $item['people_id'];
    }
}

// Get available vehicles
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

// Get plan assignments
$planAssignments = $planModel->getAssignments($planId);

// Separate into serials, people, consumables - and mark which are already assigned
$serialAssignments = [];
$peopleAssignments = [];
$consumableAssignments = [];

foreach ($planAssignments as $a) {
    if ($a['serial_id']) {
        $a['is_assigned'] = in_array($a['serial_id'], $assignedSerialIds);
        $serialAssignments[] = $a;
    } elseif ($a['people_id']) {
        $a['is_assigned'] = in_array($a['people_id'], $assignedPeopleIds);
        $peopleAssignments[] = $a;
    } elseif ($a['item_id'] && $a['assignment_type'] === 'Consumable') {
        $consumableAssignments[] = $a;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action', 'create');
    
    if ($action === 'create') {
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
                $stmt = $db->prepare("SELECT i.item_type FROM serials s JOIN items i ON s.item_id = i.id WHERE s.id = ?");
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
            
            // Add consumables
            $consumables = post('consumables', []);
            foreach ($consumables as $itemId => $qty) {
                $qty = (int)$qty;
                if ($qty > 0) {
                    $routeModel->addConsumable($result['id'], (int)$itemId, $qty);
                }
            }
            
            setFlash('success', 'สร้าง Route สำเร็จ: ' . $result['route_number']);
            redirect('create.php?plan_id=' . $planId);
        } else {
            setFlash('error', $result['error']);
        }
    } elseif ($action === 'update_route') {
        $routeId = (int) post('route_id');
        // Handle route item updates via AJAX instead
        setFlash('success', 'อัพเดท Route สำเร็จ');
        redirect('create.php?plan_id=' . $planId);
    }
}

$pageTitle = 'จัดการ Routes - ' . $plan['plan_number'];
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-truck me-2"></i>จัดการ Routes</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Routes</a></li>
                    <li class="breadcrumb-item"><a href="../../planning/view.php?id=<?= $planId ?>"><?= e($plan['plan_number']) ?></a></li>
                    <li class="breadcrumb-item active">จัดการ Routes</li>
                </ol>
            </nav>
        </div>
        <a href="../../planning/view.php?id=<?= $planId ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<!-- Job Info Bar -->
<div class="alert alert-info mb-4">
    <div class="row align-items-center">
        <div class="col-md-3">
            <strong><i class="bi bi-briefcase me-1"></i>Job:</strong>
            <a href="../../jobs/view.php?id=<?= $plan['job_id'] ?>"><?= e($plan['job_number']) ?></a>
        </div>
        <div class="col-md-3">
            <strong>ลูกค้า:</strong> <?= e($plan['customer_name']) ?>
        </div>
        <div class="col-md-4">
            <strong>รายละเอียด:</strong> <?= e($plan['scope_short']) ?>
        </div>
        <div class="col-md-2 text-end">
            <span class="badge bg-success fs-6"><?= count($existingRoutes) ?> Routes</span>
        </div>
    </div>
</div>

<div class="row">
    <!-- Left Column: Create New Route -->
    <div class="col-lg-6">
        <form method="POST" id="routeForm">
            <input type="hidden" name="action" value="create">
            
            <!-- Route Details -->
            <div class="card mb-3">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-plus-circle me-2"></i>สร้าง Route ใหม่
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันที่ส่ง <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="route_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ปลายทาง</label>
                            <input type="text" class="form-control" name="destination" placeholder="สถานที่ส่งของ">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">รถ/ยานพาหนะ</label>
                            <select class="form-select" name="vehicle_serial_id" id="vehicleSelect">
                                <option value="">-- รถตัวเอง/เลือกรถ --</option>
                                <?php foreach ($vehicles as $v): ?>
                                <option value="<?= $v['id'] ?>"><?= e($v['serial_number']) ?> - <?= e($v['item_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Supplier (ถ้าใช้รถภายนอก)</label>
                            <select class="form-select" name="supplier_id" id="supplierSelect">
                                <option value="">-- ไม่ระบุ --</option>
                                <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= e($s['code']) ?> - <?= e($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ชื่อคนขับ</label>
                            <input type="text" class="form-control" name="driver_name">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">เบอร์โทร</label>
                            <input type="text" class="form-control" name="driver_phone">
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Items to Assign -->
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-box-seam me-2"></i>เลือกของที่จะส่ง</span>
                    <small class="text-muted">เลือกแล้ว: <span id="selectedCount">0</span> รายการ</small>
                </div>
                <div class="card-body p-0">
                    <!-- Tabs for item types -->
                    <ul class="nav nav-tabs nav-fill" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-serials" type="button">
                                <i class="bi bi-upc-scan me-1"></i>Serial
                                <span class="badge bg-secondary" id="serial-badge"><?= count(array_filter($serialAssignments, fn($a) => !$a['is_assigned'])) ?></span>
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-people" type="button">
                                <i class="bi bi-people me-1"></i>บุคลากร
                                <span class="badge bg-secondary" id="people-badge"><?= count(array_filter($peopleAssignments, fn($a) => !$a['is_assigned'])) ?></span>
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-consumables" type="button">
                                <i class="bi bi-box me-1"></i>Consumable
                                <span class="badge bg-secondary" id="consumable-badge"><?= count($consumableAssignments) ?></span>
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content">
                        <!-- Serials Tab -->
                        <div class="tab-pane fade show active p-3" id="tab-serials" style="max-height: 300px; overflow-y: auto;">
                            <?php 
                            $unassignedSerials = array_filter($serialAssignments, fn($a) => !$a['is_assigned']);
                            if (empty($unassignedSerials)): 
                            ?>
                            <div class="text-center text-success py-3">
                                <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                                <p class="mb-0">จัดส่งครบแล้ว!</p>
                            </div>
                            <?php else: ?>
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th width="40"><input type="checkbox" class="form-check-input" id="selectAllSerials"></th>
                                        <th>Serial</th>
                                        <th>รายการ</th>
                                        <th>สภาพ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unassignedSerials as $a): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="form-check-input item-check" 
                                                   name="serials[]" value="<?= $a['serial_id'] ?>" data-type="serial">
                                        </td>
                                        <td><strong><?= e($a['serial_number']) ?></strong></td>
                                        <td>
                                            <small><?= e($a['item_code']) ?></small> - <?= e($a['item_name']) ?>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm" name="condition_<?= $a['serial_id'] ?>" style="width: 80px;">
                                                <option value="Good">ดี</option>
                                                <option value="Fair">พอใช้</option>
                                                <option value="Damaged">ชำรุด</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                        
                        <!-- People Tab -->
                        <div class="tab-pane fade p-3" id="tab-people" style="max-height: 300px; overflow-y: auto;">
                            <?php 
                            $unassignedPeople = array_filter($peopleAssignments, fn($a) => !$a['is_assigned']);
                            if (empty($unassignedPeople)): 
                            ?>
                            <div class="text-center text-success py-3">
                                <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                                <p class="mb-0">จัดส่งบุคลากรครบแล้ว!</p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($unassignedPeople as $a): ?>
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input item-check" 
                                       name="people[]" value="<?= $a['people_id'] ?>" id="person_<?= $a['people_id'] ?>" data-type="people">
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
                        
                        <!-- Consumables Tab -->
                        <div class="tab-pane fade p-3" id="tab-consumables" style="max-height: 300px; overflow-y: auto;">
                            <?php if (empty($consumableAssignments)): ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-box" style="font-size: 2rem;"></i>
                                <p class="mb-0">ไม่มี Consumable ใน Plan นี้</p>
                            </div>
                            <?php else: ?>
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>รหัส</th>
                                        <th>รายการ</th>
                                        <th width="100">จำนวน</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($consumableAssignments as $a): ?>
                                    <tr>
                                        <td><strong><?= e($a['item_code']) ?></strong></td>
                                        <td><?= e($a['item_name']) ?></td>
                                        <td>
                                            <input type="number" class="form-control form-control-sm consumable-qty" 
                                                   name="consumables[<?= $a['item_id'] ?>]" 
                                                   value="0" min="0" max="<?= (int)$a['quantity'] ?>" 
                                                   data-max="<?= (int)$a['quantity'] ?>" style="width: 80px;">
                                            <small class="text-muted">/ <?= (int)$a['quantity'] ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-success w-100" id="createRouteBtn">
                        <i class="bi bi-plus-circle me-1"></i>สร้าง Route
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Right Column: Existing Routes -->
    <div class="col-lg-6">
        <div class="sticky-top" style="top: 70px;">
            <?php if (empty($routesWithItems)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-truck text-muted" style="font-size: 3rem;"></i>
                    <h5 class="text-muted mt-3">ยังไม่มี Route</h5>
                    <p class="text-muted">เลือกของจากฝั่งซ้ายและสร้าง Route ใหม่</p>
                </div>
            </div>
            <?php else: ?>
            <?php foreach ($routesWithItems as $route): ?>
            <div class="card mb-3 route-card" data-route-id="<?= $route['id'] ?>">
                <div class="card-header bg-<?= match($route['status']) {
                    'Draft' => 'secondary',
                    'Confirmed' => 'info',
                    'Dispatched' => 'warning',
                    'Delivered' => 'success',
                    'Cancelled' => 'danger',
                    default => 'secondary'
                } ?> text-white d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?= e($route['route_number']) ?></strong>
                        <small class="ms-2"><?= formatDate($route['route_date']) ?></small>
                    </div>
                    <div>
                        <a href="view.php?id=<?= $route['id'] ?>" class="btn btn-sm btn-light">
                            <i class="bi bi-eye"></i>
                        </a>
                    </div>
                </div>
                <div class="card-body py-2">
                    <?php if ($route['destination']): ?>
                    <small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= e($route['destination']) ?></small>
                    <?php endif; ?>
                    <?php if ($route['vehicle_serial']): ?>
                    <small class="text-muted ms-2"><i class="bi bi-truck me-1"></i><?= e($route['vehicle_serial']) ?></small>
                    <?php endif; ?>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($route['items'] as $item): ?>
                    <li class="list-group-item py-2 d-flex justify-content-between align-items-center">
                        <div>
                            <?php if ($item['serial_id']): ?>
                            <span class="badge bg-info me-1"><?= e($item['item_type']) ?></span>
                            <strong><?= e($item['serial_number']) ?></strong>
                            <small class="text-muted"><?= e($item['item_name']) ?></small>
                            <?php elseif ($item['item_id'] && $item['item_type'] === 'Consumable'): ?>
                            <span class="badge bg-secondary me-1">Consumable</span>
                            <strong><?= e($item['item_code']) ?></strong>
                            <small class="text-muted"><?= e($item['item_name']) ?></small>
                            <span class="badge bg-primary ms-1"><?= (int)$item['quantity'] ?></span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark me-1">บุคลากร</span>
                            <strong><?= e($item['people_code']) ?></strong>
                            <small class="text-muted"><?= e($item['people_name']) ?></small>
                            <?php endif; ?>
                        </div>
                        <?php if ($route['status'] === 'Draft'): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger py-0" 
                                onclick="removeFromRoute(<?= $route['id'] ?>, <?= $item['id'] ?>)">
                            <i class="bi bi-x"></i>
                        </button>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($route['items'])): ?>
                    <li class="list-group-item text-muted text-center py-3">ยังไม่มีของ</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Update selected count
function updateSelectedCount() {
    const count = document.querySelectorAll('.item-check:checked').length;
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('createRouteBtn').disabled = count === 0;
}

// Select all serials
document.getElementById('selectAllSerials')?.addEventListener('change', function() {
    document.querySelectorAll('#tab-serials .item-check').forEach(cb => {
        cb.checked = this.checked;
    });
    updateSelectedCount();
});

// Item check listeners
document.querySelectorAll('.item-check').forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});

// Remove item from route
function removeFromRoute(routeId, itemId) {
    if (!confirm('ลบรายการนี้ออกจาก Route?')) return;
    
    fetch('<?= BASE_URL ?>/modules/logistics/routes/api/remove_item.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({route_id: routeId, item_id: itemId})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'เกิดข้อผิดพลาด');
        }
    });
}

updateSelectedCount();
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
