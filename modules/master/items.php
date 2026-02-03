<?php
/**
 * Item Management
 * 4ERP - Phase 3
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
        $originalCode = sanitize(post('code'));
        $code = ensureUniqueItemCode($db, $originalCode);
        $itemType = post('item_type');
        $vehicleSerial = trim(post('vehicle_serial_number', ''));

        if ($itemType === 'Vehicle') {
            if ($vehicleSerial === '') {
                setFlash('error', 'กรุณากรอกทะเบียนรถ');
                redirect('items.php?action=add');
            }
            $checkSerial = $db->prepare("SELECT id FROM serials WHERE serial_number = ?");
            $checkSerial->execute([$vehicleSerial]);
            if ($checkSerial->fetch()) {
                setFlash('error', 'ทะเบียน/Serial ซ้ำ');
                redirect('items.php?action=add');
            }
        }
        
        try {
            $db->beginTransaction();

            $isSerialized = post('is_serialized') ? 1 : 0;
            if ($itemType === 'Vehicle') {
                $isSerialized = 1;
            }

            $stmt = $db->prepare("
                INSERT INTO items (code, name, item_type, category, brand, model, description, unit, is_serialized, min_stock, cost_price, rental_price_day, sale_price, supplier_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $code, sanitize(post('name')), $itemType,
                sanitize(post('category')), sanitize(post('brand')), sanitize(post('model')),
                post('description'), sanitize(post('unit', $itemType === 'Vehicle' ? 'คัน' : 'pcs')),
                $isSerialized, (int) post('min_stock', 0),
                (float) post('cost_price', 0), (float) post('rental_price_day', 0),
                (float) post('sale_price', 0),
                post('supplier_id') ?: null, $_SESSION['user_id']
            ]);
            
            $newId = $db->lastInsertId();
            $auditData = ['code' => $code, 'type' => $itemType];
            if ($code !== $originalCode) {
                $auditData['base_code'] = $originalCode;
            }
            $audit->log('create', 'ITEM', $newId, null, $auditData);

            if ($itemType === 'Vehicle') {
                $stmt = $db->prepare("
                    INSERT INTO serials (item_id, serial_number, status, location, created_by)
                    VALUES (?, ?, 'Available', 'WH', ?)
                ");
                $stmt->execute([$newId, $vehicleSerial, $_SESSION['user_id']]);
                $serialId = $db->lastInsertId();
                $audit->log('create', 'SERIAL', $serialId, null, ['serial_number' => $vehicleSerial, 'item_id' => $newId]);
            }

            $db->commit();
            setFlash('success', 'สร้างสินค้าเรียบร้อย (รหัส: ' . $code . ')');
            redirect('items.php');
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
            redirect('items.php?action=add');
        }
        
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
$where = 'i.is_active = 1';
$params = [];

if ($typeFilter) {
    $where .= ' AND i.item_type = ?';
    $params[] = $typeFilter;
}
if ($search) {
    $where .= ' AND (i.code LIKE ? OR i.name LIKE ? OR i.brand LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$usageSubquery = "
    SELECT item_id, COUNT(*) as use_count
    FROM stock_movements
    WHERE movement_type = 'GI_JOB'
    GROUP BY item_id
";

$reservedSubquery = "
    SELECT item_id, COALESCE(SUM(qty), 0) as reserved_qty
    FROM reservations
    WHERE status IN ('Reserved', 'Allocated')
    GROUP BY item_id
";

$items = $db->prepare("
    SELECT i.*, s.name as supplier_name,
           COALESCE(ss.serial_count, 0) as serial_count,
           COALESCE(ss.in_use_count, 0) as in_use_count,
           COALESCE(ss.allocated_count, 0) as allocated_count,
           COALESCE(um.use_count, 0) as use_count,
           rq.reserved_qty as reserved_qty
    FROM items i
    LEFT JOIN suppliers s ON i.supplier_id = s.id
    LEFT JOIN (
        SELECT item_id,
               COUNT(*) as serial_count,
               SUM(CASE WHEN status = 'InUse' THEN 1 ELSE 0 END) as in_use_count,
               SUM(CASE WHEN status = 'Allocated' THEN 1 ELSE 0 END) as allocated_count
        FROM serials
        GROUP BY item_id
    ) ss ON ss.item_id = i.id
    LEFT JOIN ($usageSubquery) um ON um.item_id = i.id
    LEFT JOIN ($reservedSubquery) rq ON rq.item_id = i.id
    WHERE $where
    ORDER BY i.item_type, i.code
");
$items->execute($params);
$items = $items->fetchAll();

$usageRows = [];
$usageMax = 0;
if ($action === 'list') {
    $usageRowsStmt = $db->prepare("
        SELECT i.id, i.code, i.name, i.item_type, COALESCE(um.use_count, 0) as use_count
        FROM items i
        LEFT JOIN ($usageSubquery) um ON um.item_id = i.id
        WHERE $where
        ORDER BY use_count DESC, i.code
        LIMIT 8
    ");
    $usageRowsStmt->execute($params);
    $usageRows = $usageRowsStmt->fetchAll();
    foreach ($usageRows as $row) {
        $usageMax = max($usageMax, (int) ($row['use_count'] ?? 0));
    }
}

$pageTitle = 'Items - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-box-seam" style="color: var(--primary);"></i> สินค้า & อุปกรณ์
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
                <li class="breadcrumb-item active">Items</li>
            </ol>
        </nav>
    </div>
    <div class="page-header-actions">
        <?php if ($action === 'list'): ?>
        <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>เพิ่มสินค้า</a>
        <?php else: ?>
        <a href="javascript:history.back()" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>กลับ</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($action === 'list'): ?>
<?php
    $totalItems = count($items);
    $totalSerialUnits = array_sum(array_map(fn($i) => (int) ($i['serial_count'] ?? 0), $items));
    $totalInUseUnits = array_sum(array_map(fn($i) => (int) ($i['in_use_count'] ?? 0), $items));
    $totalReservedUnits = 0;
    foreach ($items as $i) {
        $reserved = $i['reserved_qty'];
        if ($reserved === null) {
            $reserved = (float) ($i['allocated_count'] ?? 0);
        }
        $totalReservedUnits += (float) $reserved;
    }
    $totalReservedDecimals = abs($totalReservedUnits - (int)$totalReservedUnits) > 0.00001 ? 2 : 0;
?>

<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="bi bi-box-seam" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format($totalItems) ?></div>
            <div class="stat-label">รายการทั้งหมด</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info"><i class="bi bi-upc-scan" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format($totalSerialUnits) ?></div>
            <div class="stat-label">Serial ทั้งหมด</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="bi bi-play-circle" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format($totalInUseUnits) ?></div>
            <div class="stat-label">กำลังใช้งาน</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><i class="bi bi-clock" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= formatNumber($totalReservedUnits, $totalReservedDecimals) ?></div>
            <div class="stat-label">ถูกจอง</div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-bar-chart-line me-2"></i>อุปกรณ์ที่ใช้งานบ่อย (ครั้ง)</span>
        <span class="text-muted small">นับจาก GI_JOB</span>
    </div>
    <div class="card-body">
        <?php if (empty($usageRows) || $usageMax === 0): ?>
            <div class="text-muted">ยังไม่มีรายการใช้งาน</div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($usageRows as $row): 
                    $count = (int) ($row['use_count'] ?? 0);
                    $pct = $usageMax > 0 ? round(($count / $usageMax) * 100) : 0;
                ?>
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div class="fw-semibold">
                            <?= e($row['code']) ?> - <?= e($row['name']) ?>
                            <span class="text-muted small">(<?= e($row['item_type']) ?>)</span>
                        </div>
                        <div class="text-muted small"><?= number_format($count) ?> ครั้ง</div>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $pct ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">ค้นหา</label>
                <input type="text" class="form-control" name="search" placeholder="รหัส, ชื่อ, แบรนด์..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">ประเภท</label>
                <select class="form-select" name="type">
                    <option value="">-- ทุกประเภท --</option>
                    <option value="Device" <?= $typeFilter === 'Device' ? 'selected' : '' ?>>Device</option>
                    <option value="Equipment" <?= $typeFilter === 'Equipment' ? 'selected' : '' ?>>Equipment</option>
                    <option value="Vehicle" <?= $typeFilter === 'Vehicle' ? 'selected' : '' ?>>Vehicle</option>
                    <option value="Consumable" <?= $typeFilter === 'Consumable' ? 'selected' : '' ?>>Consumable</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary">ค้นหา</button>
                <a href="items.php" class="btn btn-outline-secondary">ล้าง</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($items)): ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
            <p class="text-muted mt-3">ไม่พบรายการสินค้า</p>
            <a href="?action=add" class="btn btn-primary">เพิ่มสินค้า</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>รหัส</th>
                        <th>ชื่อ</th>
                        <th>ประเภท</th>
                        <th>Brand</th>
                        <th>Serial</th>
                        <th>กำลังใช้งาน</th>
                        <th>จอง</th>
                        <th>ราคาเช่า/วัน</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $i): ?>
                    <?php
                        $reservedQty = $i['reserved_qty'];
                        if ($reservedQty === null) {
                            $reservedQty = (float) ($i['allocated_count'] ?? 0);
                        }
                        $reservedDecimals = abs($reservedQty - (int)$reservedQty) > 0.00001 ? 2 : 0;
                    ?>
                    <tr>
                        <td><strong><?= e($i['code']) ?></strong></td>
                        <td><?= e($i['name']) ?></td>
                        <td>
                            <span class="badge bg-<?= match($i['item_type']) { 'Device' => 'primary', 'Equipment' => 'info', 'Vehicle' => 'warning', default => 'secondary' } ?>">
                                <?= e($i['item_type']) ?>
                            </span>
                        </td>
                        <td><?= e($i['brand'] ?? '') ?></td>
                        <td>
                            <?php if ((int) ($i['is_serialized'] ?? 0) === 1): ?>
                            <span class="badge bg-success"><?= number_format((int) ($i['serial_count'] ?? 0)) ?> units</span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) ($i['is_serialized'] ?? 0) === 1): ?>
                                <?php if ((int) ($i['in_use_count'] ?? 0) > 0): ?>
                                    <span class="badge bg-warning text-dark"><?= number_format((int) $i['in_use_count']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">0</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($reservedQty > 0): ?>
                                <span class="badge bg-warning text-dark"><?= formatNumber($reservedQty, $reservedDecimals) ?></span>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td><?= formatNumber($i['rental_price_day'] ?? 0) ?></td>
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
        <?php endif; ?>
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
                        <label class="form-label">ประเภท <span class="text-danger">*</span></label>
                        <select class="form-select" name="item_type" id="itemTypeSelect" required <?= $action === 'edit' ? 'disabled' : '' ?>>
                            <option value="">-- เลือกประเภทก่อน --</option>
                            <option value="Device" <?= ($item['item_type'] ?? '') === 'Device' ? 'selected' : '' ?>>Device (อุปกรณ์ IT)</option>
                            <option value="Equipment" <?= ($item['item_type'] ?? '') === 'Equipment' ? 'selected' : '' ?>>Equipment (เครื่องมือ)</option>
                            <option value="Vehicle" <?= ($item['item_type'] ?? '') === 'Vehicle' ? 'selected' : '' ?>>Vehicle (ยานพาหนะ)</option>
                            <option value="Consumable" <?= ($item['item_type'] ?? '') === 'Consumable' ? 'selected' : '' ?>>Consumable (วัสดุสิ้นเปลือง)</option>
                        </select>
                        <div class="form-text">เลือกประเภทเพื่อ generate รหัสอัตโนมัติ</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รหัส <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="code" id="itemCodeInput" required 
                                   value="<?= e($item['code'] ?? '') ?>" 
                                   <?= $action === 'edit' ? 'readonly' : '' ?>
                                   placeholder="เลือกประเภทก่อน">
                            <?php if ($action === 'add'): ?>
                            <button type="button" class="btn btn-outline-secondary" id="regenerateCodeBtn" disabled>
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="form-text" id="codeHelpText">รหัสจะถูก generate อัตโนมัติเมื่อเลือกประเภท</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required value="<?= e($item['name'] ?? '') ?>" placeholder="ชื่อสินค้า/อุปกรณ์">
                    </div>
                    <div class="mb-3" id="vehicleFields" style="display: none;">
                        <label class="form-label">ทะเบียนรถ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="vehicle_serial_number" id="vehicleSerialInput"
                               value="<?= e(($action === 'edit' && !empty($serials)) ? ($serials[0]['serial_number'] ?? '') : '') ?>"
                               <?= $action === 'edit' ? 'readonly' : '' ?> placeholder="เช่น กข-1234">
                        <div class="form-text">ทะเบียนรถจะถูกบันทึกเป็น Serial Number</div>
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
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-control" name="category" value="<?= e($item['category'] ?? '') ?>" placeholder="หมวดหมู่ย่อย (ถ้ามี)">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="row">
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">หน่วย</label>
                                <input type="text" class="form-control" name="unit" id="unitInput" value="<?= e($item['unit'] ?? 'pcs') ?>">
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
                <textarea class="form-control" name="description" rows="2" placeholder="รายละเอียดเพิ่มเติม (ถ้ามี)"><?= e($item['description'] ?? '') ?></textarea>
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

<?php if ($action === 'add' || $action === 'edit'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const typeSelect = document.getElementById('itemTypeSelect');
    const vehicleFields = document.getElementById('vehicleFields');
    const vehicleSerialInput = document.getElementById('vehicleSerialInput');
    const serialCheckbox = document.getElementById('isSerialized');
    const unitInput = document.getElementById('unitInput');
    const codeInput = document.getElementById('itemCodeInput');
    const regenerateBtn = document.getElementById('regenerateCodeBtn');
    const codeHelpText = document.getElementById('codeHelpText');
    const isAddMode = <?= $action === 'add' ? 'true' : 'false' ?>;

    async function generateCode(itemType) {
        if (!itemType || !isAddMode) return;
        
        try {
            codeInput.placeholder = 'กำลังสร้างรหัส...';
            const response = await fetch(`<?= BASE_URL ?>/modules/master/api/item_code_generate.php?item_type=${encodeURIComponent(itemType)}`);
            const data = await response.json();
            
            if (data.success) {
                codeInput.value = data.code;
                codeHelpText.innerHTML = `<span class="text-success"><i class="bi bi-check-circle me-1"></i>รหัส ${data.prefix}-XXXX (ลำดับที่ ${data.sequence})</span>`;
                if (regenerateBtn) regenerateBtn.disabled = false;
            } else {
                codeHelpText.innerHTML = `<span class="text-danger">เกิดข้อผิดพลาด: ${data.error}</span>`;
            }
        } catch (error) {
            console.error('Error generating code:', error);
            codeHelpText.innerHTML = `<span class="text-danger">ไม่สามารถสร้างรหัสได้</span>`;
        }
    }

    function syncVehicleFields() {
        const isVehicle = typeSelect && typeSelect.value === 'Vehicle';
        if (vehicleFields) {
            vehicleFields.style.display = isVehicle ? 'block' : 'none';
        }
        if (vehicleSerialInput) {
            vehicleSerialInput.required = isVehicle && !vehicleSerialInput.readOnly;
        }
        if (serialCheckbox && !serialCheckbox.disabled) {
            if (isVehicle) {
                serialCheckbox.checked = true;
                serialCheckbox.disabled = true;
            } else {
                serialCheckbox.disabled = false;
            }
        }
        if (unitInput && isVehicle) {
            unitInput.value = 'คัน';
        }
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', function() {
            syncVehicleFields();
            if (isAddMode && this.value) {
                generateCode(this.value);
            }
        });
        syncVehicleFields();
    }

    if (regenerateBtn) {
        regenerateBtn.addEventListener('click', function() {
            if (typeSelect && typeSelect.value) {
                generateCode(typeSelect.value);
            }
        });
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
