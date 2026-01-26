<?php
/**
 * View Dispatch Note
 * 4ERP - Phase 5
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/Dispatch.php';
require_once __DIR__ . '/../../../core/Plan.php';

$auth = new Auth();
$auth->requireAuth();

$dispatchModel = new Dispatch();
$planModel = new Plan();
$db = getDB();

$id = (int) get('id', 0);
if (!$id) {
    setFlash('error', 'ไม่พบ Dispatch Note');
    redirect('index.php');
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action', '');
    
    switch ($action) {
        case 'confirm':
            $result = $dispatchModel->confirm($id);
            if ($result['success']) {
                setFlash('success', 'ยืนยันการจัดส่งสำเร็จ');
            } else {
                setFlash('error', $result['error']);
            }
            redirect('view.php?id=' . $id);
            break;
            
        case 'deliver':
            $result = $dispatchModel->markDelivered($id);
            if ($result['success']) {
                setFlash('success', 'ทำเครื่องหมายว่าส่งถึงแล้ว');
            } else {
                setFlash('error', $result['error']);
            }
            redirect('view.php?id=' . $id);
            break;
            
        case 'cancel':
            $reason = post('cancel_reason', '');
            $result = $dispatchModel->cancel($id, $reason);
            if ($result['success']) {
                setFlash('success', 'ยกเลิก Dispatch Note สำเร็จ');
            } else {
                setFlash('error', $result['error']);
            }
            redirect('view.php?id=' . $id);
            break;
            
        case 'add_item':
            $serialId = (int) post('serial_id', 0);
            $condition = post('condition_out', 'Good');
            if ($serialId) {
                $result = $dispatchModel->addItem($id, $serialId, $condition);
                if ($result['success']) {
                    setFlash('success', 'เพิ่ม Serial สำเร็จ');
                } else {
                    setFlash('error', $result['error']);
                }
            }
            redirect('view.php?id=' . $id);
            break;
            
        case 'remove_item':
            $itemId = (int) post('item_id', 0);
            if ($itemId) {
                $result = $dispatchModel->removeItem($itemId);
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

$dispatch = $dispatchModel->getById($id);
if (!$dispatch) {
    setFlash('error', 'ไม่พบ Dispatch Note');
    redirect('index.php');
}

$items = $dispatchModel->getItems($id);

// Get plan assignments for adding more serials (if draft)
$planAssignments = $planModel->getAssignments($dispatch['plan_id']);
$assignedSerialIdsInDispatch = array_column($items, 'serial_id');
$availableSerials = array_filter($planAssignments, function($a) use ($assignedSerialIdsInDispatch) {
    return $a['serial_id'] && !in_array($a['serial_id'], $assignedSerialIdsInDispatch);
});

$pageTitle = 'Dispatch #' . $dispatch['do_number'] . ' - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-truck me-2"></i>Dispatch #<?= e($dispatch['do_number']) ?>
                <span class="badge bg-<?= match($dispatch['status']) {
                    'Draft' => 'secondary',
                    'Dispatched' => 'primary',
                    'Delivered' => 'success',
                    'Cancelled' => 'danger',
                    default => 'secondary'
                } ?>"><?= match($dispatch['status']) {
                    'Draft' => 'แบบร่าง',
                    'Dispatched' => 'จัดส่งแล้ว',
                    'Delivered' => 'ถึงแล้ว',
                    'Cancelled' => 'ยกเลิก',
                    default => $dispatch['status']
                } ?></span>
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Dispatch</a></li>
                    <li class="breadcrumb-item active"><?= e($dispatch['do_number']) ?></li>
                </ol>
            </nav>
        </div>
        <div>
            <?php if ($dispatch['status'] === 'Draft'): ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันการจัดส่ง?')">
                <input type="hidden" name="action" value="confirm">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-truck me-1"></i>จัดส่ง
                </button>
            </form>
            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                <i class="bi bi-x-circle me-1"></i>ยกเลิก
            </button>
            <?php endif; ?>
            
            <?php if ($dispatch['status'] === 'Dispatched'): ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันว่าสินค้าถึงแล้ว?')">
                <input type="hidden" name="action" value="deliver">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle me-1"></i>ถึงแล้ว
                </button>
            </form>
            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                <i class="bi bi-x-circle me-1"></i>ยกเลิก
            </button>
            <?php endif; ?>
            
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
        </div>
    </div>
</div>

<div class="row">
    <!-- Dispatch Info -->
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>ข้อมูล Dispatch
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="35%">DO Number:</th>
                        <td><?= e($dispatch['do_number']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่จัดส่ง:</th>
                        <td><?= formatDate($dispatch['dispatch_date']) ?></td>
                    </tr>
                    <tr>
                        <th>สถานะ:</th>
                        <td>
                            <span class="badge bg-<?= match($dispatch['status']) {
                                'Draft' => 'secondary',
                                'Dispatched' => 'primary',
                                'Delivered' => 'success',
                                'Cancelled' => 'danger',
                                default => 'secondary'
                            } ?>"><?= $dispatch['status'] ?></span>
                        </td>
                    </tr>
                    <?php if ($dispatch['vehicle_info']): ?>
                    <tr>
                        <th>รถ/ยานพาหนะ:</th>
                        <td><?= e($dispatch['vehicle_info']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($dispatch['driver_name']): ?>
                    <tr>
                        <th>คนขับ:</th>
                        <td><?= e($dispatch['driver_name']) ?> <?= $dispatch['driver_phone'] ? '(' . e($dispatch['driver_phone']) . ')' : '' ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($dispatch['dispatched_at']): ?>
                    <tr>
                        <th>จัดส่งเมื่อ:</th>
                        <td><?= formatDate($dispatch['dispatched_at'], 'd/m/Y H:i') ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($dispatch['delivered_at']): ?>
                    <tr>
                        <th>ถึงเมื่อ:</th>
                        <td><?= formatDate($dispatch['delivered_at'], 'd/m/Y H:i') ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($dispatch['notes']): ?>
                    <tr>
                        <th>หมายเหตุ:</th>
                        <td><?= nl2br(e($dispatch['notes'])) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>สร้างโดย:</th>
                        <td><?= e($dispatch['created_by_name']) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Plan & Job Info -->
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-briefcase me-2"></i>Plan & Job
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="35%">Plan Number:</th>
                        <td>
                            <a href="../../planning/view.php?id=<?= $dispatch['plan_id'] ?>">
                                <?= e($dispatch['plan_number']) ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <th>Job Number:</th>
                        <td>
                            <a href="../../jobs/view.php?id=<?= $dispatch['job_id'] ?>">
                                <?= e($dispatch['job_number']) ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <th>ลูกค้า:</th>
                        <td><?= e($dispatch['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <th>รายละเอียด:</th>
                        <td><?= e($dispatch['scope_short']) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Dispatch Items -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-upc-scan me-2"></i>รายการ Serial (<?= count($items) ?>)</span>
        <?php if ($dispatch['status'] === 'Draft' && !empty($availableSerials)): ?>
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
            <i class="bi bi-plus"></i> เพิ่ม Serial
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Serial Number</th>
                        <th>Item</th>
                        <th>สภาพขาออก</th>
                        <th>สถานะ Serial</th>
                        <?php if ($dispatch['status'] === 'Draft'): ?>
                        <th></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="<?= $dispatch['status'] === 'Draft' ? 5 : 4 ?>" class="text-center text-muted py-4">ยังไม่มีรายการ</td></tr>
                    <?php else: ?>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><strong><?= e($item['serial_number']) ?></strong></td>
                        <td><?= e($item['item_code']) ?> - <?= e($item['item_name']) ?></td>
                        <td>
                            <span class="badge bg-<?= match($item['condition_out']) {
                                'Good' => 'success',
                                'Fair' => 'warning text-dark',
                                'Damaged' => 'danger',
                                default => 'secondary'
                            } ?>"><?= match($item['condition_out']) {
                                'Good' => 'ดี',
                                'Fair' => 'พอใช้',
                                'Damaged' => 'เสียหาย',
                                default => $item['condition_out']
                            } ?></span>
                        </td>
                        <td>
                            <span class="badge bg-<?= match($item['serial_status']) {
                                'Available' => 'success',
                                'Allocated' => 'info',
                                'Dispatched' => 'primary',
                                'InUse' => 'warning text-dark',
                                default => 'secondary'
                            } ?>"><?= e($item['serial_status']) ?></span>
                        </td>
                        <?php if ($dispatch['status'] === 'Draft'): ?>
                        <td>
                            <form method="POST" class="d-inline" onsubmit="return confirm('ลบรายการนี้?')">
                                <input type="hidden" name="action" value="remove_item">
                                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
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
                    <h5 class="modal-title">ยกเลิก Dispatch Note</h5>
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

<!-- Add Item Modal -->
<?php if ($dispatch['status'] === 'Draft' && !empty($availableSerials)): ?>
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_item">
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
                            <option value="<?= $s['serial_id'] ?>"><?= e($s['item_code']) ?> - <?= e($s['serial_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">สภาพขาออก</label>
                        <select class="form-select" name="condition_out">
                            <option value="Good">ดี</option>
                            <option value="Fair">พอใช้</option>
                            <option value="Damaged">เสียหาย</option>
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
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
