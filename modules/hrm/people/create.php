<?php
/**
 * Create Person
 * 4ERP - HR Module
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();

// Get suppliers for external people
$suppliers = $db->query("SELECT id, code, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('create.php');
    }
    
    try {
        // Generate code
        $peopleType = post('people_type');
        $prefix = $peopleType === 'Employee' ? 'EMP' : 'EXT';
        $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) as max_num FROM people WHERE code LIKE '$prefix-%'");
        $maxNum = $stmt->fetch()['max_num'] ?? 0;
        $code = $prefix . '-' . str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare("
            INSERT INTO people (code, full_name, people_type, position, phone, email, id_card, supplier_id, hire_date, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ");
        
        $stmt->execute([
            $code,
            post('full_name'),
            $peopleType,
            post('position'),
            post('phone'),
            post('email'),
            post('id_card'),
            post('supplier_id') ?: null,
            post('hire_date') ?: null,
            $_SESSION['user_id']
        ]);
        
        $peopleId = $db->lastInsertId();
        
        $audit->log('create', 'PEOPLE', $peopleId, null, ['code' => $code, 'name' => post('full_name')]);
        
        setFlash('success', "สร้างบุคลากรเรียบร้อย: $code");
        redirect("view.php?id=$peopleId");
        
    } catch (Exception $e) {
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
    }
}

$pageTitle = 'เพิ่มบุคลากร - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-person-plus me-2"></i>เพิ่มบุคลากร</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">People</a></li>
                    <li class="breadcrumb-item active">เพิ่มใหม่</li>
                </ol>
            </nav>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
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
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ประเภท <span class="text-danger">*</span></label>
                            <select class="form-select" name="people_type" id="peopleType" required>
                                <option value="Internal">พนักงานประจำ (Internal)</option>
                                <option value="External">แรงงานภายนอก (External)</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ตำแหน่ง</label>
                            <input type="text" class="form-control" name="position">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">บัตรประชาชน</label>
                            <input type="text" class="form-control" name="id_card" maxlength="13">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันที่เริ่มงาน</label>
                            <input type="date" class="form-control" name="hire_date" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">โทรศัพท์</label>
                            <input type="text" class="form-control" name="phone">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">อีเมล</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                    </div>
                    
                    <div id="supplierField" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label">ผู้ขาย/บริษัทต้นสังกัด</label>
                            <select class="form-select" name="supplier_id">
                                <option value="">-- ไม่ระบุ --</option>
                                <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= e($s['code']) ?> - <?= e($s['name']) ?></option>
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
                <a href="index.php" class="btn btn-outline-secondary">ยกเลิก</a>
            </div>
        </div>
    </div>
</form>

<script>
document.getElementById('peopleType').addEventListener('change', function() {
    document.getElementById('supplierField').style.display = this.value === 'External' ? 'block' : 'none';
});
</script>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
