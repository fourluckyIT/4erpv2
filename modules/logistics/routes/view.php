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
$db = getDB();

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
$itemsCount = count($items);
$photoRequiredTotal = 0;
$photoUploadedTotal = 0;
foreach ($photoStatus as $status) {
    $photoRequiredTotal += (int) ($status['required'] ?? 0);
    $photoUploadedTotal += (int) ($status['count'] ?? 0);
}
$driverLabel = trim(($route['driver_name'] ?? '') . ' ' . (!empty($route['driver_phone']) ? '(' . $route['driver_phone'] . ')' : ''));
$driverLabel = $driverLabel !== '' ? $driverLabel : '-';
$currentUserName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
$routeType = $route['route_type'] ?? 'Outbound';
$isReturn = $routeType === 'Return';
$canConfirm = $route['status'] === 'Draft' && $rbac->hasAnyRole(['PLN', 'WH']);
$canDispatch = !$isReturn && $route['status'] === 'Confirmed' && $rbac->hasAnyRole(['WH']);
$canReceive = !$isReturn && $route['status'] === 'Dispatched' && $rbac->hasAnyRole(['ADM', 'PLN', 'WH', 'MGR']);
$canReturn = $isReturn && $route['status'] === 'Confirmed' && $rbac->hasAnyRole(['WH', 'ADM', 'MGR']);
$canReset = $route['status'] === 'Confirmed' && $rbac->hasAnyRole(['PLN', 'ADM', 'MGR']);
$canCancel = in_array($route['status'], ['Draft', 'Confirmed'], true) && $rbac->hasAnyRole(['ADM', 'MGR']);

