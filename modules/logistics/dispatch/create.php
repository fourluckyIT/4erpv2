<?php
/**
 * Create Dispatch Note
 * ERP v2 - Phase 5
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
$docNum = new DocumentNumber();

$planId = (int) get('plan_id');

if (!$planId) {
    setFlash('error', 'ระบุแผนงาน');
    redirect('../../planning/index.php');
}

// Get Plan info
$plan = $db->query("SELECT * FROM plans WHERE id = $planId")->fetch();
if (!$plan || $plan['status'] !== 'Confirmed') {
    setFlash('error', 'แผนงานไม่ถูกต้อง');
    redirect('../../planning/index.php');
}

// Get Vehicles
$vehicles = $db->query("SELECT id, name, code, plate_number FROM items WHERE item_type = 'Vehicle' AND is_active = 1")->fetchAll();

// Get Drivers
$drivers = $db->query("SELECT id, full_name FROM people WHERE is_active = 1")->fetchAll();

if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("create.php?plan_id=$planId");
    }
    
    try {
        $db->beginTransaction();
        
        $doNumber = $docNum->generate('DO');
        
        $stmt = $db->prepare("
            INSERT INTO dispatch_notes (do_number, plan_id, vehicle_id, driver_id, dispatch_date, status, created_by)
            VALUES (?, ?, ?, ?, ?, 'Draft', ?)
        ");
        
        $vehicleId = post('vehicle_id') ?: null;
        $driverId = post('driver_id') ?: null;
        
        $stmt->execute([
            $doNumber,
            $planId,
            $vehicleId,
            $driverId,
            post('dispatch_date'),
            $_SESSION['user_id']
        ]);
        
        $doId = $db->lastInsertId();
        
        // Auto-add items from Plan?
        // Let's add them all initially, user can edit qty later
        $planItems = $db->query("SELECT * FROM plan_items WHERE plan_id = $planId")->fetchAll();
        
        $stmtItem = $db->prepare("
            INSERT INTO dispatch_items (dispatch_id, plan_item_id, item_id, qty)
            VALUES (?, ?, ?, ?)
        ");
        
        foreach ($planItems as $pi) {
            $stmtItem->execute([
                $doId,
                $pi['id'],
                $pi['item_id'],
                $pi['qty']
            ]);
        }
        
        $db->commit();
        $audit->log('create', 'Dispatch', $doId, null, ['do_number' => $doNumber]);
        
        setFlash('success', "สร้างใบงานเรียบร้อย: $doNumber");
        redirect("view.php?id=$doId");
        
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', $e->getMessage());
        redirect("create.php?plan_id=$planId");
    }
}

$pageTitle = 'สร้างใบงานจัดส่ง - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0"><i class="bi bi-truck me-2"></i>สร้างใบงานจัดส่ง (New Dispatch)</h2>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php">Dispatch</a></li>
                <li class="breadcrumb-item active">สร้างใหม่</li>
            </ol>
        </nav>
    </div>
</div>

<div class="alert alert-info">
    สร้างจากแผนงาน: <strong><?= e($plan['plan_number']) ?></strong> (<?= formatDate($plan['plan_date']) ?>)
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    
    <div class="card">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">วันที่/เวลา จัดส่ง <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" name="dispatch_date" required 
                               value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">ยานพาหนะ (Vehicle)</label>
                        <select class="form-select" name="vehicle_id">
                            <option value="">-- ไม่ระบุ --</option>
                            <?php foreach ($vehicles as $v): ?>
                            <option value="<?= $v['id'] ?>">
                                <?= e($v['code']) ?> - <?= e($v['name']) ?> (<?= e($v['plate_number']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">คนขับ (Driver)</label>
                        <select class="form-select" name="driver_id">
                            <option value="">-- ไม่ระบุ --</option>
                            <?php foreach ($drivers as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= e($d['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="text-end mt-3">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-save me-1"></i>สร้างใบงาน
                </button>
            </div>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
