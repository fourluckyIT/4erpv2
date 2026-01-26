<?php
/**
 * Item Types Management
 * 4ERP - Admin
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireRole([ROLE_ADMIN, ROLE_MANAGER]);

$db = getDB();
$audit = new AuditLog();

// Handle form submissions
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('item_types.php');
    }
    
    $action = post('action');
    
    if ($action === 'create') {
        $code = strtoupper(trim(post('code', '')));
        $name = trim(post('name', ''));
        
        if (empty($code) || empty($name)) {
            setFlash('error', 'กรุณาระบุรหัสและชื่อ');
            redirect('item_types.php');
        }
        
        try {
            $stmt = $db->prepare("
                INSERT INTO item_types (code, name, description, icon, color, is_serialized, requires_return, requires_condition_check, show_in_planning, planning_tab_order, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $code,
                $name,
                post('description'),
                post('icon', 'bi-box'),
                post('color', 'secondary'),
                post('is_serialized') ? 1 : 0,
                post('requires_return') ? 1 : 0,
                post('requires_condition_check') ? 1 : 0,
                post('show_in_planning') ? 1 : 0,
                (int) post('planning_tab_order', 99),
                $_SESSION['user_id']
            ]);
            
            $audit->log('create', 'ITEM_TYPE', $db->lastInsertId(), null, ['code' => $code]);
            setFlash('success', "สร้าง Item Type: $code เรียบร้อย");
            
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                setFlash('error', 'รหัสซ้ำ');
            } else {
                setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
            }
        }
        
    } elseif ($action === 'update') {
        $id = (int) post('id');
        $name = trim(post('name', ''));
        
        if (!$id || empty($name)) {
            setFlash('error', 'ข้อมูลไม่ถูกต้อง');
            redirect('item_types.php');
        }
        
        try {
            $stmt = $db->prepare("
                UPDATE item_types SET 
                    name = ?, description = ?, icon = ?, color = ?,
                    is_serialized = ?, requires_return = ?, requires_condition_check = ?,
                    show_in_planning = ?, planning_tab_order = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $name,
                post('description'),
                post('icon', 'bi-box'),
                post('color', 'secondary'),
                post('is_serialized') ? 1 : 0,
                post('requires_return') ? 1 : 0,
                post('requires_condition_check') ? 1 : 0,
                post('show_in_planning') ? 1 : 0,
                (int) post('planning_tab_order', 99),
                $id
            ]);
            
            $audit->log('update', 'ITEM_TYPE', $id, null, ['name' => $name]);
            setFlash('success', 'อัปเดตเรียบร้อย');
            
        } catch (Exception $e) {
            setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
        
    } elseif ($action === 'delete') {
        $id = (int) post('id');
        
        // Check if system type
        $stmt = $db->prepare("SELECT is_system FROM item_types WHERE id = ?");
        $stmt->execute([$id]);
        $type = $stmt->fetch();
        
        if ($type && $type['is_system']) {
            setFlash('error', 'ไม่สามารถลบ System Type ได้');
        } else {
            // Check if in use
            $stmt = $db->prepare("SELECT COUNT(*) FROM items WHERE item_type = (SELECT code FROM item_types WHERE id = ?)");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                setFlash('error', 'ไม่สามารถลบได้ - มีสินค้าใช้งาน Type นี้อยู่');
            } else {
                $db->prepare("DELETE FROM item_types WHERE id = ? AND is_system = 0")->execute([$id]);
                $audit->log('delete', 'ITEM_TYPE', $id);
                setFlash('success', 'ลบเรียบร้อย');
            }
        }
    }
    
    redirect('item_types.php');
}

// Get all item types
$itemTypes = $db->query("SELECT * FROM item_types ORDER BY planning_tab_order, code")->fetchAll();

$pageTitle = 'Item Types - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-tags me-2"></i>Item Types</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
                    <li class="breadcrumb-item active">Item Types</li>
                </ol>
            </nav>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
            <i class="bi bi-plus-circle me-1"></i>เพิ่ม Type ใหม่
        </button>
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
                        <th class="text-center">Icon</th>
                        <th class="text-center">Serial</th>
                        <th class="text-center">ต้องคืน</th>
                        <th class="text-center">ตรวจสภาพ</th>
                        <th class="text-center">แสดงใน Planning</th>
                        <th class="text-center">ลำดับ Tab</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itemTypes as $t): ?>
                    <tr>
                        <td>
                            <strong><?= e($t['code']) ?></strong>
                            <?php if ($t['is_system']): ?>
                            <span class="badge bg-secondary">System</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($t['name']) ?></td>
                        <td class="text-center">
                            <i class="bi <?= e($t['icon']) ?> text-<?= e($t['color']) ?>"></i>
                        </td>
                        <td class="text-center">
                            <?= $t['is_serialized'] ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-dash text-muted"></i>' ?>
                        </td>
                        <td class="text-center">
                            <?= $t['requires_return'] ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-dash text-muted"></i>' ?>
                        </td>
                        <td class="text-center">
                            <?= $t['requires_condition_check'] ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-dash text-muted"></i>' ?>
                        </td>
                        <td class="text-center">
                            <?= $t['show_in_planning'] ? '<span class="badge bg-success">แสดง</span>' : '<span class="badge bg-secondary">ซ่อน</span>' ?>
                        </td>
                        <td class="text-center"><?= $t['planning_tab_order'] ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                    onclick="editType(<?= htmlspecialchars(json_encode($t)) ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if (!$t['is_system']): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันลบ?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>เพิ่ม Item Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">รหัส <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="code" required placeholder="เช่น TOOL">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ชื่อ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">คำอธิบาย</label>
                        <input type="text" class="form-control" name="description">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Icon (Bootstrap Icons)</label>
                            <input type="text" class="form-control" name="icon" value="bi-box" placeholder="bi-box">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">สี</label>
                            <select class="form-select" name="color">
                                <option value="primary">Primary (น้ำเงิน)</option>
                                <option value="secondary" selected>Secondary (เทา)</option>
                                <option value="success">Success (เขียว)</option>
                                <option value="danger">Danger (แดง)</option>
                                <option value="warning">Warning (เหลือง)</option>
                                <option value="info">Info (ฟ้า)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_serialized" id="c_serialized">
                                <label class="form-check-label" for="c_serialized">ต้องมี Serial</label>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="requires_return" id="c_return">
                                <label class="form-check-label" for="c_return">ต้องคืน</label>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="requires_condition_check" id="c_condition">
                                <label class="form-check-label" for="c_condition">ตรวจสภาพ</label>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="show_in_planning" id="c_planning" checked>
                                <label class="form-check-label" for="c_planning">แสดงใน Planning</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ลำดับ Tab</label>
                            <input type="number" class="form-control" name="planning_tab_order" value="99" min="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check me-1"></i>บันทึก
                    </button>
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
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>แก้ไข Item Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">รหัส</label>
                            <input type="text" class="form-control" id="edit_code" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ชื่อ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">คำอธิบาย</label>
                        <input type="text" class="form-control" name="description" id="edit_description">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Icon</label>
                            <input type="text" class="form-control" name="icon" id="edit_icon">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">สี</label>
                            <select class="form-select" name="color" id="edit_color">
                                <option value="primary">Primary</option>
                                <option value="secondary">Secondary</option>
                                <option value="success">Success</option>
                                <option value="danger">Danger</option>
                                <option value="warning">Warning</option>
                                <option value="info">Info</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_serialized" id="edit_serialized">
                                <label class="form-check-label" for="edit_serialized">ต้องมี Serial</label>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="requires_return" id="edit_return">
                                <label class="form-check-label" for="edit_return">ต้องคืน</label>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="requires_condition_check" id="edit_condition">
                                <label class="form-check-label" for="edit_condition">ตรวจสภาพ</label>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="show_in_planning" id="edit_planning">
                                <label class="form-check-label" for="edit_planning">แสดงใน Planning</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">ลำดับ Tab</label>
                            <input type="number" class="form-control" name="planning_tab_order" id="edit_tab_order" min="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check me-1"></i>บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editType(t) {
    document.getElementById('edit_id').value = t.id;
    document.getElementById('edit_code').value = t.code;
    document.getElementById('edit_name').value = t.name;
    document.getElementById('edit_description').value = t.description || '';
    document.getElementById('edit_icon').value = t.icon || 'bi-box';
    document.getElementById('edit_color').value = t.color || 'secondary';
    document.getElementById('edit_serialized').checked = t.is_serialized == 1;
    document.getElementById('edit_return').checked = t.requires_return == 1;
    document.getElementById('edit_condition').checked = t.requires_condition_check == 1;
    document.getElementById('edit_planning').checked = t.show_in_planning == 1;
    document.getElementById('edit_tab_order').value = t.planning_tab_order || 99;
    
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
