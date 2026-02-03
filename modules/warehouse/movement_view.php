<?php
/**
 * Movement Detail View
 * Warehouse Module - 4ERP
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/WarehouseService.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: WH, ADM, MGR can view
if (!$auth->isAdmin() && !$auth->hasRole(ROLE_WH) && !$auth->hasRole(ROLE_MANAGER)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: /4erpv2/index.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid movement ID');
    header('Location: movements.php');
    exit;
}

$db = getDB();

$stmt = $db->prepare("
    SELECT sm.*, 
           i.name as item_name, i.code as item_code,
           u.full_name as created_by_name,
           rm.id as reversal_id, rm.created_at as reversal_date
    FROM stock_movements sm
    LEFT JOIN items i ON sm.item_id = i.id
    LEFT JOIN users u ON sm.created_by = u.id
    LEFT JOIN stock_movements rm ON rm.reverse_of_id = sm.id
    WHERE sm.id = ?
");
$stmt->execute([$id]);
$movement = $stmt->fetch();

if (!$movement) {
    setFlash('error', 'Movement not found');
    header('Location: movements.php');
    exit;
}

// Check if this is a reversal
$originalMovement = null;
if ($movement['reverse_of_id']) {
    $stmt = $db->prepare("SELECT * FROM stock_movements WHERE id = ?");
    $stmt->execute([$movement['reverse_of_id']]);
    $originalMovement = $stmt->fetch();
}

$pageTitle = 'Movement #' . $id . ' - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-arrow-left-right me-2"></i>Movement #<?= $id ?>
            </h2>
            <p class="text-muted">รายละเอียดการเคลื่อนไหวสินค้า</p>
        </div>
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to List
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>Movement Details
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th style="width: 30%;">Movement ID</th>
                        <td><?= $movement['id'] ?></td>
                    </tr>
                    <tr>
                        <th>Type</th>
                        <td>
                            <span class="badge bg-<?= strpos($movement['movement_type'], 'GI') !== false ? 'danger' : (strpos($movement['movement_type'], 'GR') !== false ? 'success' : 'secondary') ?> fs-6">
                                <?= e($movement['movement_type']) ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Item</th>
                        <td><?= e($movement['item_code']) ?> - <?= e($movement['item_name']) ?></td>
                    </tr>
                    <tr>
                        <th>Quantity</th>
                        <td class="<?= $movement['qty'] > 0 ? 'text-success' : 'text-danger' ?> fs-5">
                            <?= $movement['qty'] > 0 ? '+' : '' ?><?= number_format($movement['qty'], 2) ?>
                        </td>
                    </tr>
                    <tr>
                        <th>From Location</th>
                        <td><?= e($movement['from_location'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>To Location</th>
                        <td><?= e($movement['to_location'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>Reference</th>
                        <td><?= e($movement['reference_table'] ?? '-') ?>:<?= $movement['reference_id'] ?? '-' ?></td>
                    </tr>
                    <tr>
                        <th>Serial ID</th>
                        <td><?= $movement['serial_id'] ?? '-' ?></td>
                    </tr>
                    <tr>
                        <th>Notes</th>
                        <td><?= e($movement['notes'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>Created By</th>
                        <td><?= e($movement['created_by_name'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>Created At</th>
                        <td><?= $movement['created_at'] ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Reversal Info -->
        <?php if ($movement['reversal_id']): ?>
        <div class="card border-warning mb-3">
            <div class="card-header bg-warning text-dark">
                <i class="bi bi-arrow-counterclockwise me-2"></i>Reversed
            </div>
            <div class="card-body">
                <p>This movement was reversed.</p>
                <a href="movement_view.php?id=<?= $movement['reversal_id'] ?>" class="btn btn-sm btn-outline-warning">
                    View Reversal #<?= $movement['reversal_id'] ?>
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($originalMovement): ?>
        <div class="card border-info mb-3">
            <div class="card-header bg-info text-white">
                <i class="bi bi-arrow-return-right me-2"></i>Reversal Of
            </div>
            <div class="card-body">
                <p>This is a reversal of movement #<?= $originalMovement['id'] ?>.</p>
                <a href="movement_view.php?id=<?= $originalMovement['id'] ?>" class="btn btn-sm btn-outline-info">
                    View Original #<?= $originalMovement['id'] ?>
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Actions -->
        <?php if (!$movement['reversal_id'] && !$movement['reverse_of_id'] && ($auth->isAdmin() || $auth->hasRole(ROLE_WH))): ?>
        <div class="card">
            <div class="card-header">
                <i class="bi bi-lightning me-2"></i>Actions
            </div>
            <div class="card-body">
                <p class="text-muted small">Reversal creates a new opposite movement (append-only).</p>
                <form method="POST" action="movement_reverse.php">
                    <input type="hidden" name="movement_id" value="<?= $movement['id'] ?>">
                    <button type="submit" class="btn btn-warning w-100" onclick="return confirm('ต้องการ Reverse movement นี้?')">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reverse Movement
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
