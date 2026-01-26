<?php
/**
 * Stock Movements List
 * Warehouse Module - ERP v2
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/WarehouseService.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: WH, ADM, MGR can view
$rbac = new RBAC();
if (!$auth->isAdmin() && !$auth->hasRole(ROLE_WAREHOUSE) && !$auth->hasRole(ROLE_MANAGER)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = getDB();

// Filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$movementType = $_GET['movement_type'] ?? '';
$itemId = $_GET['item_id'] ?? '';

// Build query
$where = ['1=1'];
$params = [];

if ($dateFrom) {
    $where[] = 'sm.created_at >= :date_from';
    $params['date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $where[] = 'sm.created_at <= :date_to';
    $params['date_to'] = $dateTo . ' 23:59:59';
}
if ($movementType) {
    $where[] = 'sm.movement_type = :move_type';
    $params['move_type'] = $movementType;
}
if ($itemId) {
    $where[] = 'sm.item_id = :item_id';
    $params['item_id'] = $itemId;
}

$sql = "SELECT sm.*, i.name as item_name, i.code as item_code, u.full_name as created_by_name
        FROM stock_movements sm
        LEFT JOIN items i ON sm.item_id = i.id
        LEFT JOIN users u ON sm.created_by = u.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY sm.created_at DESC
        LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$movements = $stmt->fetchAll();

// Get items for filter dropdown
$items = $db->query("SELECT id, code, name FROM items ORDER BY name")->fetchAll();

// Get movement types
$moveTypes = $db->query("SELECT DISTINCT movement_type FROM stock_movements ORDER BY movement_type")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Stock Movements - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="bi bi-arrow-left-right me-2"></i>Stock Movements
        </h2>
        <p class="text-muted">ประวัติการเคลื่อนไหวสินค้า</p>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Movement Type</label>
                <select name="movement_type" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($moveTypes as $mt): ?>
                        <option value="<?= e($mt) ?>" <?= $movementType === $mt ? 'selected' : '' ?>><?= e($mt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Item</label>
                <select name="item_id" class="form-select">
                    <option value="">All Items</option>
                    <?php foreach ($items as $item): ?>
                        <option value="<?= $item['id'] ?>" <?= $itemId == $item['id'] ? 'selected' : '' ?>>
                            <?= e($item['code']) ?> - <?= e($item['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search"></i> Filter
                </button>
                <a href="movements.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Movements Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-2"></i>Movements (<?= count($movements) ?>)</span>
        <?php if ($auth->isAdmin() || $auth->hasRole(ROLE_WH)): ?>
        <a href="receive.php" class="btn btn-sm btn-success">
            <i class="bi bi-plus-circle me-1"></i>WH Receive
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Reference</th>
                        <th>By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($movements)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No movements found</td></tr>
                    <?php else: ?>
                        <?php foreach ($movements as $m): ?>
                        <tr>
                            <td><?= $m['id'] ?></td>
                            <td><?= date('d M H:i', strtotime($m['created_at'])) ?></td>
                            <td>
                                <span class="badge bg-<?= strpos($m['movement_type'], 'GI') !== false ? 'danger' : (strpos($m['movement_type'], 'GR') !== false ? 'success' : 'secondary') ?>">
                                    <?= e($m['movement_type']) ?>
                                </span>
                            </td>
                            <td><?= e($m['item_code'] ?? '') ?> - <?= e($m['item_name'] ?? 'N/A') ?></td>
                            <td class="<?= $m['qty'] > 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $m['qty'] > 0 ? '+' : '' ?><?= number_format($m['qty'], 2) ?>
                            </td>
                            <td><?= e($m['from_location'] ?? '-') ?></td>
                            <td><?= e($m['to_location'] ?? '-') ?></td>
                            <td>
                                <?php if ($m['reference_table'] && $m['reference_id']): ?>
                                    <?= e($m['reference_table']) ?>:<?= $m['reference_id'] ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= e($m['created_by_name'] ?? '-') ?></td>
                            <td>
                                <a href="movement_view.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
