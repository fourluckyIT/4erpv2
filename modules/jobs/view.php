<?php
/**
 * View Job Detail
 * ERP v2 - Phase 2
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/StatusMachine.php';
require_once __DIR__ . '/../../core/Job.php';
require_once __DIR__ . '/../../core/Plan.php';
require_once __DIR__ . '/../../core/Route.php';

$auth = new Auth();
$auth->requireAuth();

$jobId = (int) get('id');
if (!$jobId) {
    setFlash('error', 'Invalid job ID');
    redirect('index.php');
}

$jobModel = new Job();
$job = $jobModel->getById($jobId);

if (!$job) {
    setFlash('error', 'ไม่พบงานนี้');
    redirect('index.php');
}

// Handle status change action
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("view.php?id=$jobId");
    }
    
    $action = post('action');
    $reason = post('reason');
    
    $result = $jobModel->changeStatus($jobId, $action, $reason);
    
    if ($result['success']) {
        setFlash('success', 'เปลี่ยนสถานะเป็น ' . StatusMachine::getStatusLabel($result['new_status']) . ' เรียบร้อย');
    } else {
        setFlash('error', $result['error']);
    }
    
    redirect("view.php?id=$jobId");
}

// Get available actions for current user
$userRoles = $auth->getCurrentRoles();
$availableActions = StatusMachine::getAvailableActions($job['status'], $userRoles);
$rbac = new RBAC();

// Get status history
$statusHistory = $jobModel->getStatusHistory($jobId);

// Get routes for this job (via plans)
$planModel = new Plan();
$routeModel = new Route();
$jobRoutes = [];
$plans = $planModel->getByJobId($jobId);
foreach ($plans as $plan) {
    $routes = $routeModel->getByPlanId($plan['id']);
    foreach ($routes as $route) {
        $route['plan_number'] = $plan['plan_number'];
        $jobRoutes[] = $route;
    }
}

$pageTitle = $job['job_number'] . ' - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-briefcase me-2"></i><?= e($job['job_number']) ?>
                <span class="badge bg-<?= StatusMachine::getStatusBadgeClass($job['status']) ?> ms-2">
                    <?= StatusMachine::getStatusLabel($job['status']) ?>
                </span>
            </h2>
            <p class="text-muted mb-0"><?= e($job['customer_name']) ?></p>
        </div>
        <div>
            <?php if (StatusMachine::isFieldEditable($job['status'], 'scope_short')): ?>
            <a href="edit.php?id=<?= $jobId ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>แก้ไข
            </a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <!-- Main Info -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>ข้อมูลงาน
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">ลูกค้า</label>
                        <div class="fw-bold"><?= e($job['customer_name']) ?></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Site</label>
                        <div><?= e($job['site_name'] ?? '-') ?></div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">ประเภทงาน</label>
                        <div><span class="badge bg-secondary"><?= e($job['job_type']) ?></span></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">อ้างอิงใบเสนอราคา</label>
                        <div><?= e($job['quotation_no'] ?? '-') ?></div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label text-muted">รายละเอียด</label>
                    <div class="fw-bold"><?= e($job['scope_short']) ?></div>
                    <?php if ($job['scope_detail']): ?>
                    <div class="text-muted small mt-1"><?= nl2br(e($job['scope_detail'])) ?></div>
                    <?php endif; ?>
                </div>
                
                <hr>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">วันเริ่มงาน</label>
                        <div><?= formatDate($job['plan_start_date']) ?></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">วันสิ้นสุด</label>
                        <div><?= formatDate($job['plan_end_date']) ?></div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">มูลค่าสัญญา</label>
                        <div class="fw-bold text-primary"><?= formatNumber($job['contract_value']) ?> บาท</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">งบประมาณ</label>
                        <div><?= formatNumber($job['budget']) ?> บาท</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Status History -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-clock-history me-2"></i>ประวัติสถานะ
            </div>
            <div class="card-body">
                <?php if (empty($statusHistory)): ?>
                <p class="text-muted text-center">ไม่มีประวัติ</p>
                <?php else: ?>
                <div class="timeline">
                    <?php foreach ($statusHistory as $h): ?>
                    <div class="timeline-item">
                        <div class="d-flex justify-content-between">
                            <div>
                                <?php if ($h['old_status']): ?>
                                <span class="badge bg-secondary"><?= StatusMachine::getStatusLabel($h['old_status']) ?></span>
                                <i class="bi bi-arrow-right mx-2"></i>
                                <?php endif; ?>
                                <span class="badge bg-<?= StatusMachine::getStatusBadgeClass($h['new_status']) ?>">
                                    <?= StatusMachine::getStatusLabel($h['new_status']) ?>
                                </span>
                                <span class="text-muted ms-2">โดย <?= e($h['changed_by_name']) ?></span>
                            </div>
                            <small class="text-muted"><?= formatDateTime($h['created_at']) ?></small>
                        </div>
                        <?php if ($h['reason']): ?>
                        <div class="text-muted small mt-1">
                            <i class="bi bi-chat-left-quote"></i> <?= e($h['reason']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Routes Section -->
        <?php if (!empty($jobRoutes)): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-truck me-2"></i>Routes (<?= count($jobRoutes) ?>)</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Route</th>
                            <th>วันที่</th>
                            <th>ปลายทาง</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobRoutes as $route): ?>
                        <tr>
                            <td>
                                <strong><?= e($route['route_number']) ?></strong>
                                <?php if ($route['supplier_name']): ?>
                                <br><small class="text-muted"><i class="bi bi-building"></i> <?= e($route['supplier_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= formatDate($route['route_date']) ?></td>
                            <td><?= e($route['destination'] ?? '-') ?></td>
                            <td>
                                <span class="badge bg-<?= match($route['status']) {
                                    'Draft' => 'secondary',
                                    'Confirmed' => 'info',
                                    'Dispatched' => 'warning',
                                    'Received' => 'success',
                                    'Delivered' => 'success',
                                    'Cancelled' => 'danger',
                                    default => 'secondary'
                                } ?>"><?= e($route['status']) ?></span>
                            </td>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/logistics/routes/view.php?id=<?= $route['id'] ?>" 
                                   class="btn btn-sm btn-outline-primary" title="ดูรายละเอียด">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if ($route['status'] === 'Dispatched'): ?>
                                <a href="<?= BASE_URL ?>/modules/logistics/routes/receive.php?id=<?= $route['id'] ?>" 
                                   class="btn btn-sm btn-success" title="รับของหน้างาน">
                                    <i class="bi bi-box-arrow-in-down"></i> รับ
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-lg-4">
        <!-- Actions -->
        <?php if (!empty($availableActions)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-lightning me-2"></i>Actions
            </div>
            <div class="card-body">
                <?php foreach ($availableActions as $action => $config): ?>
                <?php if ($action === 'plan'): ?>
                <!-- Plan action redirects to Planning page -->
                <a href="<?= BASE_URL ?>/modules/planning/create.php?job_id=<?= $jobId ?>" class="btn btn-primary w-100 mb-2">
                    <i class="bi bi-calendar-check me-1"></i>
                    <?= StatusMachine::getActionLabel($action) ?>
                </a>
                <?php else: ?>
                <form method="POST" action="" class="mb-2">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="<?= e($action) ?>">
                    
                    <?php if ($config['requires_reason']): ?>
                    <div class="mb-2">
                        <input type="text" class="form-control form-control-sm" name="reason" 
                               placeholder="เหตุผล (จำเป็น)" required>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" class="btn btn-<?= $action === 'void' ? 'danger' : ($action === 'approve' ? 'success' : 'primary') ?> w-100"
                            onclick="return confirm('ยืนยัน <?= StatusMachine::getActionLabel($action) ?>?')">
                        <i class="bi bi-<?= $action === 'void' ? 'x-circle' : ($action === 'approve' ? 'check-circle' : 'arrow-right') ?> me-1"></i>
                        <?= StatusMachine::getActionLabel($action) ?>
                    </button>
                </form>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Request Extension (available after Planned status) -->
        <?php 
        $extensionStatuses = ['Planned', 'Dispatched', 'In Progress'];
        $canRequestExtension = in_array($job['status'], $extensionStatuses) && $rbac->hasAnyRole(['ADM', 'PLN', 'MGR']);
        ?>
        <?php if ($canRequestExtension): ?>
        <div class="card mb-4 border-warning">
            <div class="card-header bg-warning text-dark">
                <i class="bi bi-calendar-plus me-2"></i>ขอขยายงาน (Extension)
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    หลังจากสถานะ Planned แล้ว หากต้องการเปลี่ยนแปลงรายละเอียดงาน ต้องขอ Extension
                </p>
                <a href="<?= BASE_URL ?>/modules/jobs/extension.php?job_id=<?= $jobId ?>" class="btn btn-warning w-100">
                    <i class="bi bi-plus-circle me-1"></i>ขอ Extension
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Owners -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-people me-2"></i>ผู้รับผิดชอบ
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label text-muted">Sale</label>
                    <div class="fw-bold"><?= e($job['owner_sale_name']) ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted">Planner</label>
                    <div><?= e($job['owner_planner_name'] ?? '-') ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted">สร้างโดย</label>
                    <div><?= e($job['created_by_name']) ?></div>
                    <small class="text-muted"><?= formatDateTime($job['created_at']) ?></small>
                </div>
            </div>
        </div>
        
        <!-- Approval Info -->
        <?php if ($job['submitted_at']): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-check-square me-2"></i>การอนุมัติ
            </div>
            <div class="card-body">
                <div class="mb-2">
                    <small class="text-muted">ส่งอนุมัติ:</small><br>
                    <?= formatDateTime($job['submitted_at']) ?>
                </div>
                <?php if ($job['approved_at']): ?>
                <div class="mb-2">
                    <small class="text-muted">อนุมัติ:</small><br>
                    <?= formatDateTime($job['approved_at']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.timeline-item {
    padding: 10px 0;
    border-bottom: 1px solid #eee;
}
.timeline-item:last-child {
    border-bottom: none;
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
