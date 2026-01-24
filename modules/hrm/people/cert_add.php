<?php
/**
 * Add/Edit Certificate
 * ERP v2 - HR Module
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();

$peopleId = (int) get('people_id');
$editId = (int) get('edit');

if (!$peopleId) {
    setFlash('error', 'ต้องระบุบุคลากร');
    redirect('index.php');
}

// Get person
$stmt = $db->prepare("SELECT * FROM people WHERE id = ?");
$stmt->execute([$peopleId]);
$person = $stmt->fetch();

if (!$person) {
    setFlash('error', 'ไม่พบบุคลากร');
    redirect('index.php');
}

// Get existing cert if editing
$cert = null;
if ($editId) {
    $stmt = $db->prepare("SELECT * FROM people_certificates WHERE id = ? AND people_id = ?");
    $stmt->execute([$editId, $peopleId]);
    $cert = $stmt->fetch();
}

// Get compliance requirements for dropdown
$requirements = [];
try {
    $requirements = $db->query("SELECT id, name, requirement_type FROM compliance_requirements WHERE is_active = 1 ORDER BY requirement_type, name")->fetchAll();
} catch (PDOException $e) {
    // Table may not have all columns
}

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("cert_add.php?people_id=$peopleId" . ($editId ? "&edit=$editId" : ''));
    }
    
    $action = post('action');
    
    if ($action === 'delete' && $editId) {
        $db->prepare("DELETE FROM people_certificates WHERE id = ? AND people_id = ?")->execute([$editId, $peopleId]);
        $audit->log('delete', 'CERTIFICATE', $editId, $cert);
        setFlash('success', 'ลบใบรับรองเรียบร้อย');
        redirect("view.php?id=$peopleId");
    }
    
    try {
        $certificateType = post('certificate_type');
        
        if ($editId) {
            // Update
            $stmt = $db->prepare("
                UPDATE people_certificates SET
                    certificate_type = ?, certificate_number = ?,
                    issue_date = ?, expiry_date = ?, issuer = ?, notes = ?
                WHERE id = ? AND people_id = ?
            ");
            $stmt->execute([
                $certificateType,
                post('certificate_number'),
                post('issue_date') ?: null,
                post('expiry_date') ?: null,
                post('issuer'),
                post('notes'),
                $editId,
                $peopleId
            ]);
            
            $audit->log('update', 'CERTIFICATE', $editId);
            setFlash('success', 'แก้ไขใบรับรองเรียบร้อย');
        } else {
            // Insert
            $stmt = $db->prepare("
                INSERT INTO people_certificates (
                    people_id, certificate_type, certificate_number,
                    issue_date, expiry_date, issuer, notes, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Valid', ?)
            ");
            $stmt->execute([
                $peopleId,
                $certificateType,
                post('certificate_number'),
                post('issue_date') ?: null,
                post('expiry_date') ?: null,
                post('issuer'),
                post('notes'),
                $_SESSION['user_id']
            ]);
            
            $certId = $db->lastInsertId();
            $audit->log('create', 'CERTIFICATE', $certId);
            setFlash('success', 'เพิ่มใบรับรองเรียบร้อย');
        }
        
        redirect("view.php?id=$peopleId");
        
    } catch (Exception $e) {
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
    }
}

$pageTitle = ($editId ? 'แก้ไข' : 'เพิ่ม') . 'ใบรับรอง - ' . e($person['full_name']);
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0"><i class="bi bi-award me-2"></i><?= $editId ? 'แก้ไข' : 'เพิ่ม' ?>ใบรับรอง/ใบอนุญาต</h2>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php">People</a></li>
                <li class="breadcrumb-item"><a href="view.php?id=<?= $peopleId ?>"><?= e($person['full_name']) ?></a></li>
                <li class="breadcrumb-item active"><?= $editId ? 'แก้ไข' : 'เพิ่ม' ?>ใบรับรอง</li>
            </ol>
        </nav>
    </div>
</div>

<div class="alert alert-info">
    <i class="bi bi-person me-2"></i>
    <strong><?= e($person['code']) ?> - <?= e($person['full_name']) ?></strong>
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    
    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>ข้อมูลใบรับรอง</span>
                    <a href="<?= BASE_URL ?>/modules/settings/cert_types.php" class="btn btn-sm btn-outline-secondary" target="_blank">
                        <i class="bi bi-gear me-1"></i>จัดการประเภทใบรับรอง
                    </a>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">เลือกจากรายการ (ถ้ามี)</label>
                            <select class="form-select" id="requirementSelect">
                                <option value="">-- ระบุเอง --</option>
                                <?php 
                                $currentCat = '';
                                foreach ($requirements as $r): 
                                    if (($r['requirement_type'] ?? '') !== $currentCat) {
                                        if ($currentCat) echo '</optgroup>';
                                        $currentCat = $r['requirement_type'] ?? 'Other';
                                        echo '<optgroup label="' . e($currentCat) . '">';
                                    }
                                ?>
                                <option value="<?= e($r['name']) ?>" <?= ($cert && $cert['certificate_type'] == $r['name']) ? 'selected' : '' ?>>
                                    <?= e($r['name']) ?>
                                </option>
                                <?php endforeach; ?>
                                <?php if ($currentCat) echo '</optgroup>'; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ประเภทใบรับรอง <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="certificate_type" id="certType"
                                   value="<?= e($cert['certificate_type'] ?? '') ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">เลขที่ใบรับรอง</label>
                            <input type="text" class="form-control" name="certificate_number"
                                   value="<?= e($cert['certificate_number'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ออกโดย</label>
                            <input type="text" class="form-control" name="issuer"
                                   value="<?= e($cert['issuer'] ?? '') ?>" placeholder="หน่วยงาน/สถาบัน">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันที่ออก</label>
                            <input type="date" class="form-control" name="issue_date"
                                   value="<?= $cert['issue_date'] ?? '' ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">วันหมดอายุ</label>
                            <input type="date" class="form-control" name="expiry_date"
                                   value="<?= $cert['expiry_date'] ?? '' ?>">
                            <small class="text-muted">เว้นว่างถ้าไม่มีวันหมดอายุ</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" name="notes" rows="2"><?= e($cert['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            
            <div class="mb-4 d-flex justify-content-between">
                <div>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-1"></i>บันทึก
                    </button>
                    <a href="view.php?id=<?= $peopleId ?>" class="btn btn-outline-secondary">ยกเลิก</a>
                </div>
                <?php if ($editId): ?>
                <button type="submit" name="action" value="delete" class="btn btn-outline-danger"
                        onclick="return confirm('ต้องการลบใบรับรองนี้?')">
                    <i class="bi bi-trash me-1"></i>ลบ
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<script>
document.getElementById('requirementSelect').addEventListener('change', function() {
    if (this.value) {
        document.getElementById('certType').value = this.value;
    }
});
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
