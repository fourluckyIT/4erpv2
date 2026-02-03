<?php
/**
 * Edit Person
 * 4ERP - HR Module
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

// Get person
$stmt = $db->prepare("SELECT * FROM people WHERE id = ?");
$stmt->execute([$id]);
$person = $stmt->fetch();

if (!$person) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get suppliers
$suppliers = $db->query("SELECT id, code, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("edit.php?id=$id");
    }
    
    try {
        $oldData = $person;
        
        $stmt = $db->prepare("
            UPDATE people SET 
                full_name = ?, people_type = ?, position = ?, 
                phone = ?, email = ?, id_card = ?, 
                supplier_id = ?, hire_date = ?, is_active = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            post('full_name'),
            post('people_type'),
            post('position'),
            post('phone'),
            post('email'),
            post('id_card'),
            post('supplier_id') ?: null,
            post('hire_date') ?: null,
            post('is_active', 1),
            $id
        ]);
        
        $audit->log('update', 'PEOPLE', $id, $oldData, $_POST);
        
        setFlash('success', 'บันทึกเรียบร้อย');
        redirect("view.php?id=$id");
        
    } catch (Exception $e) {
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
    }
}

$pageTitle = 'แก้ไข - ' . e($person['full_name']);
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-pencil me-2"></i>แก้ไขข้อมูลบุคลากร</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">People</a></li>
                    <li class="breadcrumb-item"><a href="view.php?id=<?= $id ?>"><?= e($person['code']) ?></a></li>
                    <li class="breadcrumb-item active">แก้ไข</li>
                </ol>
            </nav>
        </div>
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<form method="POST" id="editForm" onsubmit="return showChangesConfirm(event)">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    
    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">ข้อมูลบุคลากร</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">รหัส</label>
                            <input type="text" class="form-control" value="<?= e($person['code']) ?>" readonly>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">ประเภท <span class="text-danger">*</span></label>
                            <select class="form-select" name="people_type" id="peopleType" required>
                                <option value="Employee" <?= $person['people_type'] === 'Employee' ? 'selected' : '' ?>>พนักงานประจำ</option>
                                <option value="External" <?= $person['people_type'] === 'External' ? 'selected' : '' ?>>แรงงานภายนอก</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">สถานะ</label>
                            <select class="form-select" name="is_active">
                                <option value="1" <?= $person['is_active'] ? 'selected' : '' ?>>Active</option>
                                <option value="0" <?= !$person['is_active'] ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="full_name" value="<?= e($person['full_name']) ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">ตำแหน่ง</label>
                            <input type="text" class="form-control" name="position" value="<?= e($person['position'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">บัตรประชาชน</label>
                            <input type="text" class="form-control" name="id_card" maxlength="13" value="<?= e($person['id_card'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันที่เริ่มงาน</label>
                            <input type="date" class="form-control" name="hire_date" value="<?= $person['hire_date'] ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">โทรศัพท์</label>
                            <input type="text" class="form-control" name="phone" value="<?= e($person['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">อีเมล</label>
                            <input type="email" class="form-control" name="email" value="<?= e($person['email'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div id="supplierField" style="<?= $person['people_type'] === 'External' ? '' : 'display:none;' ?>">
                        <div class="mb-3">
                            <label class="form-label">ผู้ขาย/บริษัทต้นสังกัด</label>
                            <select class="form-select" name="supplier_id">
                                <option value="">-- ไม่ระบุ --</option>
                                <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $person['supplier_id'] == $s['id'] ? 'selected' : '' ?>>
                                    <?= e($s['code']) ?> - <?= e($s['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mb-4">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle me-1"></i>บันทึก
                </button>
                <a href="javascript:history.back()" class="btn btn-outline-secondary">ยกเลิก</a>
            </div>
        </div>
    </div>
</form>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>ยืนยันการแก้ไข</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>คุณได้ทำการเปลี่ยนแปลงข้อมูลดังนี้:</p>
                <div id="changesList" class="mb-3"></div>
                <p class="text-muted mb-0">ต้องการบันทึกการเปลี่ยนแปลงนี้หรือไม่?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-success" id="confirmSubmit">
                    <i class="bi bi-check-circle me-1"></i>ยืนยันบันทึก
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Store original values
const originalValues = {
    people_type: '<?= e($person['people_type'] ?? '') ?>',
    is_active: '<?= $person['is_active'] ? '1' : '0' ?>',
    full_name: '<?= e($person['full_name'] ?? '') ?>',
    position: '<?= e($person['position'] ?? '') ?>',
    id_card: '<?= e($person['id_card'] ?? '') ?>',
    hire_date: '<?= $person['hire_date'] ?? '' ?>',
    phone: '<?= e($person['phone'] ?? '') ?>',
    email: '<?= e($person['email'] ?? '') ?>',
    supplier_id: '<?= $person['supplier_id'] ?? '' ?>'
};

const fieldLabels = {
    people_type: 'ประเภท',
    is_active: 'สถานะ',
    full_name: 'ชื่อ-นามสกุล',
    position: 'ตำแหน่ง',
    id_card: 'บัตรประชาชน',
    hire_date: 'วันที่เริ่มงาน',
    phone: 'โทรศัพท์',
    email: 'อีเมล',
    supplier_id: 'ผู้ขาย/บริษัท'
};

function getFormValues() {
    const form = document.getElementById('editForm');
    const values = {};
    
    // Map Internal to Employee for comparison
    let peopleType = form.querySelector('[name="people_type"]').value;
    if (peopleType === 'Internal') peopleType = 'Employee';
    values.people_type = peopleType;
    
    values.is_active = form.querySelector('[name="is_active"]').value;
    values.full_name = form.querySelector('[name="full_name"]').value;
    values.position = form.querySelector('[name="position"]').value;
    values.id_card = form.querySelector('[name="id_card"]').value;
    values.hire_date = form.querySelector('[name="hire_date"]').value;
    values.phone = form.querySelector('[name="phone"]').value;
    values.email = form.querySelector('[name="email"]').value;
    values.supplier_id = form.querySelector('[name="supplier_id"]').value;
    
    return values;
}

function getChanges() {
    const newValues = getFormValues();
    const changes = [];
    
    for (const key in originalValues) {
        const oldVal = originalValues[key] || '';
        const newVal = newValues[key] || '';
        
        if (oldVal !== newVal) {
            changes.push({
                field: fieldLabels[key] || key,
                oldValue: oldVal || '(ว่าง)',
                newValue: newVal || '(ว่าง)'
            });
        }
    }
    
    return changes;
}

function showChangesConfirm(event) {
    event.preventDefault();
    
    const changes = getChanges();
    
    if (changes.length === 0) {
        alert('ไม่มีการเปลี่ยนแปลงข้อมูล');
        return false;
    }
    
    // Build changes list HTML
    let html = '<table class="table table-sm table-bordered"><thead class="table-light"><tr><th>ฟิลด์</th><th>ค่าเดิม</th><th>ค่าใหม่</th></tr></thead><tbody>';
    changes.forEach(c => {
        html += `<tr><td><strong>${c.field}</strong></td><td class="text-danger"><del>${c.oldValue}</del></td><td class="text-success">${c.newValue}</td></tr>`;
    });
    html += '</tbody></table>';
    
    document.getElementById('changesList').innerHTML = html;
    
    const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    modal.show();
    
    return false;
}

document.getElementById('confirmSubmit').addEventListener('click', function() {
    document.getElementById('editForm').onsubmit = null;
    document.getElementById('editForm').submit();
});

document.getElementById('peopleType').addEventListener('change', function() {
    document.getElementById('supplierField').style.display = this.value === 'External' ? 'block' : 'none';
});

// Initialize supplier field visibility
document.getElementById('supplierField').style.display = 
    document.getElementById('peopleType').value === 'External' ? 'block' : 'none';
</script>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
