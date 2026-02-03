<?php
/**
 * Warehouse Overview
 * 4ERP - Phase 6
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

// Totals for hero stats
$totals = [
    'items' => 0,
    'available' => 0,
    'allocated' => 0,
    'in_use' => 0
];
foreach ($stockSummary as $s) {
    $totals['items'] += (int) ($s['item_count'] ?? 0);
    $totals['available'] += (int) ($s['available_count'] ?? 0);
    $totals['allocated'] += (int) ($s['allocated_count'] ?? 0);
    $totals['in_use'] += (int) ($s['in_use_count'] ?? 0);
}

// Get recent movements
$recentMovements = [];
try {
    $recentMovements = $db->query("
        SELECT sm.*, i.name as item_name, s.serial_number, s.status as serial_status,
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

$pageTitle = 'Warehouse Overview - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<style>
    .wh-hero {
        border: 0;
        background: linear-gradient(135deg, #e6f6ff 0%, #eefcf5 50%, #f7f7ff 100%);
        overflow: hidden;
    }
    .wh-hero-grid {
        display: grid;
        grid-template-columns: 1.2fr 1fr;
        gap: 24px;
        align-items: center;
    }
    .wh-hero-title {
        font-size: 1.6rem;
        font-weight: 700;
        margin-bottom: 6px;
    }
    .wh-hero-sub {
        color: var(--gray-600);
        margin-bottom: 16px;
    }
    .wh-hero-stats {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }
    .wh-stat-chip {
        background: var(--white);
        border: 1px solid var(--gray-200);
        border-radius: 999px;
        padding: 6px 12px;
        font-size: 0.85rem;
        color: var(--gray-700);
        display: inline-flex;
        align-items: center;
        gap: 6px;
        box-shadow: var(--shadow-sm);
    }
    .wh-stat-chip span {
        font-weight: 700;
        color: var(--gray-900);
    }
    .wh-hero-actions {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
    }
    .wh-action {
        background: var(--white);
        border: 1px solid var(--gray-200);
        border-radius: 14px;
        padding: 14px 12px;
        text-align: left;
        text-decoration: none;
        color: var(--gray-800);
        transition: all 0.2s ease;
        box-shadow: var(--shadow-sm);
    }
    .wh-action:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }
    .wh-action .wh-action-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 8px;
        font-size: 1.1rem;
    }
    .wh-action-title {
        font-weight: 600;
        font-size: 0.95rem;
    }
    .wh-summary-card {
        border: 0;
        border-radius: 16px;
        box-shadow: var(--shadow-md);
        overflow: hidden;
    }
    .wh-summary-card .card-body {
        padding: 18px;
    }
    .wh-summary-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
    }
    .wh-summary-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        color: var(--white);
    }
    .wh-summary-title {
        font-weight: 600;
        font-size: 1rem;
        color: var(--gray-800);
    }
    .wh-summary-count {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--gray-900);
    }
    .wh-summary-meta {
        display: grid;
        gap: 6px;
        font-size: 0.85rem;
        color: var(--gray-600);
    }
    .wh-summary-meta b {
        color: var(--gray-900);
    }
    .wh-card-blue { background: linear-gradient(180deg, #f2f7ff 0%, #ffffff 100%); }
    .wh-card-amber { background: linear-gradient(180deg, #fff7ed 0%, #ffffff 100%); }
    .wh-card-teal { background: linear-gradient(180deg, #ecfeff 0%, #ffffff 100%); }
    .wh-card-purple { background: linear-gradient(180deg, #f5f3ff 0%, #ffffff 100%); }
    .wh-icon-blue { background: #3b82f6; }
    .wh-icon-amber { background: #f59e0b; }
    .wh-icon-teal { background: #14b8a6; }
    .wh-icon-purple { background: #8b5cf6; }
    .wh-movement-table thead th {
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--gray-500);
    }
    .wh-movement-pill {
        padding: 4px 8px;
        border-radius: 999px;
        font-weight: 600;
        font-size: 0.75rem;
    }
    .wh-reserved-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-left: 6px;
        padding: 2px 6px;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 600;
        color: #9a3412;
        background: #fff7ed;
        border: 1px solid #fed7aa;
    }
    @media (max-width: 992px) {
        .wh-hero-grid {
            grid-template-columns: 1fr;
        }
        .wh-hero-actions {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="card wh-hero mb-4">
    <div class="card-body">
        <div class="wh-hero-grid">
            <div>
                <div class="wh-hero-title"><i class="bi bi-box-seam me-2"></i>Warehouse Overview</div>
                <div class="wh-hero-sub">ภาพรวมสต็อก, สถานะการใช้งาน, และการเคลื่อนไหวล่าสุดของคลัง</div>
                <div class="wh-hero-stats">
                    <div class="wh-stat-chip"><i class="bi bi-grid"></i> รายการทั้งหมด <span><?= number_format($totals['items']) ?></span></div>
                    <div class="wh-stat-chip"><i class="bi bi-check-circle text-success"></i> ว่าง <span><?= number_format($totals['available']) ?></span></div>
                    <div class="wh-stat-chip"><i class="bi bi-clock text-warning"></i> จอง <span><?= number_format($totals['allocated']) ?></span></div>
                    <div class="wh-stat-chip"><i class="bi bi-play-circle text-primary"></i> ใช้งาน <span><?= number_format($totals['in_use']) ?></span></div>
                </div>
            </div>
            <div class="wh-hero-actions">
                <a href="movements.php" class="wh-action">
                    <div class="wh-action-icon" style="background:#e0f2fe;color:#0369a1;">
                        <i class="bi bi-arrow-left-right"></i>
                    </div>
                    <div class="wh-action-title">Stock Movements</div>
                    <div class="text-muted small">ดูประวัติรับเข้า/จ่ายออก</div>
                </a>
                <a href="receive.php" class="wh-action">
                    <div class="wh-action-icon" style="background:#dcfce7;color:#166534;">
                        <i class="bi bi-box-arrow-in-down"></i>
                    </div>
                    <div class="wh-action-title">WH Receive</div>
                    <div class="text-muted small">รับของเข้าคลังอย่างรวดเร็ว</div>
                </a>
                <a href="<?= BASE_URL ?>/modules/master/items.php" class="wh-action">
                    <div class="wh-action-icon" style="background:#ede9fe;color:#5b21b6;">
                        <i class="bi bi-list"></i>
                    </div>
                    <div class="wh-action-title">Manage Items</div>
                    <div class="text-muted small">จัดการรายการและสเปคสินค้า</div>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Stock Summary Cards -->
<div class="row mb-4">
    <?php foreach ($stockSummary as $summary): ?>
    <div class="col-md-3 mb-3">
        <?php
        $icon = match($summary['item_type']) {
            'Device' => 'bi-cpu',
            'Equipment' => 'bi-tools',
            'Vehicle' => 'bi-truck',
            'Consumable' => 'bi-box',
            default => 'bi-box'
        };
        $tone = match($summary['item_type']) {
            'Device' => 'blue',
            'Equipment' => 'amber',
            'Vehicle' => 'teal',
            'Consumable' => 'purple',
            default => 'blue'
        };
        ?>
        <div class="card wh-summary-card wh-card-<?= $tone ?> h-100">
            <div class="card-body">
                <div class="wh-summary-header">
                    <div class="wh-summary-title"><?= e($summary['item_type']) ?></div>
                    <div class="wh-summary-icon wh-icon-<?= $tone ?>">
                        <i class="bi <?= $icon ?>"></i>
                    </div>
                </div>
                <div class="wh-summary-count"><?= number_format($summary['item_count'] ?? 0) ?></div>
                <div class="text-muted small mb-2">รายการทั้งหมด</div>
                <div class="wh-summary-meta">
                    <div><i class="bi bi-check-circle text-success"></i> ว่าง: <b><?= number_format($summary['available_count'] ?? 0) ?></b></div>
                    <div><i class="bi bi-clock text-warning"></i> จอง: <b><?= number_format($summary['allocated_count'] ?? 0) ?></b></div>
                    <div><i class="bi bi-play-circle text-primary"></i> ใช้งาน: <b><?= number_format($summary['in_use_count'] ?? 0) ?></b></div>
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
                    <table class="table table-sm wh-movement-table">
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
                                    <span class="wh-movement-pill bg-<?= $badge ?> text-white"><?= e($m['movement_type']) ?></span>
                                </td>
                                <td><?= e($m['item_name'] ?? '-') ?></td>
                                <td>
                                    <?= e($m['serial_number'] ?? '-') ?>
                                    <?php if (in_array(($m['serial_status'] ?? ''), ['Reserved', 'Allocated'], true)): ?>
                                        <span class="wh-reserved-badge"><i class="bi bi-check-circle-fill"></i> จอง</span>
                                    <?php endif; ?>
                                </td>
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

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
