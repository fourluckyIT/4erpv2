<?php
/**
 * Site Receive - Receive items at site
 * 4ERP - Route receiving with photo evidence and receiver confirmation
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/Route.php';
require_once __DIR__ . '/../../../core/Plan.php';
require_once __DIR__ . '/../../../core/EvidencePhoto.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC check - ADM, PLN, WH, MGR can receive
$rbac = new RBAC();
if (!$rbac->hasAnyRole(['ADM', 'PLN', 'WH', 'MGR'])) {
    setFlash('error', 'คุณไม่มีสิทธิ์รับของหน้างาน');
    redirect(BASE_URL . '/index.php');
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

if ($route['status'] !== 'Dispatched') {
    setFlash('error', 'Route นี้ยังไม่ได้ Dispatch หรือรับไปแล้ว');
    redirect(BASE_URL . '/modules/logistics/routes/view.php?id=' . $routeId);
}

// Get route items
$routeItems = $routeModel->getItems($routeId);

// Get plan and job info
$planModel = new Plan();
$plan = $planModel->getById($route['plan_id']);
$db = getDB();
$stmt = $db->prepare("SELECT j.*, c.name as customer_name, s.name as site_name 
                      FROM jobs j 
                      LEFT JOIN customers c ON j.customer_id = c.id 
                      LEFT JOIN sites s ON j.site_id = s.id 
                      WHERE j.id = ?");
$stmt->execute([$plan['job_id']]);
$job = $stmt->fetch();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("receive.php?id=$routeId");
    }
    
    $receiverName = sanitize(post('receiver_name', ''));
    $receiveNotes = sanitize(post('receive_notes', ''));
    
    if (empty($receiverName)) {
        setFlash('error', 'กรุณาระบุชื่อผู้รับ');
        redirect("receive.php?id=$routeId");
    }
    
    try {
        $db->beginTransaction();
        
        // Update route status to Received
        $stmt = $db->prepare("UPDATE routes SET status = 'Received', 
                              received_by_name = ?, received_at = NOW(), 
                              receive_notes = ?, updated_at = NOW() 
                              WHERE id = ?");
        $stmt->execute([$receiverName, $receiveNotes, $routeId]);
        
        // Handle photo uploads
        $photoModel = new EvidencePhoto();
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
                    $photoModel->upload($routeId, 'Receive', $photoSeq, $file);
                    $photoSeq++;
                }
            }
        }
        
        // Audit log
        $audit = new AuditLog();
        $audit->log('receive', 'ROUTE', $routeId, [
            'receiver_name' => $receiverName,
            'notes' => $receiveNotes,
            'items_count' => count($routeItems)
        ], null);
        
        $db->commit();
        
        setFlash('success', 'รับของหน้างานเรียบร้อยแล้ว');
        redirect(BASE_URL . '/modules/logistics/routes/view.php?id=' . $routeId);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect("receive.php?id=$routeId");
    }
}

$pageTitle = 'รับของหน้างาน - ' . $route['route_number'];
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">หน้าหลัก</a></li>
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $job['id'] ?>"><?= e($job['job_number']) ?></a></li>
                    <li class="breadcrumb-item active"><?= e($route['route_number']) ?> - รับของหน้างาน</li>
                </ol>
            </nav>
            <h3><i class="bi bi-box-arrow-in-down me-2"></i>รับของหน้างาน</h3>
        </div>
    </div>
    
    <div class="row">
        <div class="col-lg-8">
            <!-- Route Info -->
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <strong><?= e($route['route_number']) ?></strong>
                    <span class="badge bg-warning text-dark ms-2">Dispatched - รอรับ</span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Job:</strong> <?= e($job['job_number']) ?></p>
                            <p><strong>ลูกค้า:</strong> <?= e($job['customer_name']) ?></p>
                            <p><strong>Site:</strong> <?= e($job['site_name'] ?? '-') ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>วันที่:</strong> <?= formatDate($route['route_date']) ?></p>
                            <p><strong>ปลายทาง:</strong> <?= e($route['destination'] ?? '-') ?></p>
                            <?php if ($route['supplier_name']): ?>
                            <p><strong>Supplier:</strong> <?= e($route['supplier_name']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Items to Receive -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-list-check me-2"></i>รายการที่ต้องรับ (<?= count($routeItems) ?> รายการ)
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($routeItems as $item): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <?php if ($item['serial_id']): ?>
                            <span class="badge bg-info me-2"><?= e($item['item_type']) ?></span>
                            <strong><?= e($item['serial_number']) ?></strong>
                            <small class="text-muted ms-2"><?= e($item['item_name']) ?></small>
                            <?php elseif ($item['item_id'] && $item['item_type'] === 'Consumable'): ?>
                            <span class="badge bg-secondary me-2">Consumable</span>
                            <strong><?= e($item['item_code']) ?></strong>
                            <small class="text-muted ms-2"><?= e($item['item_name']) ?></small>
                            <span class="badge bg-primary ms-2"><?= (int)$item['quantity'] ?></span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark me-2">บุคลากร</span>
                            <strong><?= e($item['people_code']) ?></strong>
                            <small class="text-muted ms-2"><?= e($item['people_name']) ?></small>
                            <?php endif; ?>
                        </div>
                        <i class="bi bi-check-circle text-success" style="font-size: 1.2rem;"></i>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        
        <div class="col-lg-4">
            <!-- Receive Form -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-check-circle me-2"></i>ยืนยันการรับ
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">ชื่อผู้รับ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="receiver_name" required 
                                   placeholder="พิมพ์ชื่อผู้รับเพื่อยืนยัน">
                            <small class="text-muted">ชื่อหัวหน้าหน้างาน หรือผู้รับผิดชอบ</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">รูปถ่ายหลักฐาน</label>
                            <input type="file" class="form-control" name="photos[]" multiple accept="image/*">
                            <small class="text-muted">อัพโหลดรูปการรับของ (เลือกได้หลายรูป)</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea class="form-control" name="receive_notes" rows="3" 
                                      placeholder="หมายเหตุการรับ (ถ้ามี)"></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-success w-100 btn-lg">
                            <i class="bi bi-check-circle me-2"></i>ยืนยันรับของทั้งหมด
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Dynamic Link -->
            <div class="card mt-3">
                <div class="card-header">
                    <i class="bi bi-link-45deg me-2"></i>ลิงก์สำหรับแชร์
                </div>
                <div class="card-body">
                    <div class="input-group">
                        <input type="text" class="form-control" id="shareLink" readonly
                               value="<?= BASE_URL ?>/modules/logistics/routes/receive.php?id=<?= $routeId ?>">
                        <button class="btn btn-outline-secondary" type="button" onclick="copyLink()">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                    <small class="text-muted">ส่งลิงก์นี้ให้ผู้รับหน้างานเพื่อยืนยันการรับ</small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyLink() {
    const input = document.getElementById('shareLink');
    input.select();
    document.execCommand('copy');
    alert('คัดลอกลิงก์แล้ว!');
}
</script>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
