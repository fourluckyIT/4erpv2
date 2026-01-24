<?php
/**
 * Edit Person
 * ERP v2 - HR Module
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
require_once __DIR__ . '/../../../includes/header.php';
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
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<form method="POST">
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
                                <option value="Internal" <?= $person['people_type'] === 'Employee' ? 'selected' : '' ?>>พนักงานประจำ</option>
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
                            <input type="text" class="form-control" name="position" value="<?= e($person['position']) ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">บัตรประชาชน</label>
                            <input type="text" class="form-control" name="id_card" maxlength="13" value="<?= e($person['id_card']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันที่เริ่มงาน</label>
                            <input type="date" class="form-control" name="hire_date" value="<?= $person['hire_date'] ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">โทรศัพท์</label>
                            <input type="text" class="form-control" name="phone" value="<?= e($person['phone']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">อีเมล</label>
                            <input type="email" class="form-control" name="email" value="<?= e($person['email']) ?>">
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
                <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">ยกเลิก</a>
            </div>
        </div>
    </div>
</form>

<script>
document.getElementById('peopleType').addEventListener('change', function() {
    document.getElementById('supplierField').style.display = this.value === 'External' ? 'block' : 'none';
});
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
