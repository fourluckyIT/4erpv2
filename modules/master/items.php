<?php
/**
 * Item Management
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
        redirect('items.php');
    }
    
    $formAction = post('form_action');
    
    if ($formAction === 'create') {
        $code = sanitize(post('code'));
        
        $check = $db->prepare("SELECT id FROM items WHERE code = ?");
        $check->execute([$code]);
        if ($check->fetch()) {
            setFlash('error', 'รหัสสินค้าซ้ำ');
            redirect('items.php?action=add');
        }
        
        $stmt = $db->prepare("
            INSERT INTO items (code, name, item_type, category, brand, model, description, unit, is_serialized, min_stock, cost_price, rental_price_day, sale_price, supplier_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $code, sanitize(post('name')), post('item_type'),
            sanitize(post('category')), sanitize(post('brand')), sanitize(post('model')),
            post('description'), sanitize(post('unit', 'pcs')),
            post('is_serialized') ? 1 : 0, (int) post('min_stock', 0),
            (float) post('cost_price', 0), (float) post('rental_price_day', 0),
            (float) post('sale_price', 0),
            post('supplier_id') ?: null, $_SESSION['user_id']
        ]);
        
        $newId = $db->lastInsertId();
        $audit->log('create', 'ITEM', $newId, null, ['code' => $code, 'type' => post('item_type')]);
        setFlash('success', 'สร้างสินค้าเรียบร้อย');
        redirect('items.php');
        
    } elseif ($formAction === 'update') {
        $id = (int) post('id');
        $stmt = $db->prepare("
            UPDATE items SET 
                name = ?, category = ?, brand = ?, model = ?, description = ?,
                unit = ?, is_serialized = ?, min_stock = ?,
                cost_price = ?, rental_price_day = ?, sale_price = ?, supplier_id = ?
            WHERE id = ?
        ");
        $stmt->execute([
            sanitize(post('name')), sanitize(post('category')),
            sanitize(post('brand')), sanitize(post('model')), post('description'),
            sanitize(post('unit')), post('is_serialized') ? 1 : 0,
            (int) post('min_stock', 0), (float) post('cost_price', 0),
            (float) post('rental_price_day', 0), (float) post('sale_price', 0),
            post('supplier_id') ?: null, $id
        ]);
        
        $audit->log('update', 'ITEM', $id);
        setFlash('success', 'บันทึกเรียบร้อย');
        redirect('items.php');
    }
}

// Get data
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    if (!$item) { setFlash('error', 'ไม่พบข้อมูล'); redirect('items.php'); }
    
    // Get serials if serialized
    if ($item['is_serialized']) {
        $serials = $db->prepare("SELECT * FROM serials WHERE item_id = ? ORDER BY serial_number");
        $serials->execute([$id]);
        $serials = $serials->fetchAll();
    }
}

// Suppliers dropdown
$suppliers = $db->query("SELECT id, code, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();

// List
$search = get('search', '');
$where = 'is_active = 1';
$params = [];

if ($typeFilter) {
    $where .= ' AND item_type = ?';
    $params[] = $typeFilter;
}
if ($search) {
    $where .= ' AND (code LIKE ? OR name LIKE ? OR brand LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$items = $db->prepare("SELECT i.*, s.name as supplier_name FROM items i LEFT JOIN suppliers s ON i.supplier_id = s.id WHERE $where ORDER BY i.item_type, i.code");
$items->execute($params);
$items = $items->fetchAll();

$pageTitle = 'Items - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-box-seam me-2"></i>สินค้า & อุปกรณ์</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
                    <li class="breadcrumb-item active">Items</li>
                </ol>
            </nav>
        </div>
        <div>
            <?php if ($action === 'list'): ?>
            <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>เพิ่มสินค้า</a>
            <?php else: ?>
            <a href="items.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>กลับ</a>
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
                    <option value="Device" <?= $typeFilter === 'Device' ? 'selected' : '' ?>>Device</option>
                    <option value="Equipment" <?= $typeFilter === 'Equipment' ? 'selected' : '' ?>>Equipment</option>
                    <option value="Vehicle" <?= $typeFilter === 'Vehicle' ? 'selected' : '' ?>>Vehicle</option>
                    <option value="Consumable" <?= $typeFilter === 'Consumable' ? 'selected' : '' ?>>Consumable</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">ค้นหา</button>
                <a href="items.php" class="btn btn-outline-secondary">ล้าง</a>
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
                        <th>Brand</th>
                        <th>Serial</th>
                        <th>ราคาเช่า/วัน</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $i): 
                        $serialCount = $i['is_serialized'] ? $db->query("SELECT COUNT(*) FROM serials WHERE item_id = {$i['id']}")->fetchColumn() : 0;
                    ?>
                    <tr>
                        <td><strong><?= e($i['code']) ?></strong></td>
                        <td><?= e($i['name']) ?></td>
                        <td>
                            <span class="badge bg-<?= match($i['item_type']) { 'Device' => 'primary', 'Equipment' => 'info', 'Vehicle' => 'warning', default => 'secondary' } ?>">
                                <?= e($i['item_type']) ?>
                            </span>
                        </td>
                        <td><?= e($i['brand']) ?></td>
                        <td>
                            <?php if ($i['is_serialized']): ?>
                            <span class="badge bg-success"><?= $serialCount ?> units</span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?= formatNumber($i['rental_price_day']) ?></td>
                        <td>
                            <a href="?action=edit&id=<?= $i['id'] ?>" class="btn btn-sm btn-outline-primary">
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
    <div class="card-header"><?= $action === 'add' ? 'เพิ่มสินค้า' : 'แก้ไข: ' . e($item['name']) ?></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="form_action" value="<?= $action === 'add' ? 'create' : 'update' ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $item['id'] ?>"><?php endif; ?>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">รหัส <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="code" required value="<?= e($item['code'] ?? '') ?>" <?= $action === 'edit' ? 'readonly' : '' ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required value="<?= e($item['name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ประเภท <span class="text-danger">*</span></label>
                        <select class="form-select" name="item_type" required <?= $action === 'edit' ? 'disabled' : '' ?>>
                            <option value="">-- เลือก --</option>
                            <option value="Device" <?= ($item['item_type'] ?? '') === 'Device' ? 'selected' : '' ?>>Device (อุปกรณ์ IT)</option>
                            <option value="Equipment" <?= ($item['item_type'] ?? '') === 'Equipment' ? 'selected' : '' ?>>Equipment (เครื่องมือ)</option>
                            <option value="Vehicle" <?= ($item['item_type'] ?? '') === 'Vehicle' ? 'selected' : '' ?>>Vehicle (ยานพาหนะ)</option>
                            <option value="Consumable" <?= ($item['item_type'] ?? '') === 'Consumable' ? 'selected' : '' ?>>Consumable (วัสดุสิ้นเปลือง)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-control" name="category" value="<?= e($item['category'] ?? '') ?>">
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">Brand</label>
                                <input type="text" class="form-control" name="brand" value="<?= e($item['brand'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">Model</label>
                                <input type="text" class="form-control" name="model" value="<?= e($item['model'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="row">
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">หน่วย</label>
                                <input type="text" class="form-control" name="unit" value="<?= e($item['unit'] ?? 'pcs') ?>">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">Min Stock</label>
                                <input type="number" class="form-control" name="min_stock" value="<?= $item['min_stock'] ?? 0 ?>">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="is_serialized" id="isSerialized" value="1" 
                               <?= ($item['is_serialized'] ?? 0) ? 'checked' : '' ?> <?= $action === 'edit' ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="isSerialized">Track by Serial Number</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ต้นทุน</label>
                        <input type="number" class="form-control" name="cost_price" step="0.01" value="<?= $item['cost_price'] ?? 0 ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ค่าเช่า/วัน</label>
                        <input type="number" class="form-control" name="rental_price_day" step="0.01" value="<?= $item['rental_price_day'] ?? 0 ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ราคาขาย</label>
                        <input type="number" class="form-control" name="sale_price" step="0.01" value="<?= $item['sale_price'] ?? 0 ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Supplier</label>
                        <select class="form-select" name="supplier_id">
                            <option value="">-- ไม่ระบุ --</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($item['supplier_id'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                <?= e($s['code']) ?> - <?= e($s['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">รายละเอียด</label>
                <textarea class="form-control" name="description" rows="2"><?= e($item['description'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
        </form>
    </div>
</div>

<?php if ($action === 'edit' && !empty($serials)): ?>
<div class="card mt-4">
    <div class="card-header">
        <i class="bi bi-upc-scan me-2"></i>Serial Numbers (<?= count($serials) ?> units)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr><th>Serial</th><th>Status</th><th>Location</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($serials as $sr): ?>
                    <tr>
                        <td><?= e($sr['serial_number']) ?></td>
                        <td>
                            <span class="badge bg-<?= $sr['status'] === 'Available' ? 'success' : ($sr['status'] === 'Damaged' || $sr['status'] === 'Lost' ? 'danger' : 'warning') ?>">
                                <?= e($sr['status']) ?>
                            </span>
                        </td>
                        <td><?= e($sr['location']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <a href="serials.php?item_id=<?= $item['id'] ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-plus-circle me-1"></i>จัดการ Serial
        </a>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
