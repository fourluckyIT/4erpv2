<?php
/**
 * View Plan
 * 4ERP - Phase 5
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/Plan.php';

$auth = new Auth();
$auth->requireAuth();

$planModel = new Plan();
$db = getDB();

$id = (int) get('id', 0);
if (!$id) {
    setFlash('error', 'ไม่พบ Plan');
    redirect('index.php');
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action', '');
    
    switch ($action) {
        case 'confirm':
            $result = $planModel->confirm($id);
            if ($result['success']) {
                setFlash('success', 'ยืนยัน Plan สำเร็จ');
            } else {
                setFlash('error', $result['error']);
            }
            redirect('view.php?id=' . $id);
            break;
            
        case 'cancel':
            $reason = post('cancel_reason', '');
            $result = $planModel->cancel($id, $reason);
            if ($result['success']) {
                setFlash('success', 'ยกเลิก Plan สำเร็จ');
            } else {
                setFlash('error', $result['error']);
            }
            redirect('view.php?id=' . $id);
            break;
            
        case 'add_serial':
            $serialId = (int) post('serial_id', 0);
            if ($serialId) {
                $result = $planModel->addSerial($id, $serialId);
                if ($result['success']) {
                    setFlash('success', 'เพิ่ม Serial สำเร็จ');
                } else {
                    setFlash('error', $result['error']);
                }
            }
            redirect('view.php?id=' . $id);
            break;
            
        case 'add_people':
            $peopleId = (int) post('people_id', 0);
            if ($peopleId) {
                $result = $planModel->addPeople($id, $peopleId);
                if ($result['success']) {
                    setFlash('success', 'เพิ่มบุคลากรสำเร็จ');
                } else {
                    setFlash('error', $result['error']);
                }
            }
            redirect('view.php?id=' . $id);
            break;
            
        case 'remove_assignment':
            $assignmentId = (int) post('assignment_id', 0);
            if ($assignmentId) {
                $result = $planModel->removeAssignment($assignmentId);
                if ($result['success']) {
                    setFlash('success', 'ลบรายการสำเร็จ');
                } else {
                    setFlash('error', $result['error']);
                }
            }
            redirect('view.php?id=' . $id);
            break;
    }
}

$plan = $planModel->getById($id);
if (!$plan) {
    setFlash('error', 'ไม่พบ Plan');
    redirect('index.php');
}

$assignments = $planModel->getAssignments($id);

// Get available serials for adding
$availableSerials = $db->query("
    SELECT s.*, i.name as item_name, i.code as item_code
    FROM serials s
    JOIN items i ON s.item_id = i.id
    WHERE s.status = 'Available'
    ORDER BY i.name, s.serial_number
")->fetchAll();

// Get available people for adding
$availablePeople = $db->query("SELECT * FROM people WHERE is_active = 1 ORDER BY full_name")->fetchAll();

// Filter out already assigned
$assignedSerialIds = array_column(array_filter($assignments, fn($a) => $a['serial_id']), 'serial_id');
$assignedPeopleIds = array_column(array_filter($assignments, fn($a) => $a['people_id']), 'people_id');

$pageTitle = 'Plan #' . $plan['plan_number'] . ' - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-calendar-check me-2"></i>Plan #<?= e($plan['plan_number']) ?>
                <span class="badge bg-<?= match($plan['status']) {
                    'Draft' => 'secondary',
                    'Confirmed' => 'success',
                    'Cancelled' => 'danger',
                    default => 'secondary'
                } ?>"><?= match($plan['status']) {
                    'Draft' => 'แบบร่าง',
                    'Confirmed' => 'ยืนยันแล้ว',
                    'Cancelled' => 'ยกเลิก',
                    default => $plan['status']
                } ?></span>
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Planning</a></li>
                    <li class="breadcrumb-item active"><?= e($plan['plan_number']) ?></li>
                </ol>
            </nav>
        </div>
        <div>
            <?php if ($plan['status'] === 'Draft'): ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยัน Plan นี้?')">
                <input type="hidden" name="action" value="confirm">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle me-1"></i>ยืนยัน Plan
                </button>
            </form>
            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                <i class="bi bi-x-circle me-1"></i>ยกเลิก
            </button>
            <?php endif; ?>
            
            <?php if ($plan['status'] === 'Confirmed'): ?>
            <a href="../logistics/routes/create.php?plan_id=<?= $plan['id'] ?>" class="btn btn-primary">
                <i class="bi bi-signpost-2 me-1"></i>สร้าง Route
            </a>
            <?php endif; ?>
            
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
        </div>
    </div>
</div>

<div class="row">
    <!-- Plan Info -->
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>ข้อมูล Plan
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="35%">Plan Number:</th>
                        <td><?= e($plan['plan_number']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่วางแผน:</th>
                        <td><?= formatDate($plan['plan_date']) ?></td>
                    </tr>
                    <tr>
                        <th>สถานะ:</th>
                        <td>
                            <span class="badge bg-<?= match($plan['status']) {
                                'Draft' => 'secondary',
                                'Confirmed' => 'success',
                                'Cancelled' => 'danger',
                                default => 'secondary'
                            } ?>"><?= $plan['status'] ?></span>
                        </td>
                    </tr>
                    <?php if ($plan['confirmed_at']): ?>
                    <tr>
                        <th>ยืนยันเมื่อ:</th>
                        <td><?= formatDate($plan['confirmed_at'], 'd/m/Y H:i') ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($plan['notes']): ?>
                    <tr>
                        <th>หมายเหตุ:</th>
                        <td><?= nl2br(e($plan['notes'])) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>สร้างโดย:</th>
                        <td><?= e($plan['created_by_name']) ?></td>
                    </tr>
                    <tr>
                        <th>สร้างเมื่อ:</th>
                        <td><?= formatDate($plan['created_at'], 'd/m/Y H:i') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Job Info -->
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-briefcase me-2"></i>ข้อมูล Job
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="35%">Job Number:</th>
                        <td>
                            <a href="../jobs/view.php?id=<?= $plan['job_id'] ?>">
                                <?= e($plan['job_number']) ?>
                            </a>
                        </td>
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

<!-- Assignments -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-check me-2"></i>รายการจัดสรร (<?= count($assignments) ?>)</span>
        <?php if ($plan['status'] === 'Draft'): ?>
        <div>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addSerialModal">
                <i class="bi bi-plus"></i> Serial
            </button>
            <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#addPeopleModal">
                <i class="bi bi-plus"></i> บุคลากร
            </button>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ประเภท</th>
                        <th>รหัส</th>
                        <th>รายละเอียด</th>
                        <th>สถานะ</th>
                        <?php if ($plan['status'] === 'Draft'): ?>
                        <th></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assignments)): ?>
                    <tr><td colspan="<?= $plan['status'] === 'Draft' ? 5 : 4 ?>" class="text-center text-muted py-4">ยังไม่มีรายการจัดสรร</td></tr>
                    <?php else: ?>
                    <?php foreach ($assignments as $a): ?>
                    <tr>
                        <td>
                            <?php if ($a['serial_id']): ?>
                            <span class="badge bg-info">Serial</span>
                            <?php elseif ($a['item_id'] && $a['assignment_type'] === 'Consumable'): ?>
                            <span class="badge bg-secondary">Consumable</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">บุคลากร</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($a['serial_id']): ?>
                            <?= e($a['item_code']) ?>
                            <?php elseif ($a['item_id'] && $a['assignment_type'] === 'Consumable'): ?>
                            <?= e($a['item_code']) ?>
                            <?php else: ?>
                            <?= e($a['people_code']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($a['serial_id']): ?>
                            <strong><?= e($a['serial_number']) ?></strong>
                            <small class="text-muted">(<?= e($a['item_name']) ?>)</small>
                            <?php elseif ($a['item_id'] && $a['assignment_type'] === 'Consumable'): ?>
                            <strong><?= e($a['item_name']) ?></strong>
                            <span class="badge bg-primary ms-2"><?= (int)$a['quantity'] ?> <?= e($a['consumable_unit'] ?? '') ?></span>
                            <?php else: ?>
                            <strong><?= e($a['people_name']) ?></strong>
                            <?php if ($a['position']): ?>
                            <small class="text-muted">(<?= e($a['position']) ?>)</small>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($a['serial_id']): ?>
                            <span class="badge bg-<?= $a['serial_status'] === 'Available' ? 'success' : 'secondary' ?>"><?= e($a['serial_status']) ?></span>
                            <?php elseif ($a['item_id'] && $a['assignment_type'] === 'Consumable'): ?>
                            <span class="badge bg-success">พร้อมใช้</span>
                            <?php else: ?>
                            <span class="badge bg-success">Active</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($plan['status'] === 'Draft'): ?>
                        <td>
                            <form method="POST" class="d-inline" onsubmit="return confirm('ลบรายการนี้?')">
                                <input type="hidden" name="action" value="remove_assignment">
                                <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Cancel Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="cancel">
                <div class="modal-header">
                    <h5 class="modal-title">ยกเลิก Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">เหตุผลในการยกเลิก <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="cancel_reason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                    <button type="submit" class="btn btn-danger">ยืนยันยกเลิก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Serial Modal -->
<div class="modal fade" id="addSerialModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_serial">
                <div class="modal-header">
                    <h5 class="modal-title">เพิ่ม Serial</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">เลือก Serial <span class="text-danger">*</span></label>
                        <select class="form-select" name="serial_id" required>
                            <option value="">-- เลือก --</option>
                            <?php foreach ($availableSerials as $s): ?>
                            <?php if (!in_array($s['id'], $assignedSerialIds)): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['item_code']) ?> - <?= e($s['serial_number']) ?> (<?= e($s['item_name']) ?>)</option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                    <button type="submit" class="btn btn-primary">เพิ่ม</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add People Modal -->
<div class="modal fade" id="addPeopleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_people">
                <div class="modal-header">
                    <h5 class="modal-title">เพิ่มบุคลากร</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">เลือกบุคลากร <span class="text-danger">*</span></label>
                        <select class="form-select" name="people_id" required>
                            <option value="">-- เลือก --</option>
                            <?php foreach ($availablePeople as $p): ?>
                            <?php if (!in_array($p['id'], $assignedPeopleIds)): ?>
                            <option value="<?= $p['id'] ?>"><?= e($p['code']) ?> - <?= e($p['full_name']) ?><?= $p['position'] ? ' (' . e($p['position']) . ')' : '' ?></option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                    <button type="submit" class="btn btn-primary">เพิ่ม</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