function normalizeDateTime(?string $value): string {
    if ($value === null || trim($value) === '') {
        return date('Y-m-d H:i:s');
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}

function uploadEventPhotos(EvidencePhoto $photoModel, int $routeId, string $eventType, array $files): array {
    if (empty($files) || empty($files['name'][0])) {
        $count = $photoModel->getPhotoCount($routeId, $eventType);
        if ($count < EvidencePhoto::PHOTOS_MIN_REQUIRED) {
            return ['success' => false, 'error' => "กรุณาอัพโหลดรูป {$eventType} อย่างน้อย " . EvidencePhoto::PHOTOS_MIN_REQUIRED . " รูป"];
        }
        return ['success' => true, 'count' => $count, 'uploaded' => 0];
    }

    $photoSeq = 1;
    foreach ($files['tmp_name'] as $i => $tmpName) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        if ($photoSeq > EvidencePhoto::PHOTOS_MAX) {
            break;
        }
        $file = [
            'tmp_name' => $tmpName,
            'name' => $files['name'][$i],
            'size' => $files['size'][$i],
            'type' => $files['type'][$i],
            'error' => $files['error'][$i]
        ];
        $result = $photoModel->upload($routeId, $eventType, $photoSeq, $file);
        if (empty($result['success'])) {
            return ['success' => false, 'error' => $result['error'] ?? 'อัพโหลดรูปไม่สำเร็จ'];
        }
        $photoSeq++;
    }

    $count = $photoModel->getPhotoCount($routeId, $eventType);
    if ($count < EvidencePhoto::PHOTOS_MIN_REQUIRED) {
        return ['success' => false, 'error' => "กรุณาอัพโหลดรูป {$eventType} อย่างน้อย " . EvidencePhoto::PHOTOS_MIN_REQUIRED . " รูป"];
    }
    return ['success' => true, 'count' => $count, 'uploaded' => $photoSeq - 1];
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action', '');
    
    switch ($action) {
        case 'confirm':
            if (!$canConfirm) {
                $result = ['success' => false, 'error' => 'คุณไม่มีสิทธิ์ยืนยัน Route'];
                break;
            }
            $result = $routeModel->confirm($id);
            break;

        case 'reset':
            if (!$canReset) {
                $result = ['success' => false, 'error' => 'คุณไม่มีสิทธิ์จัด Route ใหม่'];
                break;
            }
            $reason = trim((string) post('reset_reason', ''));
            if ($reason === '') {
                $result = ['success' => false, 'error' => 'กรุณาระบุเหตุผลในการจัดใหม่'];
                break;
            }
            $result = $routeModel->transitionStatus($id, 'Draft', $reason);
            break;
            
        case 'dispatch':
            if (!$canDispatch) {
                $result = ['success' => false, 'error' => 'คุณไม่มีสิทธิ์ปล่อยรถ'];
                break;
            }
            $upload = uploadEventPhotos($photoModel, $id, 'Dispatch', $_FILES['dispatch_photos'] ?? []);
            if (empty($upload['success'])) {
                $result = $upload;
                break;
            }
            $conditions = post('condition_out', []);
            $validConditions = ['Good', 'Fair', 'Damaged'];
            $audit = new AuditLog();
            foreach ($conditions as $routeItemId => $condition) {
                $routeItemId = (int) $routeItemId;
                if (!in_array($condition, $validConditions, true)) {
                    continue;
                }
                $stmt = $db->prepare("UPDATE route_items SET condition_out = ? WHERE id = ? AND route_id = ?");
                $stmt->execute([$condition, $routeItemId, $id]);
                $audit->log('update', 'ROUTE_ITEM', $routeItemId, null, ['condition_out' => $condition]);
            }
            $dispatchName = sanitize(post('dispatch_name', ''));
            $dispatchTime = normalizeDateTime(post('dispatch_time', ''));
            $result = $routeModel->dispatch($id, [
                'dispatched_by_name' => $dispatchName,
                'dispatched_at' => $dispatchTime
            ]);
            break;
            
        case 'receive':
            if (!$canReceive) {
                $result = ['success' => false, 'error' => 'คุณไม่มีสิทธิ์ยืนยันถึงหน้างาน'];
                break;
            }
            $upload = uploadEventPhotos($photoModel, $id, 'Receive', $_FILES['receive_photos'] ?? []);
            if (empty($upload['success'])) {
                $result = $upload;
                break;
            }
            $receiverName = sanitize(post('receiver_name', ''));
            $receiveNotes = sanitize(post('receive_notes', ''));
            $receiveTime = normalizeDateTime(post('receive_time', ''));
            $result = $routeModel->markReceived($id, [
                'received_by_name' => $receiverName,
                'receive_notes' => $receiveNotes,
                'received_at' => $receiveTime
            ]);
            break;

        case 'return':
            if (!$canReturn) {
                $result = ['success' => false, 'error' => 'คุณไม่มีสิทธิ์รับของกลับ'];
                break;
            }
            $upload = uploadEventPhotos($photoModel, $id, 'Return', $_FILES['return_photos'] ?? []);
            if (empty($upload['success'])) {
                $result = $upload;
                break;
            }
            $itemConditions = [];
            $consumableUsed = [];
            foreach ($items as $item) {
                if (!empty($item['serial_id'])) {
                    $itemConditions[$item['id']] = post('condition_' . $item['id'], 'Good');
                } elseif (($item['item_type'] ?? '') === 'Consumable') {
                    $consumableUsed[$item['id']] = (float) post('used_' . $item['id'], 0);
                }
            }
            $result = $routeModel->markReturned($id, $itemConditions, $consumableUsed);
            break;
            
        case 'cancel':
            if (!$canCancel) {
                $result = ['success' => false, 'error' => 'คุณไม่มีสิทธิ์ยกเลิก Route'];
                break;
            }
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
        'Received' => 'warning',
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
        'Received' => 'ถึงหน้างานแล้ว',
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

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-signpost-2" style="color: var(--primary);"></i>
            <?= e($route['route_number']) ?>
        </h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?= getStatusBadge($route['status']) ?>
            <span class="badge bg-light text-dark"><?= $routeType === 'Return' ? 'ขากลับ' : 'ขาไป' ?></span>
            <span class="text-muted">Job: <a href="../../jobs/view.php?id=<?= $route['job_id'] ?>"><?= e($route['job_number']) ?></a></span>
            <span class="text-muted">| ลูกค้า: <?= e($route['customer_name']) ?></span>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">หน้าหลัก</a></li>
                <li class="breadcrumb-item"><a href="index.php">Routes</a></li>
                <li class="breadcrumb-item active"><?= e($route['route_number']) ?></li>
            </ol>
        </nav>
    </div>
    <div class="page-header-actions">
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
        <?php if ($canConfirm): ?>
        <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn btn-info">
                <i class="bi bi-check-circle me-1"></i>ยืนยัน Route
            </button>
        </form>
        <?php endif; ?>

        <?php if ($canReset): ?>
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#resetModal">
            <i class="bi bi-arrow-counterclockwise me-1"></i>จัดใหม่
        </button>
        <?php endif; ?>

        <?php if ($canDispatch): ?>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dispatchModal">
            <i class="bi bi-truck me-1"></i>ปล่อยรถ
        </button>
        <?php endif; ?>

        <?php if ($canReceive): ?>
        <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#receiveModal">
            <i class="bi bi-box-arrow-in-down me-1"></i>ยืนยันถึงหน้างาน
        </button>
        <?php endif; ?>

        <?php if ($canReturn): ?>
        <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#returnModal">
            <i class="bi bi-box-arrow-in-left me-1"></i>รับของกลับ (WH)
        </button>
        <?php endif; ?>

        <?php if ($canCancel): ?>
        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
            <i class="bi bi-x-circle me-1"></i>ยกเลิก
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap align-items-center gap-4">
            <div class="d-flex align-items-center gap-2">
                <div class="rounded-3 p-2" style="background: var(--primary-light);">
                    <i class="bi bi-box text-primary" style="font-size: 1.25rem;"></i>
                </div>
                <div>
                    <div class="fw-bold fs-5"><?= number_format($itemsCount) ?></div>
                    <div class="text-muted small">รายการใน Route</div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="rounded-3 p-2" style="background: var(--info-light, #e0f7fa);">
                    <i class="bi bi-camera text-info" style="font-size: 1.25rem;"></i>
                </div>
                <div>
                    <div class="fw-bold fs-5"><?= number_format($photoUploadedTotal) ?>/<?= number_format($photoRequiredTotal) ?></div>
                    <div class="text-muted small">หลักฐานรูปภาพ</div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="rounded-3 p-2" style="background: var(--warning-light, #fff8e1);">
                    <i class="bi bi-calendar-event text-warning" style="font-size: 1.25rem;"></i>
                </div>
                <div>
                    <div class="fw-bold fs-5"><?= formatDate($route['route_date']) ?></div>
                    <div class="text-muted small">วันที่จัดส่ง</div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="rounded-3 p-2" style="background: var(--success-light, #e8f5e9);">
                    <i class="bi bi-person-badge text-success" style="font-size: 1.25rem;"></i>
                </div>
                <div>
                    <div class="fw-bold fs-5"><?= e($driverLabel) ?></div>
                    <div class="text-muted small">คนขับ</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Route Info - Horizontal Layout -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-info-circle me-2"></i>ข้อมูล Route</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-borderless mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="text-muted small fw-normal">Route Number</th>
                        <th class="text-muted small fw-normal">ประเภท</th>
                        <th class="text-muted small fw-normal">Plan</th>
                        <th class="text-muted small fw-normal">Job</th>
                        <th class="text-muted small fw-normal">ลูกค้า</th>
                        <th class="text-muted small fw-normal">ปลายทาง</th>
                        <th class="text-muted small fw-normal">รถ</th>
                        <th class="text-muted small fw-normal">Supplier</th>
                        <th class="text-muted small fw-normal">คนขับ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="fw-semibold"><?= e($route['route_number']) ?></td>
                        <td><?= $routeType === 'Return' ? 'ขากลับ' : 'ขาไป' ?></td>
                        <td><a href="../../planning/view.php?id=<?= $route['plan_id'] ?>" class="text-primary"><?= e($route['plan_number']) ?></a></td>
                        <td><a href="../../jobs/view.php?id=<?= $route['job_id'] ?>" class="text-primary"><?= e($route['job_number']) ?></a></td>
                        <td><?= e($route['customer_name']) ?></td>
                        <td><?= e($route['destination'] ?? '-') ?></td>
                        <td><?= $route['vehicle_serial'] ? e($route['vehicle_serial']) : '-' ?></td>
                        <td><?= e($route['supplier_name'] ?? '-') ?></td>
                        <td><?= e($driverLabel) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php if (!empty($route['notes'])): ?>
        <div class="px-3 pb-3">
            <div class="text-muted small">หมายเหตุ</div>
            <div><?= nl2br(e($route['notes'])) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Route Items -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-box me-2"></i>รายการใน Route</span>
        <span class="badge bg-secondary"><?= $itemsCount ?></span>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>ประเภท</th>
                    <th>รายการ</th>
                    <th class="text-center">Qty</th>
                    <th>สภาพออก</th>
                    <th>สภาพเข้า</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">ไม่มีรายการ</td></tr>
                <?php else: ?>
                <?php foreach ($items as $item): ?>
                <?php
                    $qtyDisplay = !empty($item['quantity']) ? (float)$item['quantity'] : null;
                ?>
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
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?= $qtyDisplay !== null ? formatNumber($qtyDisplay, $qtyDisplay == (int)$qtyDisplay ? 0 : 2) : '-' ?>
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

<?php if ($canDispatch): ?>
<div class="modal fade" id="dispatchModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="dispatch">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-truck me-2"></i>ปล่อยรถ</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">ชื่อผู้ปล่อย <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="dispatch_name" required value="<?= e($currentUserName) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">เวลาออก <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="dispatch_time" required value="<?= date('Y-m-d\TH:i') ?>">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">รูปหลักฐาน (อย่างน้อย 1 รูป)</label>
                            <input type="file" class="form-control" name="dispatch_photos[]" accept="image/*" multiple required>
                            <small class="text-muted">อัพโหลดได้หลายรูป (สูงสุด <?= EvidencePhoto::PHOTOS_MAX ?> รูป)</small>
                        </div>
                    </div>

                    <div class="border rounded p-3">
                        <div class="fw-semibold mb-2">ตรวจสภาพอุปกรณ์ก่อนปล่อย</div>
                        <?php
                            $serialItems = array_values(array_filter($items, fn($i) => !empty($i['serial_id'])));
                        ?>
                        <?php if (empty($serialItems)): ?>
                            <div class="text-muted small">ไม่มีรายการที่ต้องตรวจสภาพ</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Serial</th>
                                        <th>รายการ</th>
                                        <th class="text-center" style="width: 120px;">สภาพออก</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($serialItems as $sItem): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= e($sItem['serial_number']) ?></td>
                                        <td class="small text-muted"><?= e($sItem['item_code']) ?> - <?= e($sItem['item_name']) ?></td>
                                        <td class="text-center">
                                            <select class="form-select form-select-sm" name="condition_out[<?= (int) $sItem['id'] ?>]">
                                                <?php $condOut = $sItem['condition_out'] ?? 'Good'; ?>
                                                <option value="Good" <?= $condOut === 'Good' ? 'selected' : '' ?>>ดี</option>
                                                <option value="Fair" <?= $condOut === 'Fair' ? 'selected' : '' ?>>พอใช้</option>
                                                <option value="Damaged" <?= $condOut === 'Damaged' ? 'selected' : '' ?>>ชำรุด</option>
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
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>ยืนยันปล่อยรถ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canReceive): ?>
<div class="modal fade" id="receiveModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="receive">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-box-arrow-in-down me-2"></i>ยืนยันถึงหน้างาน</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">ชื่อผู้รับ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="receiver_name" required value="<?= e($currentUserName) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">เวลาถึงหน้างาน <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="receive_time" required value="<?= date('Y-m-d\TH:i') ?>">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">รูปหลักฐาน (อย่างน้อย 1 รูป)</label>
                            <input type="file" class="form-control" name="receive_photos[]" accept="image/*" multiple required>
                            <small class="text-muted">อัพโหลดได้หลายรูป (สูงสุด <?= EvidencePhoto::PHOTOS_MAX ?> รูป)</small>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea class="form-control" name="receive_notes" rows="2" placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-circle me-1"></i>บันทึกการถึงหน้างาน
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canReturn): ?>
<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="return">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-box-arrow-in-left me-2"></i>รับของกลับ (WH)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-12">
                            <label class="form-label">รูปหลักฐาน (อย่างน้อย 1 รูป)</label>
                            <input type="file" class="form-control" name="return_photos[]" accept="image/*" multiple required>
                            <small class="text-muted">อัพโหลดได้หลายรูป (สูงสุด <?= EvidencePhoto::PHOTOS_MAX ?> รูป)</small>
                        </div>
                    </div>
                    <div class="border rounded p-3">
                        <div class="fw-semibold mb-2">ตรวจสอบสภาพของ / จำนวนใช้จริง</div>
                        <?php if (empty($items)): ?>
                            <div class="text-muted small">ไม่มีรายการใน Route นี้</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ประเภท</th>
                                        <th>รายการ</th>
                                        <th>Serial/จำนวน</th>
                                        <th>สภาพ/ใช้จริง</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-<?= match($item['item_type']) {
                                                'Device' => 'primary',
                                                'Equipment' => 'info',
                                                'Vehicle' => 'warning',
                                                'Consumable' => 'secondary',
                                                'Manpower' => 'success',
                                                'Person' => 'success',
                                                default => 'secondary'
                                            } ?>"><?= e($item['item_type']) ?></span>
                                        </td>
                                        <td>
                                            <?= e($item['item_name'] ?? $item['people_name'] ?? '-') ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($item['serial_id'])): ?>
                                                <code><?= e($item['serial_number']) ?></code>
                                            <?php elseif (($item['item_type'] ?? '') === 'Consumable'): ?>
                                                <?= formatNumber((float) $item['qty_out'], 2) ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($item['serial_id'])): ?>
                                                <select name="condition_<?= $item['id'] ?>" class="form-select form-select-sm">
                                                    <option value="Good">Good - ปกติ</option>
                                                    <option value="Fair">Fair - พอใช้</option>
                                                    <option value="Damaged">Damaged - เสียหาย</option>
                                                    <option value="Lost">Lost - สูญหาย</option>
                                                </select>
                                            <?php elseif (($item['item_type'] ?? '') === 'Consumable'): ?>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" name="used_<?= $item['id'] ?>" class="form-control"
                                                           value="<?= e($item['qty_out']) ?>" min="0" max="<?= e($item['qty_out']) ?>" step="0.01">
                                                    <span class="input-group-text">ใช้จริง</span>
                                                </div>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-check-circle me-1"></i>ยืนยันรับของกลับ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canReset): ?>
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="reset">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">จัด Route ใหม่</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">เหตุผลในการจัดใหม่ <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="reset_reason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                    <button type="submit" class="btn btn-primary">ยืนยันจัดใหม่</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

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

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
