<?php
/**
 * Master Data Dashboard
 * 4ERP - Phase 3
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();

// Get counts
$stats = [];
$stats['customers'] = $db->query("SELECT COUNT(*) FROM customers WHERE is_active = 1")->fetchColumn();
$stats['sites'] = $db->query("SELECT COUNT(*) FROM sites WHERE is_active = 1")->fetchColumn();
$stats['suppliers'] = $db->query("SELECT COUNT(*) FROM suppliers WHERE is_active = 1")->fetchColumn();
$stats['items'] = $db->query("SELECT COUNT(*) FROM items WHERE is_active = 1")->fetchColumn();
$stats['serials'] = $db->query("SELECT COUNT(*) FROM serials")->fetchColumn();
$stats['people'] = $db->query("SELECT COUNT(*) FROM people WHERE is_active = 1")->fetchColumn();
$stats['employees'] = $db->query("SELECT COUNT(*) FROM people WHERE is_active = 1 AND people_type = 'Employee'")->fetchColumn();
$stats['external'] = $db->query("SELECT COUNT(*) FROM people WHERE is_active = 1 AND people_type = 'External'")->fetchColumn();

// Items by type
$itemsByType = $db->query("
    SELECT item_type, COUNT(*) as count 
    FROM items WHERE is_active = 1 
    GROUP BY item_type
")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Master Data - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="bi bi-database me-2"></i>Master Data
        </h2>
        <p class="text-muted mb-0">จัดการข้อมูลหลักของระบบ</p>
    </div>
</div>

<!-- Quick Stats -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="stat-number"><?= $stats['customers'] ?></div>
                <div class="stat-label">ลูกค้า</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card stat-card info">
            <div class="card-body">
                <div class="stat-number"><?= $stats['suppliers'] ?></div>
                <div class="stat-label">ผู้ขาย</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card stat-card warning">
            <div class="card-body">
                <div class="stat-number"><?= $stats['items'] ?></div>
                <div class="stat-label">รายการสินค้า</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card stat-card success">
            <div class="card-body">
                <div class="stat-number"><?= $stats['people'] ?></div>
                <div class="stat-label">บุคลากร</div>
            </div>
        </div>
    </div>
</div>

<!-- Modules Grid -->
<div class="row">
    <!-- Customers -->
    <div class="col-md-6 col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-building me-2"></i>ลูกค้า & Site
            </div>
            <div class="card-body">
                <p class="text-muted"><?= $stats['customers'] ?> ลูกค้า, <?= $stats['sites'] ?> sites</p>
                <a href="customers.php" class="btn btn-primary">
                    <i class="bi bi-list me-1"></i>จัดการลูกค้า
                </a>
                <a href="sites.php" class="btn btn-outline-primary">
                    <i class="bi bi-geo-alt me-1"></i>Sites
                </a>
            </div>
        </div>
    </div>
    
    <!-- Suppliers -->
    <div class="col-md-6 col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-info text-white">
                <i class="bi bi-truck me-2"></i>ผู้ขาย (Supplier)
            </div>
            <div class="card-body">
                <p class="text-muted"><?= $stats['suppliers'] ?> ผู้ขาย</p>
                <a href="suppliers.php" class="btn btn-info text-white">
                    <i class="bi bi-list me-1"></i>จัดการผู้ขาย
                </a>
            </div>
        </div>
    </div>
    
    <!-- Items -->
    <div class="col-md-6 col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-warning text-dark">
                <i class="bi bi-box-seam me-2"></i>สินค้า & อุปกรณ์
            </div>
            <div class="card-body">
                <p class="text-muted mb-2">
                    Device: <?= $itemsByType['Device'] ?? 0 ?>,
                    Equipment: <?= $itemsByType['Equipment'] ?? 0 ?>,
                    Vehicle: <?= $itemsByType['Vehicle'] ?? 0 ?>,
                    Consumable: <?= $itemsByType['Consumable'] ?? 0 ?>
                </p>
                <a href="items.php" class="btn btn-warning">
                    <i class="bi bi-list me-1"></i>จัดการสินค้า
                </a>
                <a href="serials.php" class="btn btn-outline-warning">
                    <i class="bi bi-upc-scan me-1"></i>Serial (<?= $stats['serials'] ?>)
                </a>
            </div>
        </div>
    </div>
    
    <!-- People -->
    <div class="col-md-6 col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-success text-white">
                <i class="bi bi-people me-2"></i>บุคลากร
            </div>
            <div class="card-body">
                <p class="text-muted">
                    พนักงาน: <?= $stats['employees'] ?>,
                    แรงงานภายนอก: <?= $stats['external'] ?>
                </p>
                <a href="people.php" class="btn btn-success">
                    <i class="bi bi-list me-1"></i>จัดการบุคลากร
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
