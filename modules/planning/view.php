<?php
/**
 * View Plan
 * ERP v2 - Phase 5
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
$id = (int) get('id');

if (!$id) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get Plan
$stmt = $db->prepare("
    SELECT p.*, j.job_number, j.job_type, c.name as customer_name, u.full_name as creator_name
    FROM plans p
    JOIN jobs j ON p.job_id = j.id
    JOIN customers c ON j.customer_id = c.id
    JOIN users u ON p.created_by = u.id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$plan = $stmt->fetch();

if (!$plan) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get items
$items = $db->prepare("
    SELECT pi.*, i.code, i.name, i.unit
    FROM plan_items pi
    JOIN items i ON pi.item_id = i.id
    WHERE pi.plan_id = ?
");
$items->execute([$id]);
$items = $items->fetchAll();

// Handle Actions
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("view.php?id=$id");
    }
    
    $action = post('action');
    
    if ($action === 'confirm' && $plan['status'] === 'Draft') {
        $db->prepare("UPDATE plans SET status = 'Confirmed' WHERE id = ?")->execute([$id]);
        $audit->log('confirm', 'Plan', $id);
        setFlash('success', 'ยืนยันแผนงานเรียบร้อย');
        redirect("view.php?id=$id");
    } elseif ($action === 'cancel' && $plan['status'] !== 'Cancelled') {
        $db->prepare("UPDATE plans SET status = 'Cancelled' WHERE id = ?")->execute([$id]);
        $audit->log('cancel', 'Plan', $id);
        setFlash('success', 'ยกเลิกแผนงานเรียบร้อย');
        redirect("view.php?id=$id");
    }
}

$pageTitle = "Plan: {$plan['plan_number']} - ERP v2";
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-calendar-check me-2"></i><?= e($plan['plan_number']) ?>
                <span class="badge bg-<?= $plan['status'] === 'Confirmed' ? 'success' : ($plan['status'] === 'Draft' ? 'secondary' : 'danger') ?> ms-2">
                    <?= e($plan['status']) ?>
                </span>
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Planning</a></li>
                    <li class="breadcrumb-item active"><?= e($plan['plan_number']) ?></li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
            
            <?php if ($plan['status'] === 'Confirmed'): ?>
            <a href="../logistics/dispatch/create.php?plan_id=<?= $plan['id'] ?>" class="btn btn-primary ms-2">
                <i class="bi bi-truck me-1"></i>สร้างใบงาน (Dispatch)
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Info -->
<div class="row">
    <div class="col-md-8 mb-4">
        <div class="card h-100">
            <div class="card-header">ข้อมูลแผนงาน</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="150">เลขที่ Job</th>
                        <td><?= e($plan['job_number']) ?> (<?= e($plan['job_type']) ?>)</td>
                    </tr>
                    <tr>
                        <th>ลูกค้า</th>
                        <td><?= e($plan['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่ปฏิบัติงาน</th>
                        <td><strong><?= formatDate($plan['plan_date']) ?></strong></td>
                    </tr>
                    <tr>
                        <th>ผู้สร้าง</th>
                        <td><?= e($plan['creator_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่สร้าง</th>
                        <td><?= formatDateTime($plan['created_at']) ?></td>
                    </tr>
                    <tr>
                        <th>หมายเหตุ</th>
                        <td><?= nl2br(e($plan['notes'])) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-4 mb-4">
        <div class="card h-100">
            <div class="card-header">การจัดการ</div>
            <div class="card-body">
                <?php if ($plan['status'] === 'Draft'): ?>
                <form method="POST" class="d-grid gap-2">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <button type="submit" name="action" value="confirm" class="btn btn-success" onclick="return confirm('ยืนยันแผนงานนี้?')">
                        <i class="bi bi-check-circle me-1"></i>ยืนยันแผนงาน (Confirm)
                    </button>
                    <button type="submit" name="action" value="cancel" class="btn btn-outline-danger" onclick="return confirm('ต้องการยกเลิก?')">
                        <i class="bi bi-x-circle me-1"></i>ยกเลิก (Cancel)
                    </button>
                </form>
                <?php elseif ($plan['status'] === 'Confirmed'): ?>
                <div class="alert alert-success mb-0">
                    <i class="bi bi-check-circle-fill me-2"></i>แผนงานได้รับการยืนยันแล้ว
                    <hr>
                    <small>พร้อมสำหรับการสร้างใบจ่ายงาน (Dispatch)</small>
                </div>
                <?php else: ?>
                <div class="alert alert-secondary mb-0">
                    แผนงานนี้ถูกยกเลิกแล้ว
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Items -->
<div class="card mb-4">
    <div class="card-header">รายการสินค้า/อุปกรณ์ที่ต้องเตรียม</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>รหัส</th>
                        <th>รายการ</th>
                        <th class="text-center">จำนวน</th>
                        <th>หน่วย</th>
                        <th>หมายเหตุ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $item): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= e($item['code']) ?></td>
                        <td><?= e($item['name']) ?></td>
                        <td class="text-center fw-bold"><?= formatNumber($item['qty']) ?></td>
                        <td><?= e($item['unit']) ?></td>
                        <td><?= e($item['notes']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
