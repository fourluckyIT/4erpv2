<?php
/**
 * View Dispatch Note
 * ERP v2 - Phase 5
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
$id = (int) get('id');

if (!$id) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get DO
$stmt = $db->prepare("
    SELECT dn.*, p.plan_number, j.job_number, c.name as customer_name,
           v.name as vehicle_name, d.full_name as driver_name
    FROM dispatch_notes dn
    JOIN plans p ON dn.plan_id = p.id
    JOIN jobs j ON p.job_id = j.id
    JOIN customers c ON j.customer_id = c.id
    LEFT JOIN items v ON dn.vehicle_id = v.id
    LEFT JOIN people d ON dn.driver_id = d.id
    WHERE dn.id = ?
");
$stmt->execute([$id]);
$do = $stmt->fetch();

if (!$do) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get items
$items = $db->prepare("
    SELECT di.*, i.code, i.name, i.unit, i.is_serialized
    FROM dispatch_items di
    JOIN items i ON di.item_id = i.id
    WHERE di.dispatch_id = ?
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
    
    if ($action === 'update_serials') {
        // Save serials
        $serials = post('serials', []);
        foreach ($serials as $itemId => $snList) {
            // Filter empty lines
            $snArray = array_filter(explode("\n", $snList));
            $json = !empty($snArray) ? json_encode(array_values($snArray)) : null;
            
            $db->prepare("UPDATE dispatch_items SET serial_numbers = ? WHERE id = ?")
               ->execute([$json, $itemId]);
        }
        setFlash('success', 'บันทึก Serial แล้ว');
        redirect("view.php?id=$id");
        

    } elseif ($action === 'dispatch') {
        // Validate and update serials
        $db->beginTransaction();
        try {
            // Get Plan's Job ID
            $jobId = $do['job_id'] ?? 0;
            if (!$jobId) {
                // If not in $do, fetch it
                $stmt = $db->prepare("SELECT job_id FROM plans WHERE id = ?");
                $stmt->execute([$do['plan_id']]);
                $jobId = $stmt->fetchColumn();
            }

            foreach ($items as $item) {
                if ($item['is_serialized']) {
                    $itemSerials = json_decode($item['serial_numbers'] ?? '[]', true);
                    if (count($itemSerials) > $item['qty']) {
                         throw new Exception("สินค้า {$item['code']} มี Serial เกินจำนวนที่ระบุ");
                    }

                    foreach ($itemSerials as $sn) {
                        // Check status
                        $check = $db->prepare("SELECT id, status FROM serials WHERE serial_number = ? FOR UPDATE");
                        $check->execute([$sn]);
                        $sInfo = $check->fetch();
                        
                        if (!$sInfo) {
                            throw new Exception("ไม่พบ Serial: $sn");
                        }
                        
                        // Strict check: Must be Available
                        // (You might allow 'Allocated' if we had allocation logic, but for now strict)
                        if ($sInfo['status'] !== 'Available') {
                            throw new Exception("Serial $sn ไม่พร้อมใช้งาน (สถานะ: {$sInfo['status']})");
                        }
                        
                        // Update status
                        $db->prepare("
                            UPDATE serials 
                            SET status = 'Dispatched', current_job_id = ?, updated_at = NOW() 
                            WHERE id = ?
                        ")->execute([$jobId, $sInfo['id']]);
                        
                        // Log history? (Maybe in serial_history table later)
                    }
                }
            }
            
            $db->prepare("UPDATE dispatch_notes SET status = 'Dispatched' WHERE id = ?")->execute([$id]);
            $audit->log('dispatch', 'DO', $id);
            $db->commit();
            
            setFlash('success', 'ยืนยันการจัดส่ง (Dispatched) และตัดสต็อก Serial แล้ว');
            redirect("view.php?id=$id");
            
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
            redirect("view.php?id=$id");
        }

        
    } elseif ($action === 'cancel') {
        $db->prepare("UPDATE dispatch_notes SET status = 'Cancelled' WHERE id = ?")->execute([$id]);
        $audit->log('cancel', 'DO', $id);
        redirect("view.php?id=$id");
    }
}

$pageTitle = "DO: {$do['do_number']} - ERP v2";
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-truck me-2"></i><?= e($do['do_number']) ?>
                <span class="badge bg-<?= $do['status'] === 'Dispatched' ? 'success' : 'secondary' ?> ms-2">
                    <?= e($do['status']) ?>
                </span>
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Dispatch</a></li>
                    <li class="breadcrumb-item active"><?= e($do['do_number']) ?></li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary">กลับ</a>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8 mb-4">
        <div class="card mb-4 h-100">
            <div class="card-header">ข้อมูลการจัดส่ง</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="150">Plan / Job</th>
                        <td><?= e($do['plan_number']) ?> / <?= e($do['job_number']) ?></td>
                    </tr>
                    <tr>
                        <th>ลูกค้า</th>
                        <td><?= e($do['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่ส่ง</th>
                        <td><strong><?= formatDateTime($do['dispatch_date']) ?></strong></td>
                    </tr>
                    <tr>
                        <th>รถ / คนขับ</th>
                        <td>
                            <?= e($do['vehicle_name'] ?: '-') ?> / <?= e($do['driver_name'] ?: '-') ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-4 mb-4">
        <div class="card h-100">
            <div class="card-header">การจัดการ</div>
            <div class="card-body">
                <?php if ($do['status'] === 'Draft'): ?>
                <form method="POST" class="d-grid gap-2">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <button type="submit" name="action" value="dispatch" class="btn btn-primary" onclick="return confirm('ยืนยันปล่อยรถ?')">
                        <i class="bi bi-send me-1"></i>ปล่อยรถ (Dispatch)
                    </button>
                    <button type="submit" name="action" value="cancel" class="btn btn-outline-danger">
                        <i class="bi bi-x me-1"></i>ยกเลิก
                    </button>
                </form>
                <?php else: ?>
                <div class="alert alert-info">
                    สถานะ: <?= e($do['status']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="update_serials">
    
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>รายการสินค้า</span>
            <?php if ($do['status'] === 'Draft'): ?>
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-save me-1"></i>บันทึก Serial
            </button>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>รหัส</th>
                            <th>รายการ</th>
                            <th class="text-center">จำนวน</th>
                            <th>Serial Numbers</th>
                            <th>สภาพ/หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $idx => $item): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><?= e($item['code']) ?></td>
                            <td>
                                <?= e($item['name']) ?>
                                <?php if ($item['is_serialized']): ?>
                                <span class="badge bg-info">SN</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center fw-bold"><?= formatNumber($item['qty']) ?></td>
                            <td>
                                <?php 
                                $serials = json_decode($item['serial_numbers'] ?? '[]', true);
                                $serialText = implode("\n", $serials);
                                ?>
                                <?php if ($do['status'] === 'Draft' && $item['is_serialized']): ?>
                                <textarea class="form-control form-control-sm" name="serials[<?= $item['id'] ?>]" rows="3" placeholder="Scan SN here..."><?= e($serialText) ?></textarea>
                                <?php else: ?>
                                <?= nl2br(e($serialText)) ?>
                                <?php endif; ?>
                            </td>
                            <td>-</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
