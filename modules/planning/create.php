<?php
/**
 * Create Plan
 * ERP v2 - Phase 5
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/Plan.php';

$auth = new Auth();
$auth->requireAuth();

$planModel = new Plan();
$db = getDB();

// Get job_id from URL
$jobId = (int) get('job_id', 0);

if (!$jobId) {
    setFlash('error', 'กรุณาระบุ Job');
    redirect('index.php');
}

// Get job
$stmt = $db->prepare("
    SELECT j.*, c.name as customer_name
    FROM jobs j
    LEFT JOIN customers c ON j.customer_id = c.id
    WHERE j.id = ?
");
$stmt->execute([$jobId]);
$job = $stmt->fetch();

if (!$job) {
    setFlash('error', 'ไม่พบ Job');
    redirect('index.php');
}

if ($job['status'] !== 'Approved') {
    setFlash('error', 'Job ต้องอยู่ในสถานะ Approved เท่านั้นจึงจะสร้าง Plan ได้');
    redirect('index.php');
}

// Check existing plan
$existingPlan = $planModel->getActiveByJobId($jobId);
if ($existingPlan) {
    setFlash('error', 'Job นี้มี Plan ที่ยังใช้งานอยู่แล้ว');
    redirect('view.php?id=' . $existingPlan['id']);
}

// Get available serials
$serials = $db->query("
    SELECT s.*, i.name as item_name, i.code as item_code
    FROM serials s
    JOIN items i ON s.item_id = i.id
    WHERE s.status = 'Available'
    ORDER BY i.name, s.serial_number
")->fetchAll();

// Get active people
$people = $db->query("
    SELECT * FROM people WHERE is_active = 1 ORDER BY full_name
")->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action', '');
    
    if ($action === 'create_plan') {
        $data = [
            'plan_date' => post('plan_date', date('Y-m-d')),
            'notes' => post('notes', '')
        ];
        
        $result = $planModel->create($jobId, $data);
        
        if ($result['success']) {
            // Add serial assignments
            $selectedSerials = post('serials', []);
            foreach ($selectedSerials as $serialId) {
                $planModel->addSerial($result['id'], (int)$serialId);
            }
            
            // Add people assignments
            $selectedPeople = post('people', []);
            foreach ($selectedPeople as $peopleId) {
                $planModel->addPeople($result['id'], (int)$peopleId);
            }
            
            setFlash('success', 'สร้าง Plan สำเร็จ: ' . $result['plan_number']);
            redirect('view.php?id=' . $result['id']);
        } else {
            setFlash('error', $result['error']);
        }
    }
}

$pageTitle = 'สร้าง Plan - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0"><i class="bi bi-plus-circle me-2"></i>สร้าง Plan</h2>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php">Planning</a></li>
                <li class="breadcrumb-item active">สร้างใหม่</li>
            </ol>
        </nav>
    </div>
</div>

<!-- Job Info -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <i class="bi bi-briefcase me-2"></i>ข้อมูล Job
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <strong>Job Number:</strong><br>
                <a href="../jobs/view.php?id=<?= $job['id'] ?>"><?= e($job['job_number']) ?></a>
            </div>
            <div class="col-md-3">
                <strong>ลูกค้า:</strong><br>
                <?= e($job['customer_name']) ?>
            </div>
            <div class="col-md-3">
                <strong>วันเริ่มงาน:</strong><br>
                <?= formatDate($job['plan_start_date']) ?>
            </div>
            <div class="col-md-3">
                <strong>วันสิ้นสุด:</strong><br>
                <?= formatDate($job['plan_end_date']) ?>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-12">
                <strong>รายละเอียด:</strong><br>
                <?= e($job['scope_short']) ?>
            </div>
        </div>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="action" value="create_plan">
    
    <div class="row">
        <!-- Plan Info -->
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-info-circle me-2"></i>ข้อมูล Plan
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">วันที่วางแผน <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="plan_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Serial Selection -->
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-upc-scan me-2"></i>เลือก Serial Numbers
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    <?php if (empty($serials)): ?>
                    <p class="text-muted">ไม่มี Serial ที่ว่าง</p>
                    <?php else: ?>
                    <?php 
                    $currentItem = '';
                    foreach ($serials as $serial): 
                        if ($currentItem !== $serial['item_name']):
                            if ($currentItem !== '') echo '</div>';
                            $currentItem = $serial['item_name'];
                    ?>
                    <div class="mb-2"><strong><?= e($serial['item_code']) ?> - <?= e($serial['item_name']) ?></strong></div>
                    <div class="ms-3">
                    <?php endif; ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="serials[]" value="<?= $serial['id'] ?>" id="serial_<?= $serial['id'] ?>">
                            <label class="form-check-label" for="serial_<?= $serial['id'] ?>">
                                <?= e($serial['serial_number']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($currentItem !== '') echo '</div>'; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- People Selection -->
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-people me-2"></i>เลือกบุคลากร
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    <?php if (empty($people)): ?>
                    <p class="text-muted">ไม่มีบุคลากร</p>
                    <?php else: ?>
                    <?php foreach ($people as $person): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="people[]" value="<?= $person['id'] ?>" id="person_<?= $person['id'] ?>">
                        <label class="form-check-label" for="person_<?= $person['id'] ?>">
                            <strong><?= e($person['code']) ?></strong> - <?= e($person['full_name']) ?>
                            <?php if ($person['position']): ?>
                            <small class="text-muted">(<?= e($person['position']) ?>)</small>
                            <?php endif; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-success btn-lg">
            <i class="bi bi-check-circle me-1"></i>สร้าง Plan
        </button>
        <a href="index.php" class="btn btn-outline-secondary btn-lg">ยกเลิก</a>
    </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
