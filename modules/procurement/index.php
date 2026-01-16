<?php
/**
 * Procurement Dashboard
 * ERP v2 - Phase 4
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();

// Get counts
$prStats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(status = 'Draft') as draft,
        SUM(status = 'Submitted') as pending,
        SUM(status = 'Approved') as approved
    FROM purchase_requests
")->fetch();

$poStats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(status = 'Draft') as draft,
        SUM(status = 'Submitted') as pending,
        SUM(status = 'Approved') as approved,
        SUM(status IN ('Partially Received', 'Received')) as received
    FROM purchase_orders
")->fetch();

$grStats = $db->query("
    SELECT COUNT(*) as total FROM goods_receipts
")->fetch();

// Recent PRs
$recentPRs = $db->query("
    SELECT pr.*, u.full_name as requester_name 
    FROM purchase_requests pr 
    JOIN users u ON pr.requester_id = u.id
    ORDER BY pr.created_at DESC LIMIT 5
")->fetchAll();

// Recent POs
$recentPOs = $db->query("
    SELECT po.*, s.name as supplier_name 
    FROM purchase_orders po 
    JOIN suppliers s ON po.supplier_id = s.id
    ORDER BY po.created_at DESC LIMIT 5
")->fetchAll();

$pageTitle = 'Procurement - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="bi bi-cart3 me-2"></i>Procurement
        </h2>
        <p class="text-muted mb-0">จัดซื้อ-จัดจ้าง</p>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stat-number"><?= (int)$prStats['pending'] ?></div>
                        <div class="stat-label">PR รออนุมัติ</div>
                    </div>
                    <div class="stat-icon text-primary">
                        <i class="bi bi-file-text"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card stat-card info">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stat-number"><?= (int)$poStats['pending'] ?></div>
                        <div class="stat-label">PO รออนุมัติ</div>
                    </div>
                    <div class="stat-icon text-info">
                        <i class="bi bi-receipt"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card stat-card success">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stat-number"><?= (int)$grStats['total'] ?></div>
                        <div class="stat-label">GR ทั้งหมด</div>
                    </div>
                    <div class="stat-icon text-success">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-file-text me-2"></i>Purchase Request (PR)
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    ทั้งหมด: <?= (int)$prStats['total'] ?> |
                    แบบร่าง: <?= (int)$prStats['draft'] ?> |
                    อนุมัติแล้ว: <?= (int)$prStats['approved'] ?>
                </p>
                <a href="pr/" class="btn btn-primary btn-sm">
                    <i class="bi bi-list me-1"></i>รายการ PR
                </a>
                <a href="pr/create.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i>สร้าง PR
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card h-100">
            <div class="card-header bg-info text-white">
                <i class="bi bi-receipt me-2"></i>Purchase Order (PO)
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    ทั้งหมด: <?= (int)$poStats['total'] ?> |
                    รอรับ: <?= (int)$poStats['approved'] ?>
                </p>
                <a href="po/" class="btn btn-info text-white btn-sm">
                    <i class="bi bi-list me-1"></i>รายการ PO
                </a>
                <a href="po/create.php" class="btn btn-outline-info btn-sm">
                    <i class="bi bi-plus-circle me-1"></i>สร้าง PO
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card h-100">
            <div class="card-header bg-success text-white">
                <i class="bi bi-box-seam me-2"></i>Goods Receipt (GR)
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    ทั้งหมด: <?= (int)$grStats['total'] ?>
                </p>
                <a href="gr/" class="btn btn-success btn-sm">
                    <i class="bi bi-list me-1"></i>รายการ GR
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Recent PRs -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-clock-history me-2"></i>PR ล่าสุด
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>เลขที่</th>
                                <th>ผู้ขอ</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentPRs)): ?>
                            <tr><td colspan="3" class="text-center text-muted">ไม่มีข้อมูล</td></tr>
                            <?php else: ?>
                            <?php foreach ($recentPRs as $pr): ?>
                            <tr>
                                <td>
                                    <a href="pr/view.php?id=<?= $pr['id'] ?>"><?= e($pr['pr_number']) ?></a>
                                </td>
                                <td><?= e($pr['requester_name']) ?></td>
                                <td>
                                    <span class="badge bg-<?= match($pr['status']) {
                                        'Draft' => 'secondary',
                                        'Submitted' => 'warning',
                                        'Approved' => 'success',
                                        'Rejected' => 'danger',
                                        default => 'secondary'
                                    } ?>"><?= e($pr['status']) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent POs -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-clock-history me-2"></i>PO ล่าสุด
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>เลขที่</th>
                                <th>ผู้ขาย</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentPOs)): ?>
                            <tr><td colspan="3" class="text-center text-muted">ไม่มีข้อมูล</td></tr>
                            <?php else: ?>
                            <?php foreach ($recentPOs as $po): ?>
                            <tr>
                                <td>
                                    <a href="po/view.php?id=<?= $po['id'] ?>"><?= e($po['po_number']) ?></a>
                                </td>
                                <td><?= e($po['supplier_name']) ?></td>
                                <td>
                                    <span class="badge bg-<?= match($po['status']) {
                                        'Draft' => 'secondary',
                                        'Submitted' => 'warning',
                                        'Approved' => 'primary',
                                        'Partially Received' => 'info',
                                        'Received' => 'success',
                                        default => 'secondary'
                                    } ?>"><?= e($po['status']) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
