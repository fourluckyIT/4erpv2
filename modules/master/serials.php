<?php
/**
 * Serial Number Management
 * 4ERP - Phase 3
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
$itemId = (int) get('item_id');
$action = get('action', 'list');

// Handle form submissions
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('serials.php' . ($itemId ? "?item_id=$itemId" : ''));
    }
    
    $formAction = post('form_action');
    
    if ($formAction === 'add_serial') {
        $iid = (int) post('item_id');
        $serial = sanitize(post('serial_number'));
        
        // Check duplicate (global)
        $check = $db->prepare("SELECT id FROM serials WHERE serial_number = ?");
        $check->execute([$serial]);
        if ($check->fetch()) {
            setFlash('error', 'Serial number ซ้ำ');
            redirect("serials.php?item_id=$iid");
        }
        
        $stmt = $db->prepare("
            INSERT INTO serials (item_id, serial_number, status, location, purchase_date, purchase_price, warranty_until, created_by)
            VALUES (?, ?, 'Available', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $iid, $serial, sanitize(post('location')),
            post('purchase_date') ?: null, (float) post('purchase_price'),
            post('warranty_until') ?: null, $_SESSION['user_id']
        ]);
        
        $audit->log('create', 'SERIAL', $db->lastInsertId(), null, ['serial' => $serial]);
        setFlash('success', 'เพิ่ม Serial เรียบร้อย');
        redirect("serials.php?item_id=$iid");
        
    } elseif ($formAction === 'update_status') {
        $id = (int) post('id');
        $newStatus = post('status');
        
        $stmt = $db->prepare("UPDATE serials SET status = ?, condition_note = ? WHERE id = ?");
        $stmt->execute([$newStatus, post('condition_note'), $id]);
        
        $audit->log('update_status', 'SERIAL', $id, null, ['status' => $newStatus]);
        setFlash('success', 'อัพเดท status เรียบร้อย');
        redirect("serials.php?item_id=" . post('item_id'));
    }
}

// Get item info
$item = null;
if ($itemId) {
    $stmt = $db->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
}

// List serials
$where = '1=1';
$params = [];
if ($itemId) {
    $where .= ' AND s.item_id = ?';
    $params[] = $itemId;
}

$search = get('search', '');
if ($search) {
    $where .= ' AND s.serial_number LIKE ?';
    $params[] = "%$search%";
}

$statusFilter = get('status_filter', '');
if ($statusFilter) {
    $where .= ' AND s.status = ?';
    $params[] = $statusFilter;
}

$serials = $db->prepare("
    SELECT s.*, i.code as item_code, i.name as item_name 
    FROM serials s 
    JOIN items i ON s.item_id = i.id 
    WHERE $where ORDER BY i.code, s.serial_number
");
$serials->execute($params);
$serials = $serials->fetchAll();

// Items list (for filter)
$items = $db->query("SELECT id, code, name FROM items WHERE is_serialized = 1 AND is_active = 1 ORDER BY code")->fetchAll();

$pageTitle = 'Serial Numbers - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-upc-scan me-2"></i>Serial Numbers</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
                    <li class="breadcrumb-item active">Serials</li>
                </ol>
            </nav>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <select class="form-select" name="item_id">
                    <option value="">-- ทุกสินค้า --</option>
                    <?php foreach ($items as $i): ?>
                    <option value="<?= $i['id'] ?>" <?= $itemId == $i['id'] ? 'selected' : '' ?>>
                        <?= e($i['code']) ?> - <?= e($i['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="text" class="form-control" name="search" placeholder="Serial..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status_filter">
                    <option value="">-- Status --</option>
                    <option value="Available" <?= $statusFilter === 'Available' ? 'selected' : '' ?>>Available</option>
                    <option value="Allocated" <?= $statusFilter === 'Allocated' ? 'selected' : '' ?>>Allocated</option>
                    <option value="Dispatched" <?= $statusFilter === 'Dispatched' ? 'selected' : '' ?>>Dispatched</option>
                    <option value="Damaged" <?= $statusFilter === 'Damaged' ? 'selected' : '' ?>>Damaged</option>
                    <option value="Lost" <?= $statusFilter === 'Lost' ? 'selected' : '' ?>>Lost</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">ค้นหา</button>
                <a href="serials.php" class="btn btn-outline-secondary">ล้าง</a>
            </div>
        </form>
    </div>
</div>

<?php if ($item): ?>
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <i class="bi bi-box me-2"></i><?= e($item['code']) ?> - <?= e($item['name']) ?>
    </div>
    <div class="card-body">
        <button class="btn btn-success btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#addSerialForm">
            <i class="bi bi-plus-circle me-1"></i>เพิ่ม Serial
        </button>
        
        <div class="collapse mt-3" id="addSerialForm">
            <form method="POST" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="form_action" value="add_serial">
                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                <div class="col-md-2">
                    <input type="text" class="form-control form-control-sm" name="serial_number" placeholder="Serial Number" required>
                </div>
                <div class="col-md-2">
                    <input type="text" class="form-control form-control-sm" name="location" placeholder="Location" value="คลังหลัก">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control form-control-sm" name="purchase_date" placeholder="วันซื้อ">
                </div>
                <div class="col-md-2">
                    <input type="number" class="form-control form-control-sm" name="purchase_price" placeholder="ราคา" step="0.01">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control form-control-sm" name="warranty_until" placeholder="หมดประกัน">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary btn-sm">เพิ่ม</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>สินค้า</th>
                        <th>Serial</th>
                        <th>Status</th>
                        <th>Location</th>
                        <th>Warranty</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($serials as $s): ?>
                    <tr>
                        <td>
                            <small class="text-muted"><?= e($s['item_code']) ?></small><br>
                            <?= e($s['item_name']) ?>
                        </td>
                        <td><strong><?= e($s['serial_number']) ?></strong></td>
                        <td>
                            <span class="badge bg-<?= match($s['status']) {
                                'Available' => 'success',
                                'Allocated' => 'info',
                                'Dispatched', 'InUse' => 'warning',
                                'Damaged', 'Lost' => 'danger',
                                default => 'secondary'
                            } ?>">
                                <?= e($s['status']) ?>
                            </span>
                        </td>
                        <td><?= e($s['location']) ?></td>
                        <td>
                            <?php if ($s['warranty_until']): 
                                $expired = $s['warranty_until'] < date('Y-m-d');
                            ?>
                            <small class="<?= $expired ? 'text-danger' : '' ?>">
                                <?= formatDate($s['warranty_until']) ?>
                            </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-secondary" type="button" 
                                    data-bs-toggle="modal" data-bs-target="#statusModal<?= $s['id'] ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            
                            <!-- Status Modal -->
                            <div class="modal fade" id="statusModal<?= $s['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-sm">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="form_action" value="update_status">
                                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                            <input type="hidden" name="item_id" value="<?= $s['item_id'] ?>">
                                            <div class="modal-header">
                                                <h6 class="modal-title"><?= e($s['serial_number']) ?></h6>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label">Status</label>
                                                    <select class="form-select form-select-sm" name="status">
                                                        <?php foreach (['Available', 'Allocated', 'Dispatched', 'InUse', 'Returned', 'Damaged', 'Lost', 'Sold'] as $st): ?>
                                                        <option value="<?= $st ?>" <?= $s['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">หมายเหตุ</label>
                                                    <textarea class="form-control form-control-sm" name="condition_note" rows="2"><?= e($s['condition_note']) ?></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="submit" class="btn btn-primary btn-sm">บันทึก</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
