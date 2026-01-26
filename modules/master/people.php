<?php
/**
 * People Management (Employees & External Manpower)
 * ERP v2 - Phase 3
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
$action = get('action', 'list');
$id = (int) get('id');
$typeFilter = get('type', '');

// Handle form submissions
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('people.php');
    }
    
    $formAction = post('form_action');
    
    if ($formAction === 'create') {
        $code = sanitize(post('code'));
        
        $check = $db->prepare("SELECT id FROM people WHERE code = ?");
        $check->execute([$code]);
        if ($check->fetch()) {
            setFlash('error', 'รหัสบุคลากรซ้ำ');
            redirect('people.php?action=add');
        }
        
        $stmt = $db->prepare("
            INSERT INTO people (code, full_name, people_type, position, department, phone, email, id_card, address, daily_rate, emergency_contact, emergency_phone, supplier_id, hire_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $code, sanitize(post('full_name')), post('people_type'),
            sanitize(post('position')), sanitize(post('department')),
            sanitize(post('phone')), sanitize(post('email')),
            sanitize(post('id_card')), post('address'),
            (float) post('daily_rate', 0),
            sanitize(post('emergency_contact')), sanitize(post('emergency_phone')),
            post('supplier_id') ?: null, post('hire_date') ?: null,
            $_SESSION['user_id']
        ]);
        
        $newId = $db->lastInsertId();
        $audit->log('create', 'PEOPLE', $newId, null, ['code' => $code, 'type' => post('people_type')]);
        setFlash('success', 'สร้างบุคลากรเรียบร้อย');
        redirect('people.php');
        
    } elseif ($formAction === 'update') {
        $id = (int) post('id');
        $stmt = $db->prepare("
            UPDATE people SET 
                full_name = ?, position = ?, department = ?, phone = ?, email = ?,
                id_card = ?, address = ?, daily_rate = ?,
                emergency_contact = ?, emergency_phone = ?, supplier_id = ?, hire_date = ?
            WHERE id = ?
        ");
        $stmt->execute([
            sanitize(post('full_name')), sanitize(post('position')),
            sanitize(post('department')), sanitize(post('phone')),
            sanitize(post('email')), sanitize(post('id_card')),
            post('address'), (float) post('daily_rate', 0),
            sanitize(post('emergency_contact')), sanitize(post('emergency_phone')),
            post('supplier_id') ?: null, post('hire_date') ?: null,
            $id
        ]);
        
        $audit->log('update', 'PEOPLE', $id);
        setFlash('success', 'บันทึกเรียบร้อย');
        redirect('people.php');
        
    } elseif ($formAction === 'add_cert') {
        $peopleId = (int) post('people_id');
        $stmt = $db->prepare("
            INSERT INTO people_certs (people_id, cert_name, cert_number, issued_by, issued_date, expiry_date)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $peopleId, sanitize(post('cert_name')), sanitize(post('cert_number')),
            sanitize(post('issued_by')), post('issued_date') ?: null, post('expiry_date') ?: null
        ]);
        setFlash('success', 'เพิ่มใบรับรองเรียบร้อย');
        redirect("people.php?action=edit&id=$peopleId");
        
    } elseif ($formAction === 'toggle_active') {
        $id = (int) post('id');
        $db->prepare("UPDATE people SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        redirect('people.php');
    }
}

// Get data
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT p.*, s.name as supplier_name FROM people p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.id = ?");
    $stmt->execute([$id]);
    $person = $stmt->fetch();
    if (!$person) { setFlash('error', 'ไม่พบข้อมูล'); redirect('people.php'); }
    
    // Get certs
    $certs = $db->prepare("SELECT * FROM people_certs WHERE people_id = ? AND is_active = 1 ORDER BY expiry_date");
    $certs->execute([$id]);
    $certs = $certs->fetchAll();
}

// Suppliers dropdown (for external)
$suppliers = $db->query("SELECT id, code, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();

// List
$search = get('search', '');
$where = 'is_active = 1';
$params = [];

if ($typeFilter) {
    $where .= ' AND people_type = ?';
    $params[] = $typeFilter;
}
if ($search) {
    $where .= ' AND (code LIKE ? OR full_name LIKE ? OR position LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$people = $db->prepare("SELECT * FROM people WHERE $where ORDER BY people_type, code");
$people->execute($params);
$people = $people->fetchAll();

$pageTitle = 'People - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-people me-2"></i>บุคลากร</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
                    <li class="breadcrumb-item active">People</li>
                </ol>
            </nav>
        </div>
        <div>
            <?php if ($action === 'list'): ?>
            <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>เพิ่มบุคลากร</a>
            <?php else: ?>
            <a href="people.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>กลับ</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($action === 'list'): ?>
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="ค้นหา..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="type">
                    <option value="">-- ทุกประเภท --</option>
                    <option value="Employee" <?= $typeFilter === 'Employee' ? 'selected' : '' ?>>พนักงาน</option>
                    <option value="External" <?= $typeFilter === 'External' ? 'selected' : '' ?>>แรงงานภายนอก</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">ค้นหา</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>รหัส</th>
                        <th>ชื่อ</th>
                        <th>ประเภท</th>
                        <th>ตำแหน่ง</th>
                        <th>โทร</th>
                        <th>ค่าแรง/วัน</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($people as $p): ?>
                    <tr>
                        <td><strong><?= e($p['code']) ?></strong></td>
                        <td><?= e($p['full_name']) ?></td>
                        <td>
                            <span class="badge bg-<?= $p['people_type'] === 'Employee' ? 'primary' : 'warning' ?>">
                                <?= $p['people_type'] === 'Employee' ? 'พนักงาน' : 'ภายนอก' ?>
                            </span>
                        </td>
                        <td><?= e($p['position']) ?></td>
                        <td><?= e($p['phone']) ?></td>
                        <td><?= $p['daily_rate'] > 0 ? formatNumber($p['daily_rate']) : '-' ?></td>
                        <td>
                            <a href="?action=edit&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card">
    <div class="card-header"><?= $action === 'add' ? 'เพิ่มบุคลากร' : 'แก้ไข: ' . e($person['full_name']) ?></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="form_action" value="<?= $action === 'add' ? 'create' : 'update' ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $person['id'] ?>"><?php endif; ?>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">รหัส <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="code" required value="<?= e($person['code'] ?? '') ?>" <?= $action === 'edit' ? 'readonly' : '' ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" required value="<?= e($person['full_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ประเภท <span class="text-danger">*</span></label>
                        <select class="form-select" name="people_type" required id="peopleType" <?= $action === 'edit' ? 'disabled' : '' ?>>
                            <option value="">-- เลือก --</option>
                            <option value="Employee" <?= ($person['people_type'] ?? '') === 'Employee' ? 'selected' : '' ?>>พนักงาน (Employee)</option>
                            <option value="External" <?= ($person['people_type'] ?? '') === 'External' ? 'selected' : '' ?>>แรงงานภายนอก (External)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ตำแหน่ง</label>
                        <input type="text" class="form-control" name="position" value="<?= e($person['position'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">แผนก</label>
                        <input type="text" class="form-control" name="department" value="<?= e($person['department'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">วันเริ่มงาน</label>
                        <input type="date" class="form-control" name="hire_date" value="<?= $person['hire_date'] ?? '' ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">โทรศัพท์</label>
                        <input type="text" class="form-control" name="phone" value="<?= e($person['phone'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= e($person['email'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">บัตรประชาชน</label>
                        <input type="text" class="form-control" name="id_card" value="<?= e($person['id_card'] ?? '') ?>">
                    </div>
                    <div class="mb-3" id="supplierField" style="<?= ($person['people_type'] ?? '') !== 'External' ? 'display:none' : '' ?>">
                        <label class="form-label">ผู้ขาย (สำหรับแรงงานภายนอก)</label>
                        <select class="form-select" name="supplier_id">
                            <option value="">-- ไม่ระบุ --</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($person['supplier_id'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                <?= e($s['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ค่าแรง/วัน</label>
                        <input type="number" class="form-control" name="daily_rate" step="0.01" value="<?= $person['daily_rate'] ?? 0 ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ผู้ติดต่อฉุกเฉิน</label>
                        <div class="row">
                            <div class="col-7">
                                <input type="text" class="form-control" name="emergency_contact" placeholder="ชื่อ" value="<?= e($person['emergency_contact'] ?? '') ?>">
                            </div>
                            <div class="col-5">
                                <input type="text" class="form-control" name="emergency_phone" placeholder="โทร" value="<?= e($person['emergency_phone'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">ที่อยู่</label>
                <textarea class="form-control" name="address" rows="2"><?= e($person['address'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
        </form>
    </div>
</div>

<?php if ($action === 'edit'): ?>
<div class="card mt-4">
    <div class="card-header">
        <i class="bi bi-award me-2"></i>ใบรับรอง / ใบอนุญาต
    </div>
    <div class="card-body">
        <?php if (!empty($certs)): ?>
        <div class="table-responsive mb-3">
            <table class="table table-sm">
                <thead>
                    <tr><th>ใบรับรอง</th><th>เลขที่</th><th>วันหมดอายุ</th><th>สถานะ</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($certs as $c): 
                        $isExpired = $c['expiry_date'] && $c['expiry_date'] < date('Y-m-d');
                        $isExpiring = $c['expiry_date'] && $c['expiry_date'] < date('Y-m-d', strtotime('+30 days'));
                    ?>
                    <tr>
                        <td><?= e($c['cert_name']) ?></td>
                        <td><?= e($c['cert_number']) ?></td>
                        <td><?= formatDate($c['expiry_date']) ?></td>
                        <td>
                            <?php if ($isExpired): ?>
                            <span class="badge bg-danger">หมดอายุ</span>
                            <?php elseif ($isExpiring): ?>
                            <span class="badge bg-warning text-dark">ใกล้หมดอายุ</span>
                            <?php else: ?>
                            <span class="badge bg-success">ปกติ</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#addCertForm">
            <i class="bi bi-plus-circle me-1"></i>เพิ่มใบรับรอง
        </button>
        
        <div class="collapse mt-3" id="addCertForm">
            <form method="POST" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="form_action" value="add_cert">
                <input type="hidden" name="people_id" value="<?= $person['id'] ?>">
                <div class="col-md-3">
                    <input type="text" class="form-control form-control-sm" name="cert_name" placeholder="ชื่อใบรับรอง" required>
                </div>
                <div class="col-md-2">
                    <input type="text" class="form-control form-control-sm" name="cert_number" placeholder="เลขที่">
                </div>
                <div class="col-md-2">
                    <input type="text" class="form-control form-control-sm" name="issued_by" placeholder="ผู้ออก">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control form-control-sm" name="expiry_date" placeholder="วันหมดอายุ">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm">เพิ่ม</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.getElementById('peopleType')?.addEventListener('change', function() {
    document.getElementById('supplierField').style.display = this.value === 'External' ? '' : 'none';
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
