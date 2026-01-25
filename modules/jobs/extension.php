<?php
/**
 * Job Extension Request
 * ERP v2 - Request changes to job after Planned status (lockpoint)
 * 
 * Per agents.md: After Planned, all changes must go through Extension system
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/Job.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
if (!$rbac->hasAnyRole(['ADM', 'PLN', 'MGR'])) {
    setFlash('error', 'คุณไม่มีสิทธิ์ขอ Extension');
    redirect(BASE_URL . '/modules/jobs/index.php');
}

$jobId = (int) get('job_id');
if (!$jobId) {
    setFlash('error', 'Invalid job ID');
    redirect(BASE_URL . '/modules/jobs/index.php');
}

$jobModel = new Job();
$job = $jobModel->getById($jobId);

if (!$job) {
    setFlash('error', 'ไม่พบงานนี้');
    redirect(BASE_URL . '/modules/jobs/index.php');
}

// Only allow extension for jobs after Planned status
$extensionStatuses = ['Planned', 'Dispatched', 'In Progress'];
if (!in_array($job['status'], $extensionStatuses)) {
    setFlash('error', 'ไม่สามารถขอ Extension สำหรับงานสถานะ ' . $job['status']);
    redirect(BASE_URL . '/modules/jobs/view.php?id=' . $jobId);
}

$db = getDB();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("extension.php?job_id=$jobId");
    }
    
    $extensionType = sanitize(post('extension_type', ''));
    $reason = sanitize(post('reason', ''));
    $requestedChanges = sanitize(post('requested_changes', ''));
    $newEndDate = post('new_end_date', '');
    $additionalBudget = (float) post('additional_budget', 0);
    
    if (empty($extensionType) || empty($reason) || empty($requestedChanges)) {
        setFlash('error', 'กรุณากรอกข้อมูลให้ครบถ้วน');
        redirect("extension.php?job_id=$jobId");
    }
    
    try {
        $db->beginTransaction();
        
        // Create extension request
        $stmt = $db->prepare("
            INSERT INTO job_extensions (
                job_id, extension_type, reason, requested_changes, 
                new_end_date, additional_budget, status, 
                requested_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, NOW())
        ");
        $stmt->execute([
            $jobId, 
            $extensionType, 
            $reason, 
            $requestedChanges,
            $newEndDate ?: null,
            $additionalBudget,
            $_SESSION['user_id']
        ]);
        
        $extensionId = $db->lastInsertId();
        
        // Audit log
        $audit = new AuditLog();
        $audit->log('extension_request', 'JOB', $jobId, [
            'extension_id' => $extensionId,
            'type' => $extensionType,
            'reason' => $reason
        ], null);
        
        $db->commit();
        
        setFlash('success', 'ส่งคำขอ Extension เรียบร้อยแล้ว รอการอนุมัติ');
        redirect(BASE_URL . '/modules/jobs/view.php?id=' . $jobId);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect("extension.php?job_id=$jobId");
    }
}

// Get existing extensions for this job
$stmt = $db->prepare("
    SELECT e.*, u.fullname as requested_by_name, 
           ua.fullname as approved_by_name
    FROM job_extensions e
    LEFT JOIN users u ON e.requested_by = u.id
    LEFT JOIN users ua ON e.approved_by = ua.id
    WHERE e.job_id = ?
    ORDER BY e.created_at DESC
");
$stmt->execute([$jobId]);
$extensions = $stmt->fetchAll();

$pageTitle = 'ขอ Extension - ' . $job['job_number'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">หน้าหลัก</a></li>
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/modules/jobs/index.php">Jobs</a></li>
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $jobId ?>"><?= e($job['job_number']) ?></a></li>
                    <li class="breadcrumb-item active">ขอ Extension</li>
                </ol>
            </nav>
            <h3><i class="bi bi-calendar-plus me-2"></i>ขอ Extension</h3>
            <p class="text-muted">งาน: <strong><?= e($job['job_number']) ?></strong> - <?= e($job['scope_short']) ?></p>
        </div>
    </div>
    
    <div class="row">
        <div class="col-lg-8">
            <!-- Extension Request Form -->
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <i class="bi bi-plus-circle me-2"></i>แบบฟอร์มขอ Extension
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>หมายเหตุ:</strong> หลังจากงานสถานะ "วางแผนแล้ว" การเปลี่ยนแปลงใดๆ ต้องผ่านการขอ Extension และได้รับการอนุมัติจาก Manager
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">ประเภท Extension <span class="text-danger">*</span></label>
                            <select class="form-select" name="extension_type" required>
                                <option value="">-- เลือกประเภท --</option>
                                <option value="date_extension">ขยายระยะเวลา</option>
                                <option value="scope_change">เปลี่ยนแปลงขอบเขตงาน</option>
                                <option value="budget_increase">เพิ่มงบประมาณ</option>
                                <option value="resource_change">เปลี่ยนแปลงทรัพยากร (อุปกรณ์/บุคลากร)</option>
                                <option value="other">อื่นๆ</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">เหตุผลที่ขอ Extension <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="reason" rows="3" required 
                                      placeholder="อธิบายเหตุผลที่ต้องขอ Extension"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">รายละเอียดการเปลี่ยนแปลงที่ต้องการ <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="requested_changes" rows="4" required 
                                      placeholder="ระบุรายละเอียดสิ่งที่ต้องการเปลี่ยนแปลง"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">วันสิ้นสุดใหม่ (ถ้ามี)</label>
                                <input type="date" class="form-control" name="new_end_date" 
                                       value="<?= e($job['plan_end_date']) ?>">
                                <small class="text-muted">ปัจจุบัน: <?= formatDate($job['plan_end_date']) ?></small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">งบประมาณเพิ่มเติม (บาท)</label>
                                <input type="number" class="form-control" name="additional_budget" 
                                       value="0" min="0" step="0.01">
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-warning">
                                <i class="bi bi-send me-1"></i>ส่งคำขอ Extension
                            </button>
                            <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $jobId ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i>กลับ
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <!-- Job Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-briefcase me-2"></i>ข้อมูลงาน
                </div>
                <div class="card-body">
                    <p><strong>Job:</strong> <?= e($job['job_number']) ?></p>
                    <p><strong>ลูกค้า:</strong> <?= e($job['customer_name']) ?></p>
                    <p><strong>สถานะ:</strong> <span class="badge bg-warning"><?= e($job['status']) ?></span></p>
                    <p><strong>วันเริ่มงาน:</strong> <?= formatDate($job['plan_start_date']) ?></p>
                    <p><strong>วันสิ้นสุด:</strong> <?= formatDate($job['plan_end_date']) ?></p>
                    <p><strong>งบประมาณ:</strong> <?= formatNumber($job['budget']) ?> บาท</p>
                </div>
            </div>
            
            <!-- Extension History -->
            <?php if (!empty($extensions)): ?>
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-clock-history me-2"></i>ประวัติ Extension
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($extensions as $ext): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <strong><?= e($ext['extension_type']) ?></strong>
                            <span class="badge bg-<?= match($ext['status']) {
                                'Pending' => 'warning',
                                'Approved' => 'success',
                                'Rejected' => 'danger',
                                default => 'secondary'
                            } ?>"><?= e($ext['status']) ?></span>
                        </div>
                        <small class="text-muted"><?= formatDateTime($ext['created_at']) ?></small>
                        <p class="small mb-0 mt-1"><?= e(mb_substr($ext['reason'], 0, 100)) ?>...</p>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
