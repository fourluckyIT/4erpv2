<?php
/**
 * Manage Certificate Types (Compliance Requirements)
 * ERP v2 - Settings Module
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

// Only admin can manage
if (!$auth->isAdmin()) {
    setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    redirect(BASE_URL);
}

$db = getDB();
$audit = new AuditLog();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('cert_types.php');
    }
    
    $action = post('action');
    
    if ($action === 'add') {
        // Get first site_id (or null if sites don't exist)
        $siteId = $db->query("SELECT id FROM sites LIMIT 1")->fetchColumn() ?: null;
        
        $stmt = $db->prepare("
            INSERT INTO compliance_requirements (site_id, name, description, requirement_type, validity_days, is_mandatory, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $siteId,
            post('name'),
            post('default_issuer'),
            post('requirement_type'),
            post('validity_days') ?: null,
            post('is_mandatory', 0)
        ]);
        
        $id = $db->lastInsertId();
        $audit->log('create', 'CERT_TYPE', $id);
        setFlash('success', 'เพิ่มประเภทใบรับรองเรียบร้อย');
        
    } elseif ($action === 'edit') {
        $id = (int) post('id');
        $stmt = $db->prepare("
            UPDATE compliance_requirements SET
                name = ?, description = ?, requirement_type = ?, validity_days = ?, is_mandatory = ?
            WHERE id = ?
        ");
        $stmt->execute([
            post('name'),
            post('default_issuer'),
            post('requirement_type'),
            post('validity_days') ?: null,
            post('is_mandatory', 0),
            $id
        ]);
        
        $audit->log('update', 'CERT_TYPE', $id);
        setFlash('success', 'แก้ไขเรียบร้อย');
        
    } elseif ($action === 'toggle') {
        $id = (int) post('id');
        $db->prepare("UPDATE compliance_requirements SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $audit->log('toggle', 'CERT_TYPE', $id);
        setFlash('success', 'เปลี่ยนสถานะเรียบร้อย');
    }
    
    redirect('cert_types.php');
}

// Get all cert types
$certTypes = $db->query("SELECT * FROM compliance_requirements ORDER BY requirement_type, name")->fetchAll();

$categories = ['Certificate', 'Training', 'Equipment', 'Document', 'Other'];

$pageTitle = 'จัดการประเภทใบรับรอง - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-gear me-2"></i>จัดการประเภทใบรับรอง</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">หน้าหลัก</a></li>
                    <li class="breadcrumb-item active">ประเภทใบรับรอง</li>
                </ol>
            </nav>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle me-1"></i>เพิ่มประเภท
        </button>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>ชื่อ</th>
                    <th>สถาบัน/ผู้ออก</th>
                    <th>หมวดหมู่</th>
                    <th class="text-center">อายุ (วัน)</th>
                    <th class="text-center">บังคับ</th>
                    <th class="text-center">สถานะ</th>
                    <th width="100"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($certTypes)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีข้อมูล</td></tr>
                <?php else: ?>
                <?php foreach ($certTypes as $ct): ?>
                <tr class="<?= !$ct['is_active'] ? 'table-secondary' : '' ?>">
                    <td><strong><?= e($ct['name']) ?></strong></td>
                    <td><?= e($ct['description'] ?: '-') ?></td>
                    <td>
                        <span class="badge bg-<?= match($ct['requirement_type'] ?? '') {
                            'Certificate' => 'info',
                            'Training' => 'success',
                            'Equipment' => 'primary',
                            'Document' => 'warning text-dark',
                            default => 'secondary'
                        } ?>">
                            <?= e($ct['requirement_type'] ?? 'Other') ?>
                        </span>
                    </td>
                    <td class="text-center"><?= $ct['validity_days'] ?: '-' ?></td>
                    <td class="text-center">
                        <?php if ($ct['is_mandatory']): ?>
                        <i class="bi bi-check-circle-fill text-success"></i>
                        <?php else: ?>
                        <i class="bi bi-dash text-muted"></i>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($ct['is_active']): ?>
                        <span class="badge bg-success">Active</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="editType(<?= htmlspecialchars(json_encode($ct)) ?>)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $ct['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= $ct['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?>">
                                <i class="bi bi-<?= $ct['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
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

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">เพิ่มประเภทใบรับรอง</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ชื่อใบรับรอง <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">สถาบัน/ผู้ออก (Default)</label>
                        <input type="text" class="form-control" name="default_issuer" placeholder="เช่น กรมพัฒนาฝีมือแรงงาน">
                        <small class="text-muted">จะถูกดึงไปใช้อัตโนมัติเวลาเพิ่มใบรับรอง</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมวดหมู่</label>
                        <select class="form-select" name="requirement_type">
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat ?>"><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">อายุ (วัน)</label>
                        <input type="number" class="form-control" name="validity_days" placeholder="เช่น 365">
                        <small class="text-muted">เว้นว่างถ้าไม่หมดอายุ</small>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_mandatory" value="1" id="isMandatory">
                        <label class="form-check-label" for="isMandatory">บังคับต้องมี</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <div class="modal-header">
                    <h5 class="modal-title">แก้ไขประเภทใบรับรอง</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ชื่อใบรับรอง <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="editName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">สถาบัน/ผู้ออก (Default)</label>
                        <input type="text" class="form-control" name="default_issuer" id="editIssuer" placeholder="เช่น กรมพัฒนาฝีมือแรงงาน">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมวดหมู่</label>
                        <select class="form-select" name="requirement_type" id="editType">
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat ?>"><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">อายุ (วัน)</label>
                        <input type="number" class="form-control" name="validity_days" id="editValidity">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_mandatory" value="1" id="editMandatory">
                        <label class="form-check-label" for="editMandatory">บังคับต้องมี</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editType(data) {
    document.getElementById('editId').value = data.id;
    document.getElementById('editName').value = data.name;
    document.getElementById('editIssuer').value = data.description || '';
    document.getElementById('editType').value = data.requirement_type || 'Other';
    document.getElementById('editValidity').value = data.validity_days || '';
    document.getElementById('editMandatory').checked = data.is_mandatory == 1;
    
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
