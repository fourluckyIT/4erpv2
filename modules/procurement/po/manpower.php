<?php
/**
 * Manpower Registration from PO
 * ERP v2 - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();

$poId = (int) get('po_id');

if (!$poId) {
    setFlash('error', 'ต้องระบุ PO');
    redirect('../po/');
}

// Get PO
$stmt = $db->prepare("
    SELECT po.*, s.name as supplier_name, s.id as supplier_id
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.id = ? AND po.po_type = 'Manpower' AND po.status = 'Approved'
");
$stmt->execute([$poId]);
$po = $stmt->fetch();

if (!$po) {
    setFlash('error', 'ไม่พบ PO แรงงานที่อนุมัติแล้ว');
    redirect('../po/');
}

// Get existing manpower
$existingManpower = $db->prepare("
    SELECT pm.*, p.full_name, p.code as people_code
    FROM po_manpower pm
    JOIN people p ON pm.people_id = p.id
    WHERE pm.po_id = ?
");
$existingManpower->execute([$poId]);
$existingManpower = $existingManpower->fetchAll();

// Get available external people from same supplier
$availablePeople = $db->prepare("
    SELECT p.* FROM people p
    WHERE p.people_type = 'External' 
    AND p.is_active = 1 
    AND (p.supplier_id = ? OR p.supplier_id IS NULL)
    AND p.id NOT IN (SELECT people_id FROM po_manpower WHERE po_id = ?)
    ORDER BY p.full_name
");
$availablePeople->execute([$po['supplier_id'], $poId]);
$availablePeople = $availablePeople->fetchAll();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("manpower.php?po_id=$poId");
    }
    
    $formAction = post('form_action');
    
    if ($formAction === 'add_existing') {
        $peopleId = (int) post('people_id');
        if ($peopleId) {
            $stmt = $db->prepare("
                INSERT INTO po_manpower (po_id, people_id, position, daily_rate, contract_start, contract_end)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $poId,
                $peopleId,
                post('position'),
                (float) post('daily_rate', 0),
                post('contract_start') ?: null,
                post('contract_end') ?: null
            ]);
            
            $audit->log('add_manpower', 'PO', $poId, null, ['people_id' => $peopleId]);
            setFlash('success', 'เพิ่มแรงงานเรียบร้อย');
        }
        
    } elseif ($formAction === 'create_new') {
        // Create new person first
        $docNum = new DocumentNumber();
        $code = 'EXT-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare("
            INSERT INTO people (code, full_name, people_type, position, phone, id_card, daily_rate, supplier_id, hire_date, created_by)
            VALUES (?, ?, 'External', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $code,
            post('full_name'),
            post('position'),
            post('phone'),
            post('id_card'),
            (float) post('daily_rate', 0),
            $po['supplier_id'],
            date('Y-m-d'),
            $_SESSION['user_id']
        ]);
        
        $peopleId = $db->lastInsertId();
        
        // Link to PO
        $stmt = $db->prepare("
            INSERT INTO po_manpower (po_id, people_id, position, daily_rate, contract_start, contract_end)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $poId,
            $peopleId,
            post('position'),
            (float) post('daily_rate', 0),
            post('contract_start') ?: null,
            post('contract_end') ?: null
        ]);
        
        $audit->log('create_manpower', 'PO', $poId, null, ['people_id' => $peopleId, 'name' => post('full_name')]);
        setFlash('success', 'สร้างและเพิ่มแรงงานเรียบร้อย');
        
    } elseif ($formAction === 'remove') {
        $pmId = (int) post('pm_id');
        $db->prepare("DELETE FROM po_manpower WHERE id = ? AND po_id = ?")->execute([$pmId, $poId]);
        setFlash('success', 'ลบออกเรียบร้อย');
    }
    
    redirect("manpower.php?po_id=$poId");
}

$pageTitle = 'ลงทะเบียนแรงงาน - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-people me-2"></i>ลงทะเบียนแรงงาน</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="../po/view.php?id=<?= $poId ?>"><?= e($po['po_number']) ?></a></li>
                    <li class="breadcrumb-item active">Manpower</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="../po/view.php?id=<?= $poId ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ PO
            </a>
        </div>
    </div>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    PO: <strong><?= e($po['po_number']) ?></strong> | ผู้ขาย: <strong><?= e($po['supplier_name']) ?></strong>
</div>

<!-- Existing Manpower -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-people-fill me-2"></i>แรงงานที่ลงทะเบียนแล้ว (<?= count($existingManpower) ?> คน)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>รหัส</th>
                        <th>ชื่อ</th>
                        <th>ตำแหน่ง</th>
                        <th>ค่าแรง/วัน</th>
                        <th>สัญญา</th>
                        <th>สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($existingManpower)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">ยังไม่มีแรงงาน</td></tr>
                    <?php else: ?>
                    <?php foreach ($existingManpower as $mp): ?>
                    <tr>
                        <td><?= e($mp['people_code']) ?></td>
                        <td><strong><?= e($mp['full_name']) ?></strong></td>
                        <td><?= e($mp['position']) ?></td>
                        <td><?= formatNumber($mp['daily_rate']) ?></td>
                        <td>
                            <?= $mp['contract_start'] ? formatDate($mp['contract_start']) : '-' ?>
                            <?= $mp['contract_end'] ? ' ถึง ' . formatDate($mp['contract_end']) : '' ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $mp['status'] === 'Active' ? 'success' : 'secondary' ?>">
                                <?= $mp['status'] ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="form_action" value="remove">
                                <input type="hidden" name="pm_id" value="<?= $mp['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('ลบออก?')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row">
    <!-- Add Existing -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-person-plus me-2"></i>เพิ่มจากรายชื่อเดิม
            </div>
            <div class="card-body">
                <?php if (empty($availablePeople)): ?>
                <p class="text-muted">ไม่มีรายชื่อที่สามารถเพิ่มได้</p>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="form_action" value="add_existing">
                    
                    <div class="mb-3">
                        <label class="form-label">เลือกบุคคล</label>
                        <select class="form-select" name="people_id" required>
                            <option value="">-- เลือก --</option>
                            <?php foreach ($availablePeople as $p): ?>
                            <option value="<?= $p['id'] ?>" data-rate="<?= $p['daily_rate'] ?>">
                                <?= e($p['code']) ?> - <?= e($p['full_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ตำแหน่ง</label>
                        <input type="text" class="form-control" name="position" required>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">เริ่มสัญญา</label>
                                <input type="date" class="form-control" name="contract_start" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">สิ้นสุด</label>
                                <input type="date" class="form-control" name="contract_end">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ค่าแรง/วัน</label>
                        <input type="number" class="form-control" name="daily_rate" step="0.01">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>เพิ่ม
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Create New -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-person-plus-fill me-2"></i>สร้างใหม่
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="form_action" value="create_new">
                    
                    <div class="mb-3">
                        <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" required>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">บัตรประชาชน</label>
                                <input type="text" class="form-control" name="id_card">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">โทร</label>
                                <input type="text" class="form-control" name="phone">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ตำแหน่ง <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="position" required>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">เริ่มสัญญา</label>
                                <input type="date" class="form-control" name="contract_start" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">สิ้นสุด</label>
                                <input type="date" class="form-control" name="contract_end">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ค่าแรง/วัน</label>
                        <input type="number" class="form-control" name="daily_rate" step="0.01">
                    </div>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-person-plus me-1"></i>สร้างและเพิ่ม
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
