<?php
/**
 * Warehouse Overview
 * ERP v2 - Phase 6
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();

// Get stock summary by item type
$stockSummary = $db->query("
    SELECT 
        i.item_type,
        COUNT(DISTINCT i.id) as item_count,
        SUM(CASE WHEN s.status = 'Available' THEN 1 ELSE 0 END) as available_count,
        SUM(CASE WHEN s.status = 'Allocated' THEN 1 ELSE 0 END) as allocated_count,
        SUM(CASE WHEN s.status = 'InUse' THEN 1 ELSE 0 END) as in_use_count
    FROM items i
    LEFT JOIN serials s ON i.id = s.item_id
    WHERE i.is_active = 1
    GROUP BY i.item_type
")->fetchAll();

// Get recent movements
$recentMovements = [];
try {
    $recentMovements = $db->query("
        SELECT sm.*, i.name as item_name, s.serial_number,
               u.full_name as created_by_name
        FROM stock_movements sm
        LEFT JOIN items i ON sm.item_id = i.id
        LEFT JOIN serials s ON sm.serial_id = s.id
        LEFT JOIN users u ON sm.created_by = u.id
        ORDER BY sm.created_at DESC
        LIMIT 10
    ")->fetchAll();
} catch (PDOException $e) {
    $recentMovements = [];
}

$pageTitle = 'Warehouse Overview - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0"><i class="bi bi-box-seam me-2"></i>Warehouse Overview</h2>
    </div>
</div>

<!-- Stock Summary Cards -->
<div class="row mb-4">
    <?php foreach ($stockSummary as $summary): ?>
    <div class="col-md-3 mb-3">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title">
                    <?php
                    $icon = match($summary['item_type']) {
                        'Device' => 'bi-cpu',
                        'Equipment' => 'bi-tools',
                        'Vehicle' => 'bi-truck',
                        'Consumable' => 'bi-box',
                        default => 'bi-box'
                    };
                    ?>
                    <i class="bi <?= $icon ?> me-2"></i><?= e($summary['item_type']) ?>
                </h5>
                <p class="card-text">
                    <span class="badge bg-secondary"><?= $summary['item_count'] ?> รายการ</span>
                </p>
                <div class="small">
                    <span class="text-success"><i class="bi bi-check-circle"></i> ว่าง: <?= $summary['available_count'] ?? 0 ?></span><br>
                    <span class="text-warning"><i class="bi bi-clock"></i> จอง: <?= $summary['allocated_count'] ?? 0 ?></span><br>
                    <span class="text-primary"><i class="bi bi-play-circle"></i> ใช้งาน: <?= $summary['in_use_count'] ?? 0 ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($stockSummary)): ?>
    <div class="col-12">
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>ยังไม่มีข้อมูลสินค้าในระบบ
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Quick Actions -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-lightning me-2"></i>Quick Actions
            </div>
            <div class="card-body">
                <a href="movements.php" class="btn btn-outline-primary me-2">
                    <i class="bi bi-arrow-left-right me-1"></i>Stock Movements
                </a>
                <a href="receive.php" class="btn btn-outline-success me-2">
                    <i class="bi bi-box-arrow-in-down me-1"></i>WH Receive
                </a>
                <a href="<?= BASE_URL ?>/modules/master/items.php" class="btn btn-outline-secondary">
                    <i class="bi bi-list me-1"></i>Manage Items
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Recent Movements -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2"></i>Recent Stock Movements</span>
                <a href="movements.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body">
                <?php if (empty($recentMovements)): ?>
                <p class="text-muted text-center py-3">ยังไม่มีการเคลื่อนไหวสต็อก</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>วันที่</th>
                                <th>ประเภท</th>
                                <th>สินค้า</th>
                                <th>Serial</th>
                                <th>จำนวน</th>
                                <th>โดย</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentMovements as $m): ?>
                            <tr>
                                <td><?= formatDateTime($m['created_at']) ?></td>
                                <td>
                                    <?php
                                    $badge = 'secondary';
                                    if ((float)($m['qty'] ?? 0) > 0) {
                                        $badge = 'success';
                                    } elseif ((float)($m['qty'] ?? 0) < 0) {
                                        $badge = 'danger';
                                    }
                                    ?>
                                    <span class="badge bg-<?= $badge ?>"><?= e($m['movement_type']) ?></span>
                                </td>
                                <td><?= e($m['item_name'] ?? '-') ?></td>
                                <td><?= e($m['serial_number'] ?? '-') ?></td>
                                <td><?= formatNumber($m['qty'] ?? 0, 0) ?></td>
                                <td><?= e($m['created_by_name'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
