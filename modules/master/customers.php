<?php
/**
 * Customer Management
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
        redirect('customers.php');
    }
    
    $formAction = post('form_action');
    
    if ($formAction === 'create') {
        $code = sanitize(post('code'));
        $name = sanitize(post('name'));
        
        // Check duplicate code
        $check = $db->prepare("SELECT id FROM customers WHERE code = ?");
        $check->execute([$code]);
        if ($check->fetch()) {
            setFlash('error', 'รหัสลูกค้าซ้ำ');
            redirect('customers.php?action=add');
        }
        
        $stmt = $db->prepare("
            INSERT INTO customers (code, name, contact_name, phone, email, address, tax_id, credit_limit, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $code,
            $name,
            sanitize(post('contact_name')),
            sanitize(post('phone')),
            sanitize(post('email')),
            post('address'),
            sanitize(post('tax_id')),
            (float) post('credit_limit', 0),
            $_SESSION['user_id']
        ]);
        
        $newId = $db->lastInsertId();
        $audit->log('create', 'CUSTOMER', $newId, null, ['code' => $code, 'name' => $name]);
        
        setFlash('success', 'สร้างลูกค้าเรียบร้อย');
        redirect('customers.php');
        
    } elseif ($formAction === 'update') {
        $id = (int) post('id');
        
        // Get old data for audit
        $oldData = $db->prepare("SELECT * FROM customers WHERE id = ?");
        $oldData->execute([$id]);
        $old = $oldData->fetch();
        
        $stmt = $db->prepare("
            UPDATE customers SET 
                name = ?, contact_name = ?, phone = ?, email = ?, 
                address = ?, tax_id = ?, credit_limit = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            sanitize(post('name')),
            sanitize(post('contact_name')),
            sanitize(post('phone')),
            sanitize(post('email')),
            post('address'),
            sanitize(post('tax_id')),
            (float) post('credit_limit', 0),
            $id
        ]);
        
        $audit->log('update', 'CUSTOMER', $id, $old, ['name' => post('name')]);
        
        setFlash('success', 'บันทึกเรียบร้อย');
        redirect('customers.php');
        
    } elseif ($formAction === 'toggle_active') {
        $id = (int) post('id');
        $db->prepare("UPDATE customers SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $audit->log('toggle_active', 'CUSTOMER', $id);
        setFlash('success', 'เปลี่ยนสถานะเรียบร้อย');
        redirect('customers.php');
    }
}

// Get data
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();
    if (!$customer) {
        setFlash('error', 'ไม่พบข้อมูล');
        redirect('customers.php');
    }
    
    // Get sites
    $sitesStmt = $db->prepare("SELECT * FROM sites WHERE customer_id = ? ORDER BY name");
    $sitesStmt->execute([$id]);
    $sites = $sitesStmt->fetchAll();
}

// List
$search = get('search', '');
$showInactive = get('inactive', '0') === '1';

$where = $showInactive ? '1=1' : 'is_active = 1';
$params = [];
if ($search) {
    $where .= ' AND (code LIKE ? OR name LIKE ? OR contact_name LIKE ?)';
    $params = ["%$search%", "%$search%", "%$search%"];
}

$customers = $db->prepare("SELECT * FROM customers WHERE $where ORDER BY code");
$customers->execute($params);
$customers = $customers->fetchAll();

$pageTitle = 'Customers - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-building me-2"></i>ลูกค้า
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
                    <li class="breadcrumb-item active">Customers</li>
                </ol>
            </nav>
        </div>
        <div>
            <?php if ($action === 'list'): ?>
            <a href="?action=add" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>เพิ่มลูกค้า
            </a>
            <?php else: ?>
            <a href="customers.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($action === 'list'): ?>
<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="ค้นหา..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" name="inactive" value="1" id="showInactive" <?= $showInactive ? 'checked' : '' ?>>
                    <label class="form-check-label" for="showInactive">แสดงที่ปิดใช้งาน</label>
                </div>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">ค้นหา</button>
            </div>
        </form>
    </div>
</div>

<!-- List -->
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
                        <th>Sites</th>
                        <th>สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $c): 
                        $siteCount = $db->prepare("SELECT COUNT(*) FROM sites WHERE customer_id = ?");
                        $siteCount->execute([$c['id']]);
                    ?>
                    <tr class="<?= !$c['is_active'] ? 'table-secondary' : '' ?>">
                        <td><strong><?= e($c['code']) ?></strong></td>
                        <td><?= e($c['name']) ?></td>
                        <td><?= e($c['contact_name']) ?></td>
                        <td><?= e($c['phone']) ?></td>
                        <td><span class="badge bg-secondary"><?= $siteCount->fetchColumn() ?></span></td>
                        <td>
                            <?php if ($c['is_active']): ?>
                            <span class="badge bg-success">ใช้งาน</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">ปิดใช้งาน</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?action=edit&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="form_action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" onclick="return confirm('ยืนยัน?')">
                                    <i class="bi bi-toggle-<?= $c['is_active'] ? 'on' : 'off' ?>"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<!-- Add/Edit Form -->
<div class="card">
    <div class="card-header">
        <?= $action === 'add' ? 'เพิ่มลูกค้าใหม่' : 'แก้ไขลูกค้า: ' . e($customer['name']) ?>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="form_action" value="<?= $action === 'add' ? 'create' : 'update' ?>">
            <?php if ($action === 'edit'): ?>
            <input type="hidden" name="id" value="<?= $customer['id'] ?>">
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">รหัสลูกค้า <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="code" required maxlength="20"
                               value="<?= e($customer['code'] ?? '') ?>" <?= $action === 'edit' ? 'readonly' : '' ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อบริษัท <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required
                               value="<?= e($customer['name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อผู้ติดต่อ</label>
                        <input type="text" class="form-control" name="contact_name"
                               value="<?= e($customer['contact_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">โทรศัพท์</label>
                        <input type="text" class="form-control" name="phone"
                               value="<?= e($customer['phone'] ?? '') ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email"
                               value="<?= e($customer['email'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">เลขประจำตัวผู้เสียภาษี</label>
                        <input type="text" class="form-control" name="tax_id"
                               value="<?= e($customer['tax_id'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">วงเงินเครดิต</label>
                        <input type="number" class="form-control" name="credit_limit" step="0.01"
                               value="<?= $customer['credit_limit'] ?? 0 ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ที่อยู่</label>
                        <textarea class="form-control" name="address" rows="3"><?= e($customer['address'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-circle me-1"></i>บันทึก
            </button>
        </form>
    </div>
</div>

<?php if ($action === 'edit' && !empty($sites)): ?>
<div class="card mt-4">
    <div class="card-header">
        <i class="bi bi-geo-alt me-2"></i>Sites ของลูกค้านี้
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ชื่อ Site</th>
                        <th>ที่อยู่</th>
                        <th>ติดต่อ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sites as $site): ?>
                    <tr>
                        <td><?= e($site['name']) ?></td>
                        <td><?= e($site['address']) ?></td>
                        <td><?= e($site['contact_name']) ?> <?= e($site['phone']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <a href="sites.php?action=add&customer_id=<?= $customer['id'] ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-plus-circle me-1"></i>เพิ่ม Site
        </a>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
