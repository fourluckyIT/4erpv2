<?php
/**
 * Site Management
 * ERP v2 - Phase 3
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
$action = get('action', 'list');
$id = (int) get('id');
$customerId = (int) get('customer_id');

// Handle form submissions
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('sites.php');
    }
    
    $formAction = post('form_action');
    
    if ($formAction === 'create') {
        $stmt = $db->prepare("
            INSERT INTO sites (customer_id, name, address, contact_name, phone, gps_lat, gps_lng, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int) post('customer_id'),
            sanitize(post('name')),
            post('address'),
            sanitize(post('contact_name')),
            sanitize(post('phone')),
            post('gps_lat') ?: null,
            post('gps_lng') ?: null,
            post('notes'),
            $_SESSION['user_id']
        ]);
        
        $newId = $db->lastInsertId();
        $audit->log('create', 'SITE', $newId);
        setFlash('success', 'สร้าง Site เรียบร้อย');
        redirect('sites.php');
        
    } elseif ($formAction === 'update') {
        $id = (int) post('id');
        $stmt = $db->prepare("
            UPDATE sites SET 
                name = ?, address = ?, contact_name = ?, phone = ?,
                gps_lat = ?, gps_lng = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->execute([
            sanitize(post('name')), post('address'),
            sanitize(post('contact_name')), sanitize(post('phone')),
            post('gps_lat') ?: null, post('gps_lng') ?: null,
            post('notes'), $id
        ]);
        
        $audit->log('update', 'SITE', $id);
        setFlash('success', 'บันทึกเรียบร้อย');
        redirect('sites.php');
    }
}

// Get data
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT s.*, c.name as customer_name FROM sites s JOIN customers c ON s.customer_id = c.id WHERE s.id = ?");
    $stmt->execute([$id]);
    $site = $stmt->fetch();
    if (!$site) { setFlash('error', 'ไม่พบข้อมูล'); redirect('sites.php'); }
}

if ($action === 'add' && $customerId) {
    $customerStmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $customerStmt->execute([$customerId]);
    $preselectedCustomer = $customerStmt->fetch();
}

// Customers dropdown
$customers = $db->query("SELECT id, code, name FROM customers WHERE is_active = 1 ORDER BY name")->fetchAll();

// List
$search = get('search', '');
$filterCustomer = (int) get('filter_customer');

$where = '1=1';
$params = [];
if ($filterCustomer) {
    $where .= ' AND s.customer_id = ?';
    $params[] = $filterCustomer;
}
if ($search) {
    $where .= ' AND (s.name LIKE ? OR c.name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sites = $db->prepare("
    SELECT s.*, c.name as customer_name, c.code as customer_code 
    FROM sites s 
    JOIN customers c ON s.customer_id = c.id 
    WHERE $where ORDER BY c.name, s.name
");
$sites->execute($params);
$sites = $sites->fetchAll();

$pageTitle = 'Sites - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Sites</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
                    <li class="breadcrumb-item active">Sites</li>
                </ol>
            </nav>
        </div>
        <div>
            <?php if ($action === 'list'): ?>
            <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>เพิ่ม Site</a>
            <?php else: ?>
            <a href="sites.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>กลับ</a>
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
                <select class="form-select" name="filter_customer">
                    <option value="">-- ทุกลูกค้า --</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filterCustomer == $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
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
                        <th>ลูกค้า</th>
                        <th>ชื่อ Site</th>
                        <th>ที่อยู่</th>
                        <th>ติดต่อ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sites as $s): ?>
                    <tr>
                        <td>
                            <small class="text-muted"><?= e($s['customer_code']) ?></small><br>
                            <?= e($s['customer_name']) ?>
                        </td>
                        <td><strong><?= e($s['name']) ?></strong></td>
                        <td><small><?= e(mb_substr($s['address'], 0, 50)) ?>...</small></td>
                        <td><?= e($s['contact_name']) ?><br><small><?= e($s['phone']) ?></small></td>
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
    <div class="card-header"><?= $action === 'add' ? 'เพิ่ม Site' : 'แก้ไข: ' . e($site['name']) ?></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="form_action" value="<?= $action === 'add' ? 'create' : 'update' ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $site['id'] ?>"><?php endif; ?>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">ลูกค้า <span class="text-danger">*</span></label>
                        <?php if ($action === 'edit'): ?>
                        <input type="text" class="form-control" value="<?= e($site['customer_name']) ?>" readonly>
                        <?php else: ?>
                        <select class="form-select" name="customer_id" required>
                            <option value="">-- เลือกลูกค้า --</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($preselectedCustomer['id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['code']) ?> - <?= e($c['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อ Site <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required value="<?= e($site['name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ที่อยู่</label>
                        <textarea class="form-control" name="address" rows="3"><?= e($site['address'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">ผู้ติดต่อ</label>
                        <input type="text" class="form-control" name="contact_name" value="<?= e($site['contact_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">โทรศัพท์</label>
                        <input type="text" class="form-control" name="phone" value="<?= e($site['phone'] ?? '') ?>">
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">GPS Lat</label>
                                <input type="text" class="form-control" name="gps_lat" value="<?= e($site['gps_lat'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">GPS Lng</label>
                                <input type="text" class="form-control" name="gps_lng" value="<?= e($site['gps_lng'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" name="notes" rows="2"><?= e($site['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
