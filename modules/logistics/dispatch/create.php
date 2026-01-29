<?php
/**
 * Create Dispatch Note
 * 4ERP - Phase 5
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/Dispatch.php';
require_once __DIR__ . '/../../../core/Plan.php';
require_once __DIR__ . '/../../../core/Route.php';
require_once __DIR__ . '/../../../core/EvidencePhoto.php';

$auth = new Auth();
$auth->requireAuth();

$dispatchModel = new Dispatch();
$planModel = new Plan();
$routeModel = new Route();
$photoModel = new EvidencePhoto();
$db = getDB();
$audit = new AuditLog();

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

// Job info for display
$jobInfo = null;
try {
    $stmt = $db->prepare("
        SELECT j.job_number, j.plan_start_date, j.plan_end_date,
               c.name as customer_name, s.name as site_name
        FROM jobs j
        LEFT JOIN customers c ON j.customer_id = c.id
        LEFT JOIN sites s ON j.site_id = s.id
        WHERE j.id = ?
    ");
    $stmt->execute([$plan['job_id']]);
    $jobInfo = $stmt->fetch() ?: null;
} catch (Exception $e) {
    $jobInfo = null;
}

// Get plan assignments (serials only)
$assignments = $planModel->getAssignments($planId);
$serialAssignments = array_filter($assignments, fn($a) => $a['serial_id'] !== null);
$peopleAssignments = array_filter($assignments, fn($a) => $a['people_id'] !== null);

// Routes for this plan (Planner must create first)
$routes = $routeModel->getByPlanId($planId);
$routeCount = count($routes);
$routeItemCounts = [];
if (!empty($routes)) {
    $routeIds = array_column($routes, 'id');
    $placeholders = implode(',', array_fill(0, count($routeIds), '?'));
    $stmt = $db->prepare("SELECT route_id, COUNT(*) as cnt FROM route_items WHERE route_id IN ($placeholders) GROUP BY route_id");
    $stmt->execute($routeIds);
    $routeItemCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}
$dispatchPhotoCounts = [];
if (!empty($routes)) {
    try {
        $routeIds = array_column($routes, 'id');
        $placeholders = implode(',', array_fill(0, count($routeIds), '?'));
        $stmt = $db->prepare("
            SELECT route_id, COUNT(*) as cnt
            FROM evidence_photos
            WHERE event_type = 'Dispatch' AND route_id IN ($placeholders)
            GROUP BY route_id
        ");
        $stmt->execute($routeIds);
        $dispatchPhotoCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        $dispatchPhotoCounts = [];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action', '');

    if ($action === 'dispatch_route') {
        if (!verifyCsrf(post('csrf_token', ''))) {
            setFlash('error', 'Invalid request');
            redirect('create.php?plan_id=' . $planId);
        }

        $routeId = (int) post('route_id', 0);
        if (!$routeId) {
            setFlash('error', 'ไม่พบ Route');
            redirect('create.php?plan_id=' . $planId);
        }

        $route = $routeModel->getById($routeId);
        if (!$route || (int)$route['plan_id'] !== (int)$planId) {
            setFlash('error', 'Route ไม่ถูกต้อง');
            redirect('create.php?plan_id=' . $planId);
        }

        if ($route['status'] !== 'Confirmed') {
            setFlash('error', 'Route ต้องอยู่ในสถานะ Confirmed เท่านั้น');
            redirect('create.php?plan_id=' . $planId);
        }

        $dispatchName = trim((string) post('dispatch_name', ''));
        if ($dispatchName !== '' && $dispatchName !== ($route['driver_name'] ?? '')) {
            $stmt = $db->prepare("UPDATE routes SET driver_name = ? WHERE id = ?");
            $stmt->execute([$dispatchName, $routeId]);
            $audit->log('update', 'ROUTE', $routeId, ['driver_name' => $route['driver_name'] ?? null], ['driver_name' => $dispatchName], 'Dispatch input');
        }

        for ($seq = 1; $seq <= 4; $seq++) {
            $fileKey = 'photo_' . $seq;
            if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                $result = $photoModel->upload($routeId, 'Dispatch', $seq, $_FILES[$fileKey]);
                if (empty($result['success'])) {
                    setFlash('error', $result['error'] ?? 'อัพโหลดรูปไม่สำเร็จ');
                    redirect('create.php?plan_id=' . $planId);
                }
            }
        }

        $result = $routeModel->dispatch($routeId);
        if ($result['success']) {
            setFlash('success', 'Dispatch สำเร็จ');
        } else {
            setFlash('error', $result['error']);
        }
        redirect('create.php?plan_id=' . $planId);
    }

    if ($routeCount <= 0) {
        setFlash('error', 'ต้องมี Route ก่อนจึงจะสร้าง Dispatch');
        redirect('create.php?plan_id=' . $planId);
    }

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

$pageTitle = 'สร้าง Dispatch Note - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
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

<!-- Routes for this Plan -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-signpost-2 me-2"></i>Routes ของ Plan นี้</span>
        <span class="badge bg-secondary"><?= $routeCount ?> Route</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($routes)): ?>
            <div class="p-4 text-center text-muted">
                ยังไม่มี Route สำหรับ Plan นี้ กรุณาให้ Planner จัด Route ก่อน
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Route</th>
                        <th>วันที่ออก</th>
                        <th>Job / ลูกค้า / ไซต์</th>
                        <th>รถ/ผู้ให้บริการ</th>
                        <th>รูป Dispatch</th>
                        <th>สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($routes as $r): ?>
                    <?php
                        $photoCount = (int)($dispatchPhotoCounts[$r['id']] ?? 0);
                        $canDispatch = $r['status'] === 'Confirmed';
                        $jobNumber = $jobInfo['job_number'] ?? $plan['job_number'];
                        $customerName = $jobInfo['customer_name'] ?? $plan['customer_name'];
                        $siteName = $jobInfo['site_name'] ?? '';
                        $jobDateText = '';
                        if (!empty($jobInfo['plan_start_date']) || !empty($jobInfo['plan_end_date'])) {
                            $jobDateText = trim(
                                (isset($jobInfo['plan_start_date']) ? formatDate($jobInfo['plan_start_date']) : '') .
                                (isset($jobInfo['plan_end_date']) ? ' - ' . formatDate($jobInfo['plan_end_date']) : '')
                            );
                        }
                    ?>
                    <tr>
                        <td><strong><?= e($r['route_number']) ?></strong></td>
                        <td><?= formatDate($r['route_date']) ?></td>
                        <td>
                            <div><?= e($jobNumber) ?></div>
                            <small class="text-muted"><?= e($customerName) ?></small>
                            <?php if ($jobDateText !== ''): ?>
                                <div class="text-muted small"><?= e($jobDateText) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($siteName)): ?>
                                <div class="text-muted small">ไซต์: <?= e($siteName) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $r['vehicle_serial'] ? e($r['vehicle_serial']) : '-' ?>
                            <?php if (!empty($r['driver_name'])): ?>
                                <div class="text-muted small"><?= e($r['driver_name']) ?></div>
                            <?php endif; ?>
                            <?php if ($r['supplier_name']): ?>
                                <div class="text-muted small"><?= e($r['supplier_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark"><?= $photoCount ?>/4</span>
                        </td>
                        <td>
                            <span class="badge bg-<?= match($r['status']) {
                                'Draft' => 'secondary',
                                'Confirmed' => 'info',
                                'Dispatched' => 'primary',
                                'InProgress' => 'warning text-dark',
                                'Returned' => 'warning text-dark',
                                'WHReceived' => 'success',
                                'Cancelled' => 'danger',
                                default => 'secondary'
                            } ?>"><?= e($r['status']) ?></span>
                        </td>
                        <td class="text-end">
                            <div class="d-flex gap-2 justify-content-end">
                                <button type="button"
                                        class="btn btn-sm btn-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#dispatchModal"
                                        data-route-id="<?= (int)$r['id'] ?>"
                                        data-route-number="<?= e($r['route_number']) ?>"
                                        <?= $canDispatch ? '' : 'disabled' ?>>
                                    Dispatch
                                </button>
                                <a href="<?= BASE_URL ?>/modules/logistics/routes/view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    เปิด Route
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Dispatch Modal -->
<div class="modal fade" id="dispatchModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="dispatch_route">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="route_id" id="dispatchRouteId">
                <div class="modal-header">
                    <h5 class="modal-title">ปล่อยรถ (Dispatch)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2 text-muted">Route: <strong id="dispatchRouteNumber">-</strong></div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อผู้ปล่อยรถ/คนขับ</label>
                        <input type="text" class="form-control" name="dispatch_name" placeholder="ระบุชื่อ">
                    </div>
                    <div class="mb-2"><strong>รูป Dispatch (4 รูป)</strong></div>
                    <div class="row g-2">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                        <div class="col-6">
                            <label class="form-label small">รูปที่ <?= $i ?></label>
                            <input type="file" class="form-control form-control-sm" name="photo_<?= $i ?>" accept="image/*">
                        </div>
                        <?php endfor; ?>
                    </div>
                    <small class="text-muted d-block mt-2">ต้องอัปโหลดให้ครบ 4 รูปก่อนปล่อยรถ</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">ยืนยัน Dispatch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manpower from Plan -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people me-2"></i>บุคลากรตามแผน</span>
        <span class="badge bg-secondary"><?= count($peopleAssignments) ?> คน</span>
    </div>
    <div class="card-body">
        <?php if (empty($peopleAssignments)): ?>
            <div class="text-muted">ไม่มีบุคลากรใน Plan นี้</div>
        <?php else: ?>
            <div class="row g-2">
                <?php foreach ($peopleAssignments as $p): ?>
                <div class="col-6 col-md-3">
                    <div class="p-2 border rounded bg-light h-100">
                        <div class="fw-semibold"><?= e($p['people_code']) ?></div>
                        <div><?= e($p['people_name']) ?></div>
                        <?php if (!empty($p['position'])): ?>
                        <div class="text-muted small"><?= e($p['position']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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

const dispatchModal = document.getElementById('dispatchModal');
if (dispatchModal) {
    dispatchModal.addEventListener('show.bs.modal', function(event) {
        const btn = event.relatedTarget;
        if (!btn) return;
        const routeId = btn.getAttribute('data-route-id');
        const routeNumber = btn.getAttribute('data-route-number');
        const idInput = dispatchModal.querySelector('#dispatchRouteId');
        const numberEl = dispatchModal.querySelector('#dispatchRouteNumber');
        if (idInput) idInput.value = routeId || '';
        if (numberEl) numberEl.textContent = routeNumber || '-';
        dispatchModal.querySelectorAll('input[type="file"]').forEach(input => {
            input.value = '';
        });
        const nameInput = dispatchModal.querySelector('input[name="dispatch_name"]');
        if (nameInput) nameInput.value = '';
    });
}
</script>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
