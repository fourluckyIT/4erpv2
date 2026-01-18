<?php
/**
 * WH Receive - Record Incoming Stock
 * Warehouse Module - ERP v2
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/WarehouseService.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: WH + ADM only
if (!$auth->isAdmin() && !$auth->hasRole(ROLE_WAREHOUSE)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: /4erpv2/index.php');
    exit;
}

$db = getDB();
$wh = new WarehouseService();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $qty = (float)($_POST['qty'] ?? 0);
    $toLocation = trim($_POST['to_location'] ?? 'WH');
    $referenceTable = trim($_POST['reference_table'] ?? '');
    $referenceId = (int)($_POST['reference_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    if (!$itemId || $qty <= 0) {
        setFlash('error', 'กรุณาเลือกสินค้าและระบุจำนวน');
    } else {
        $result = $wh->recordMovement(
            'GR_WH',      // Goods Receipt to Warehouse
            $itemId,
            $qty,         // Positive for receipt
            'EXTERNAL',   // From external source
            $toLocation,
            $referenceTable ?: 'manual',
            $referenceId ?: 0,
            null,         // serial_id
            null,         // reverse_of_id
            $notes
        );
        
        if ($result['success']) {
            setFlash('success', 'บันทึกการรับสินค้าสำเร็จ Movement ID: ' . $result['id']);
            header('Location: movement_view.php?id=' . $result['id']);
            exit;
        } else {
            setFlash('error', 'Error: ' . ($result['error'] ?? 'Unknown'));
        }
    }
}

// Get items for dropdown
$items = $db->query("SELECT id, code, name, quantity as qty_on_hand FROM items ORDER BY name")->fetchAll();

// Get recent routes for reference
$routes = $db->query("
    SELECT r.id, r.route_number, r.status, r.route_date 
    FROM routes r 
    WHERE r.status IN ('Returned', 'WHReceived')
    ORDER BY r.id DESC LIMIT 20
")->fetchAll();

// Get recent GRs
$grs = $db->query("
    SELECT id, gr_number, received_date 
    FROM goods_receipts 
    ORDER BY id DESC LIMIT 20
")->fetchAll();

$pageTitle = 'WH Receive - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-box-arrow-in-down me-2"></i>WH Receive
            </h2>
            <p class="text-muted">บันทึกการรับสินค้าเข้าคลัง</p>
        </div>
        <a href="movements.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Movements
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-plus-circle me-2"></i>Record Stock Receipt
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Item <span class="text-danger">*</span></label>
                        <select name="item_id" class="form-select" required>
                            <option value="">-- Select Item --</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?= $item['id'] ?>">
                                    <?= e($item['code']) ?> - <?= e($item['name']) ?> 
                                    (Current: <?= number_format($item['qty_on_hand'], 0) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" name="qty" class="form-control" min="0.01" step="0.01" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">To Location</label>
                            <select name="to_location" class="form-select">
                                <option value="WH">WH (Main Warehouse)</option>
                                <option value="WH-A">WH-A</option>
                                <option value="WH-B">WH-B</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reference Type</label>
                            <select name="reference_table" class="form-select" id="refType">
                                <option value="">Manual Entry</option>
                                <option value="goods_receipts">Goods Receipt (GR)</option>
                                <option value="routes">Route Return</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reference ID</label>
                            <input type="number" name="reference_id" class="form-control" id="refId" placeholder="Optional">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes"></textarea>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="movements.php" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-lg me-1"></i>Record Receipt
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header">
                <i class="bi bi-truck me-2"></i>Recent Routes (Returned)
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($routes as $r): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= e($r['route_number']) ?></span>
                        <small class="text-muted">#<?= $r['id'] ?></small>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($routes)): ?>
                    <li class="list-group-item text-muted">No recent routes</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <i class="bi bi-box-seam me-2"></i>Recent Goods Receipts
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($grs as $gr): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= e($gr['gr_number']) ?></span>
                        <small class="text-muted">#<?= $gr['id'] ?></small>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($grs)): ?>
                    <li class="list-group-item text-muted">No recent GRs</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
