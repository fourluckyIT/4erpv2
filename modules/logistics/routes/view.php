<?php
/**
 * View Route
 * 4ERP - Phase 5 v2
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/Route.php';
require_once __DIR__ . '/../../../core/EvidencePhoto.php';
require_once __DIR__ . '/../../../core/RouteReminder.php';

$auth = new Auth();
$auth->requireAuth();
$rbac = new RBAC();

$routeModel = new Route();
$photoModel = new EvidencePhoto();

$id = (int) get('id', 0);
if (!$id) {
    setFlash('error', 'ไม่พบ Route');
    redirect('index.php');
}

$route = $routeModel->getById($id);
if (!$route) {
    setFlash('error', 'ไม่พบ Route');
    redirect('index.php');
}

$items = $routeModel->getItems($id);
$photoStatus = $photoModel->getCompletionStatus($id);
$allPhotos = $photoModel->getAllPhotos($id);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action', '');
    
    switch ($action) {
        case 'confirm':
            $result = $routeModel->confirm($id);
            break;
            
        case 'dispatch':
            $result = $routeModel->dispatch($id);
            break;
            
        case 'start_progress':
            $result = $routeModel->startProgress($id);
            break;
            
        case 'wh_receive':
            $result = $routeModel->whReceive($id);
            break;
            
        case 'cancel':
            $reason = post('cancel_reason', '');
            $result = $routeModel->cancel($id, $reason);
            break;
            
        case 'snooze_reminder':
            if (!$rbac->hasAnyRole(['WH', 'ADM', 'MGR'])) {
                $result = ['success' => false, 'error' => 'คุณไม่มีสิทธิ์จัดการแจ้งเตือน'];
                break;
            }
            $option = post('snooze_option', '30m');
            $reminder = new RouteReminder();
            $result = $reminder->snooze($id, $option, $_SESSION['user_id']);
            break;
            
        case 'upload_photo':
            $eventType = post('event_type', '');
            $photoSeq = (int) post('photo_seq', 0);
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $result = $photoModel->upload($id, $eventType, $photoSeq, $_FILES['photo']);
            } else {
                $result = ['success' => false, 'error' => 'กรุณาเลือกไฟล์รูปภาพ'];
            }
            break;
            
        default:
            $result = ['success' => false, 'error' => 'Unknown action'];
    }
    
    if ($result['success']) {
        setFlash('success', 'ดำเนินการสำเร็จ');
    } else {
        setFlash('error', $result['error']);
    }
    
    redirect('view.php?id=' . $id);
}

// Status badge helper
function getStatusBadge(string $status): string {
    $class = match($status) {
        'Draft' => 'secondary',
        'Confirmed' => 'info',
        'Dispatched' => 'primary',
        'InProgress' => 'warning',
        'Returned' => 'info',
        'WHReceived' => 'success',
        'Cancelled' => 'danger',
        default => 'secondary'
    };
    $label = match($status) {
        'Draft' => 'แบบร่าง',
        'Confirmed' => 'ยืนยันแล้ว',
        'Dispatched' => 'ส่งของแล้ว',
        'InProgress' => 'กำลังดำเนินการ',
        'Returned' => 'รับคืนแล้ว',
        'WHReceived' => 'คลังรับแล้ว',
        'Cancelled' => 'ยกเลิก',
        default => $status
    };
    return "<span class=\"badge bg-{$class}\">{$label}</span>";
}

$pageTitle = 'Route: ' . $route['route_number'] . ' - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-signpost-2 me-2"></i><?= e($route['route_number']) ?>
                <?= getStatusBadge($route['status']) ?>
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Routes</a></li>
                    <li class="breadcrumb-item active"><?= e($route['route_number']) ?></li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
            <?php if ($route['status'] === 'Draft'): ?>
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="confirm">
                <button type="submit" class="btn btn-info">
                    <i class="bi bi-check-circle me-1"></i>Confirm
                </button>
            </form>
            <?php endif; ?>
            
            <?php if ($route['status'] === 'Confirmed'): ?>
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="dispatch">
                <button type="submit" class="btn btn-primary" 
                        <?= !$photoStatus['Dispatch']['complete'] ? 'disabled' : '' ?>>
                    <i class="bi bi-truck me-1"></i>Dispatch
                    <?php if (!$photoStatus['Dispatch']['complete']): ?>
                    <small>(ต้องอัพโหลดรูป)</small>
                    <?php endif; ?>
                </button>
            </form>
            <?php endif; ?>
            
            <?php if ($route['status'] === 'Dispatched'): ?>
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="start_progress">
                <button type="submit" class="btn btn-warning"
                        <?= !$photoStatus['Receive']['complete'] ? 'disabled' : '' ?>>
                    <i class="bi bi-play-circle me-1"></i>เริ่มงาน
                    <?php if (!$photoStatus['Receive']['complete']): ?>
                    <small>(ต้องอัพโหลดรูป)</small>
                    <?php endif; ?>
                </button>
            </form>
            <?php endif; ?>
            
            <?php if (in_array($route['status'], ['Dispatched', 'InProgress'])): ?>
            <a href="return.php?id=<?= $id ?>" class="btn btn-info">
                <i class="bi bi-box-arrow-in-left me-1"></i>รับคืน (WH)
            </a>
            <?php endif; ?>
            
            <?php if ($route['status'] === 'Returned'): ?>
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="wh_receive">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-box-seam me-1"></i>คลังรับ
                </button>
            </form>
            <?php endif; ?>
            
            <?php if (in_array($route['status'], ['Draft', 'Confirmed', 'Dispatched'])): ?>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                <i class="bi bi-x-circle me-1"></i>ยกเลิก
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($route['status'] === 'Confirmed' && $rbac->hasAnyRole(['WH', 'ADM', 'MGR'])): ?>
<div class="card mb-4 border-warning">
    <div class="card-header bg-warning text-dark">
        <i class="bi bi-bell-slash me-2"></i>WH แจ้งเตือนปล่อยรถ (Snooze)
    </div>
    <div class="card-body">
        <form method="POST" class="row g-2 align-items-center">
            <input type="hidden" name="action" value="snooze_reminder">
            <div class="col-auto">
                <select name="snooze_option" class="form-select form-select-sm">
                    <option value="30m">30 นาที</option>
                    <option value="2h">2 ชั่วโมง</option>
                    <option value="4h">4 ชั่วโมง</option>
                    <option value="8h">8 ชั่วโมง</option>
                    <option value="next_day">วันถัดไป 07:30</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-outline-dark btn-sm">
                    ปิดแจ้งเตือนชั่วคราว
                </button>
            </div>
        </form>
        <div class="small text-muted mt-2">
            แจ้งเตือนจะกลับมาเวลา 07:30 ตามรอบปกติ
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <!-- Route Info -->
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>ข้อมูล Route
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="35%">Route Number:</th>
                        <td><strong><?= e($route['route_number']) ?></strong></td>
                    </tr>
                    <tr>
                        <th>Plan:</th>
                        <td><a href="../../planning/view.php?id=<?= $route['plan_id'] ?>"><?= e($route['plan_number']) ?></a></td>
                    </tr>
                    <tr>
                        <th>Job:</th>
                        <td><a href="../../jobs/view.php?id=<?= $route['job_id'] ?>"><?= e($route['job_number']) ?></a></td>
                    </tr>
                    <tr>
                        <th>ลูกค้า:</th>
                        <td><?= e($route['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่:</th>
                        <td><?= formatDate($route['route_date']) ?></td>
                    </tr>
                    <tr>
                        <th>ปลายทาง:</th>
                        <td><?= e($route['destination'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>รถ:</th>
                        <td><?= $route['vehicle_serial'] ? e($route['vehicle_serial'] . ' - ' . $route['vehicle_name']) : '-' ?></td>
                    </tr>
                    <tr>
                        <th>Supplier:</th>
                        <td><?= e($route['supplier_name'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>คนขับ:</th>
                        <td><?= e($route['driver_name'] ?? '-') ?> <?= $route['driver_phone'] ? '(' . e($route['driver_phone']) . ')' : '' ?></td>
                    </tr>
                    <?php if (!empty($route['notes'])): ?>
                    <tr>
                        <th>หมายเหตุ:</th>
                        <td><?= nl2br(e($route['notes'])) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        
        <!-- Route Items -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-box me-2"></i>รายการใน Route (<?= count($items) ?>)
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>ประเภท</th>
                            <th>รายการ</th>
                            <th>สภาพออก</th>
                            <th>สภาพเข้า</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">ไม่มีรายการ</td></tr>
                        <?php else: ?>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <span class="badge bg-<?= match($item['item_type']) {
                                    'Manpower' => 'success',
                                    'Device' => 'primary',
                                    'Equipment' => 'info',
                                    'Vehicle' => 'warning',
                                    'Consumable' => 'secondary',
                                    default => 'secondary'
                                } ?>"><?= e($item['item_type']) ?></span>
                            </td>
                            <td>
                                <?php if (!empty($item['serial_id'])): ?>
                                <strong><?= e($item['serial_number']) ?></strong><br>
                                <small class="text-muted"><?= e($item['item_code']) ?> - <?= e($item['item_name']) ?></small>
                                <?php elseif (!empty($item['people_id'])): ?>
                                <strong><?= e($item['people_code']) ?></strong> - <?= e($item['people_name']) ?>
                                <?php if (!empty($item['position'])): ?>
                                <small class="text-muted">(<?= e($item['position']) ?>)</small>
                                <?php endif; ?>
                                <?php else: ?>
                                <strong><?= e($item['item_code'] ?? '') ?></strong> - <?= e($item['item_name'] ?? '') ?>
                                <?php if (!empty($item['quantity'])): ?>
                                <small class="text-muted">(จำนวน: <?= e($item['quantity']) ?>)</small>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($item['condition_out'])): ?>
                                <span class="badge bg-<?= match($item['condition_out']) {
                                    'Good' => 'success',
                                    'Fair' => 'warning',
                                    'Damaged' => 'danger',
                                    default => 'secondary'
                                } ?>"><?= e($item['condition_out']) ?></span>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($item['condition_in'])): ?>
                                <span class="badge bg-<?= match($item['condition_in']) {
                                    'Good' => 'success',
                                    'Fair' => 'warning',
                                    'Damaged' => 'danger',
                                    'Lost' => 'dark',
                                    default => 'secondary'
                                } ?>"><?= e($item['condition_in']) ?></span>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Photos -->
    <div class="col-md-6">
        <?php 
        $eventTypes = [
            'Dispatch' => ['icon' => 'truck', 'label' => 'รูปตอนส่งออก', 'color' => 'primary'],
            'Receive' => ['icon' => 'box-arrow-in-down', 'label' => 'รูปตอนรับของ', 'color' => 'info'],
            'Return' => ['icon' => 'box-arrow-in-left', 'label' => 'รูปตอนรับคืน', 'color' => 'warning'],
            'POSCheck' => ['icon' => 'clipboard-check', 'label' => 'รูป POS Check', 'color' => 'success']
        ];
        
        foreach ($eventTypes as $eventType => $config): 
            $photos = $allPhotos[$eventType] ?? [];
            $status = $photoStatus[$eventType];
        ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-<?= $config['icon'] ?> me-2"></i><?= $config['label'] ?>
                    <span class="badge bg-<?= $status['complete'] ? 'success' : 'secondary' ?>">
                        <?= $status['count'] ?>/<?= $status['required'] ?>
                    </span>
                </span>
                <?php if ($status['complete']): ?>
                <span class="badge bg-success"><i class="bi bi-check"></i> ครบแล้ว</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <?php for ($seq = 1; $seq <= 4; $seq++): 
                        $photo = null;
                        foreach ($photos as $p) {
                            if ($p['photo_seq'] == $seq) {
                                $photo = $p;
                                break;
                            }
                        }
                    ?>
                    <div class="col-3">
                        <?php if ($photo): ?>
                        <div class="position-relative">
                            <img src="<?= BASE_URL . '/' . $photo['file_path'] ?>" 
                                 class="img-thumbnail" style="width: 100%; height: 80px; object-fit: cover;">
                            <span class="position-absolute top-0 start-0 badge bg-dark"><?= $seq ?></span>
                        </div>
                        <?php else: ?>
                        <div class="border rounded d-flex align-items-center justify-content-center" 
                             style="height: 80px; background: #f8f9fa; cursor: pointer;"
                             onclick="openUploadModal('<?= $eventType ?>', <?= $seq ?>)">
                            <div class="text-center text-muted">
                                <i class="bi bi-camera fs-4"></i><br>
                                <small>รูป <?= $seq ?></small>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Upload Photo Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_photo">
                <input type="hidden" name="event_type" id="uploadEventType">
                <input type="hidden" name="photo_seq" id="uploadPhotoSeq">
                <div class="modal-header">
                    <h5 class="modal-title">อัพโหลดรูปภาพ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">เลือกไฟล์รูปภาพ</label>
                        <input type="file" class="form-control" name="photo" accept="image/*" required>
                        <small class="text-muted">รองรับ JPEG, PNG, WebP (ไม่เกิน 10MB)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">อัพโหลด</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="cancel">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">ยกเลิก Route</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
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

<script>
function openUploadModal(eventType, seq) {
    document.getElementById('uploadEventType').value = eventType;
    document.getElementById('uploadPhotoSeq').value = seq;
    new bootstrap.Modal(document.getElementById('uploadModal')).show();
}
</script>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
