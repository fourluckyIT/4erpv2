<?php
/**
 * Route Return - WH receives items back from site
 * Uses the SAME route (no new route needed)
 * 
 * Flow: Dispatched/InProgress/Received → Returned
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/Route.php';
require_once __DIR__ . '/../../../core/EvidencePhoto.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
if (!$rbac->hasAnyRole(['ADM', 'WH', 'MGR'])) {
    setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    redirect(BASE_URL . '/modules/logistics/routes/index.php');
}

$routeId = (int) get('id');
if (!$routeId) {
    setFlash('error', 'Invalid route ID');
    redirect(BASE_URL . '/modules/logistics/routes/index.php');
}

$routeModel = new Route();
$route = $routeModel->getById($routeId);

if (!$route) {
    setFlash('error', 'ไม่พบ Route นี้');
    redirect(BASE_URL . '/modules/logistics/routes/index.php');
}

// Only allow return for Dispatched, InProgress, or Received routes
$allowedStatuses = ['Dispatched', 'InProgress', 'Received'];
if (!in_array($route['status'], $allowedStatuses)) {
    setFlash('error', 'Route สถานะ ' . $route['status'] . ' ไม่สามารถรับคืนได้');
    redirect(BASE_URL . '/modules/logistics/routes/create.php?id=' . $routeId);
}

$db = getDB();

// Get route items
$routeItems = $routeModel->getItems($routeId);

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("return.php?id=$routeId");
    }
    
    try {
        $db->beginTransaction();
        
        // Upload return photos first
        $photoModel = new EvidencePhoto();
        $photoCount = 0;
        
        if (!empty($_FILES['photos']['name'][0])) {
            $photoSeq = 1;
            foreach ($_FILES['photos']['tmp_name'] as $i => $tmpName) {
                if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK && $photoSeq <= 4) {
                    $file = [
                        'tmp_name' => $tmpName,
                        'name' => $_FILES['photos']['name'][$i],
                        'size' => $_FILES['photos']['size'][$i],
                        'type' => $_FILES['photos']['type'][$i],
                        'error' => $_FILES['photos']['error'][$i]
                    ];
                    $result = $photoModel->upload($routeId, 'Return', $photoSeq, $file);
                    if ($result['success']) {
                        $photoCount++;
                    }
                    $photoSeq++;
                }
            }
        }
        
        // Get existing photo count
        $existingPhotos = $photoModel->getPhotos($routeId, 'Return');
        $totalPhotos = count($existingPhotos) + $photoCount;
        
        if ($totalPhotos < 4) {
            $db->rollBack();
            setFlash('error', "กรุณาอัพโหลดรูปรับคืนให้ครบ 4 รูป (ปัจจุบันมี $totalPhotos รูป)");
            redirect("return.php?id=$routeId");
        }
        
        // Collect item conditions
        $itemConditions = [];
        $consumableUsed = [];
        
        foreach ($routeItems as $item) {
            if ($item['serial_id']) {
                // Device/Equipment - get condition
                $conditionKey = 'condition_' . $item['id'];
                $itemConditions[$item['id']] = post($conditionKey, 'Good');
            } elseif ($item['item_type'] === 'Consumable') {
                // Consumable - get actual used qty
                $usedKey = 'used_' . $item['id'];
                $consumableUsed[$item['id']] = (float) post($usedKey, 0);
            }
        }
        
        $db->commit();
        
        // Now call markReturned (it will check photos again)
        $result = $routeModel->markReturned($routeId, $itemConditions, $consumableUsed);
        
        if ($result['success']) {
            setFlash('success', 'รับคืนสำเร็จ! Route ' . $route['route_number'] . ' สถานะเป็น Returned');
            redirect(BASE_URL . '/modules/logistics/routes/create.php?id=' . $routeId);
        } else {
            setFlash('error', $result['error']);
            redirect("return.php?id=$routeId");
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect("return.php?id=$routeId");
    }
}

// Get existing return photos
$photoModel = new EvidencePhoto();
$existingPhotos = $photoModel->getPhotos($routeId, 'Return');

$pageTitle = 'รับคืน - ' . $route['route_number'];
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">หน้าหลัก</a></li>
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/modules/logistics/routes/index.php">Routes</a></li>
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/modules/logistics/routes/create.php?id=<?= $routeId ?>"><?= e($route['route_number']) ?></a></li>
                    <li class="breadcrumb-item active">รับคืน</li>
                </ol>
            </nav>
            <div class="d-flex justify-content-between align-items-center">
                <h3><i class="bi bi-box-arrow-in-down me-2"></i>รับคืนของ (WH)</h3>
                <span class="badge bg-info fs-6"><?= e($route['status']) ?></span>
            </div>
            <p class="text-muted">Route: <strong><?= e($route['route_number']) ?></strong> | วันที่: <?= formatDate($route['route_date']) ?></p>
        </div>
    </div>
    
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        
        <div class="row">
            <div class="col-lg-8">
                <!-- Items Condition -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <i class="bi bi-list-check me-2"></i>ตรวจสอบสภาพของ
                    </div>
                    <div class="card-body">
                        <?php if (empty($routeItems)): ?>
                            <p class="text-muted">ไม่มีรายการในเส้นทางนี้</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>ประเภท</th>
                                            <th>รายการ</th>
                                            <th>Serial/จำนวน</th>
                                            <th>สภาพ/จำนวนใช้จริง</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($routeItems as $item): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-<?= match($item['item_type']) {
                                                    'Device' => 'primary',
                                                    'Equipment' => 'info',
                                                    'Consumable' => 'warning',
                                                    'Person' => 'success',
                                                    default => 'secondary'
                                                } ?>"><?= e($item['item_type']) ?></span>
                                            </td>
                                            <td><?= e($item['item_name'] ?? $item['person_name'] ?? '-') ?></td>
                                            <td>
                                                <?php if ($item['serial_id']): ?>
                                                    <code><?= e($item['serial_number']) ?></code>
                                                <?php else: ?>
                                                    จำนวน: <?= number_format($item['qty_out']) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($item['serial_id']): ?>
                                                    <!-- Device/Equipment: Condition select -->
                                                    <select name="condition_<?= $item['id'] ?>" class="form-select form-select-sm">
                                                        <option value="Good">Good - ปกติ</option>
                                                        <option value="Damaged">Damaged - เสียหาย</option>
                                                        <option value="Lost">Lost - สูญหาย</option>
                                                    </select>
                                                <?php elseif ($item['item_type'] === 'Consumable'): ?>
                                                    <!-- Consumable: Actual used qty -->
                                                    <div class="input-group input-group-sm">
                                                        <input type="number" name="used_<?= $item['id'] ?>" 
                                                               class="form-control" 
                                                               value="<?= e($item['qty_out']) ?>" 
                                                               min="0" max="<?= e($item['qty_out']) ?>" step="0.01">
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
            </div>
            
            <div class="col-lg-4">
                <!-- Photo Upload -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <i class="bi bi-camera me-2"></i>รูปถ่ายรับคืน (4 รูป)
                    </div>
                    <div class="card-body">
                        <?php if (!empty($existingPhotos)): ?>
                            <div class="mb-3">
                                <small class="text-muted">อัพโหลดแล้ว: <?= count($existingPhotos) ?>/4 รูป</small>
                                <div class="row g-2 mt-1">
                                    <?php foreach ($existingPhotos as $photo): ?>
                                    <div class="col-6">
                                        <img src="<?= BASE_URL ?>/<?= e($photo['file_path']) ?>" 
                                             class="img-thumbnail" alt="Evidence">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php $remaining = 4 - count($existingPhotos); ?>
                        <?php if ($remaining > 0): ?>
                        <div class="mb-3">
                            <label class="form-label">อัพโหลดรูปเพิ่ม (<?= $remaining ?> รูป)</label>
                            <input type="file" name="photos[]" class="form-control" 
                                   accept="image/*" multiple>
                            <small class="text-muted">รูปภาพรอบๆ ตอนรับของคืน</small>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-1"></i>อัพโหลดรูปครบแล้ว
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Submit -->
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-check-lg me-1"></i>ยืนยันรับคืน
                    </button>
                    <a href="<?= BASE_URL ?>/modules/logistics/routes/create.php?id=<?= $routeId ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>กลับ
                    </a>
                </div>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
