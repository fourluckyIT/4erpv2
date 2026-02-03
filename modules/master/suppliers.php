<?php
/**
 * Supplier Management
 * 4ERP - Phase 3
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
$action = get('action', 'list');
$id = (int) get('id');

// Handle form submissions
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('suppliers.php');
    }
    
    $formAction = post('form_action');
    
    if ($formAction === 'create') {
        $code = sanitize(post('code'));
        
        // Check duplicate
        $check = $db->prepare("SELECT id FROM suppliers WHERE code = ?");
        $check->execute([$code]);
        if ($check->fetch()) {
            setFlash('error', 'รหัสผู้ขายซ้ำ');
            redirect('suppliers.php?action=add');
        }
        
        $stmt = $db->prepare("
            INSERT INTO suppliers (code, name, contact_name, phone, email, address, tax_id, payment_terms, bank_name, bank_account, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $code, sanitize(post('name')), sanitize(post('contact_name')),
            sanitize(post('phone')), sanitize(post('email')), post('address'),
            sanitize(post('tax_id')), (int) post('payment_terms', 30),
            sanitize(post('bank_name')), sanitize(post('bank_account')),
            post('notes'), $_SESSION['user_id']
        ]);
        
        $newId = $db->lastInsertId();
        $audit->log('create', 'SUPPLIER', $newId, null, ['code' => $code]);
        setFlash('success', 'สร้างผู้ขายเรียบร้อย');
        redirect('suppliers.php');
        
    } elseif ($formAction === 'update') {
        $id = (int) post('id');
        $stmt = $db->prepare("
            UPDATE suppliers SET 
                name = ?, contact_name = ?, phone = ?, email = ?, address = ?,
                tax_id = ?, payment_terms = ?, bank_name = ?, bank_account = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->execute([
            sanitize(post('name')), sanitize(post('contact_name')),
            sanitize(post('phone')), sanitize(post('email')), post('address'),
            sanitize(post('tax_id')), (int) post('payment_terms', 30),
            sanitize(post('bank_name')), sanitize(post('bank_account')),
            post('notes'), $id
        ]);
        
        $audit->log('update', 'SUPPLIER', $id);
        setFlash('success', 'บันทึกเรียบร้อย');
        redirect('suppliers.php');
        
    } elseif ($formAction === 'toggle_active') {
        $id = (int) post('id');
        $db->prepare("UPDATE suppliers SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $audit->log('toggle_active', 'SUPPLIER', $id);
        redirect('suppliers.php');
    }
}

// Get data
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$id]);
    $supplier = $stmt->fetch();
    if (!$supplier) { setFlash('error', 'ไม่พบข้อมูล'); redirect('suppliers.php'); }
}

$search = get('search', '');
$showInactive = get('inactive', '0') === '1';

$where = $showInactive ? '1=1' : 'is_active = 1';
$params = [];
if ($search) {
    $where .= ' AND (code LIKE ? OR name LIKE ?)';
    $params = ["%$search%", "%$search%"];
}

$suppliers = $db->prepare("SELECT * FROM suppliers WHERE $where ORDER BY code");
$suppliers->execute($params);
$suppliers = $suppliers->fetchAll();

$pageTitle = 'Suppliers - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-truck me-2"></i>ผู้ขาย</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
                    <li class="breadcrumb-item active">Suppliers</li>
                </ol>
            </nav>
        </div>
        <div>
            <?php if ($action === 'list'): ?>
            <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>เพิ่มผู้ขาย</a>
            <?php else: ?>
            <a href="javascript:history.back()" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>กลับ</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($action === 'list'): ?>
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="ค้นหา..." value="<?= e($search) ?>">
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
                        <th>ติดต่อ</th>
                        <th>โทร</th>
                        <th>เครดิต</th>
                        <th>สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suppliers as $s): ?>
                    <tr>
                        <td><strong><?= e($s['code']) ?></strong></td>
                        <td><?= e($s['name']) ?></td>
                        <td><?= e($s['contact_name']) ?></td>
                        <td><?= e($s['phone']) ?></td>
                        <td><?= $s['payment_terms'] ?> วัน</td>
                        <td>
                            <span class="badge bg-<?= $s['is_active'] ? 'success' : 'secondary' ?>">
                                <?= $s['is_active'] ? 'ใช้งาน' : 'ปิด' ?>
                            </span>
                        </td>
                        <td>
                            <a href="?action=edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary">
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
    <div class="card-header"><?= $action === 'add' ? 'เพิ่มผู้ขายใหม่' : 'แก้ไข: ' . e($supplier['name']) ?></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="form_action" value="<?= $action === 'add' ? 'create' : 'update' ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $supplier['id'] ?>"><?php endif; ?>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">รหัส <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="code" required value="<?= e($supplier['code'] ?? '') ?>" <?= $action === 'edit' ? 'readonly' : '' ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required value="<?= e($supplier['name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ผู้ติดต่อ</label>
                        <input type="text" class="form-control" name="contact_name" value="<?= e($supplier['contact_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">โทร</label>
                        <input type="text" class="form-control" name="phone" value="<?= e($supplier['phone'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= e($supplier['email'] ?? '') ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Tax ID</label>
                        <input type="text" class="form-control" name="tax_id" value="<?= e($supplier['tax_id'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">เครดิต (วัน)</label>
                        <input type="number" class="form-control" name="payment_terms" value="<?= $supplier['payment_terms'] ?? 30 ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ธนาคาร</label>
                        <input type="text" class="form-control" name="bank_name" value="<?= e($supplier['bank_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">เลขบัญชี</label>
                        <input type="text" class="form-control" name="bank_account" value="<?= e($supplier['bank_account'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ที่อยู่</label>
                        <textarea class="form-control" name="address" rows="2"><?= e($supplier['address'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">หมายเหตุ</label>
                <textarea class="form-control" name="notes" rows="2"><?= e($supplier['notes'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
