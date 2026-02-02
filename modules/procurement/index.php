<?php
/**
 * Procurement Dashboard
 * 4ERP - Phase 4
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();
$rbac = new RBAC();

$db = getDB();

// RBAC: Determine what this user can see
$canViewPR = $rbac->can('view', 'PR') || $rbac->can('create', 'PR');
$canCreatePR = $rbac->can('create', 'PR');
$canViewPO = $rbac->can('view', 'PO');
$canCreatePO = $rbac->can('create', 'PO');
$canViewGR = $auth->isAdmin() || $auth->hasRole(ROLE_WAREHOUSE) || $auth->hasRole(ROLE_PURCHASE);

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

$pageTitle = 'Procurement - 4ERP';
$showQuickActions = $canViewPR || $canCreatePR || $canViewPO || $canCreatePO || $canViewGR;
$recentPrCol = $canViewPO ? 'col-6' : 'col-12';
$recentPoCol = $canViewPR ? 'col-6' : 'col-12';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-cart3" style="color: var(--primary);"></i> Procurement
        </h1>
        <p class="page-subtitle">จัดซื้อ-จัดจ้าง</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canCreatePR): ?>
        <a href="pr/create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> สร้าง PR
        </a>
        <?php endif; ?>
        <?php if ($canCreatePO): ?>
        <a href="po/create.php" class="btn btn-info text-white">
            <i class="bi bi-plus-circle"></i> สร้าง PO
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($canViewPR || $canViewPO || $canViewGR): ?>
<!-- Stats Cards -->
<div class="stat-cards">
    <?php if ($canViewPR): ?>
    <div class="stat-card">
        <div class="stat-icon primary"><i class="bi bi-file-text" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int)$prStats['pending'] ?></div>
            <div class="stat-label">PR รออนุมัติ</div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($canViewPO): ?>
    <div class="stat-card">
        <div class="stat-icon info"><i class="bi bi-receipt" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int)$poStats['pending'] ?></div>
            <div class="stat-label">PO รออนุมัติ</div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($canViewGR): ?>
    <div class="stat-card">
        <div class="stat-icon success"><i class="bi bi-box-seam" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int)$grStats['total'] ?></div>
            <div class="stat-label">GR ทั้งหมด</div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="widgets-grid">
    <?php if ($showQuickActions): ?>
    <div class="widget col-12">
        <div class="card-header">
            <div class="card-title"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</div>
        </div>
        <div class="card-body">
            <div class="quick-actions">
                <?php if ($canViewPR): ?>
                <a href="pr/" class="quick-action">
                    <div class="quick-action-icon"><i class="bi bi-file-text"></i></div>
                    <div class="quick-action-label">รายการ PR</div>
                </a>
                <?php endif; ?>
                <?php if ($canCreatePR): ?>
                <a href="pr/create.php" class="quick-action">
                    <div class="quick-action-icon"><i class="bi bi-plus-circle"></i></div>
                    <div class="quick-action-label">สร้าง PR</div>
                </a>
                <?php endif; ?>
                <?php if ($canViewPO): ?>
                <a href="po/" class="quick-action">
                    <div class="quick-action-icon"><i class="bi bi-receipt"></i></div>
                    <div class="quick-action-label">รายการ PO</div>
                </a>
                <?php endif; ?>
                <?php if ($canCreatePO): ?>
                <a href="po/create.php" class="quick-action">
                    <div class="quick-action-icon"><i class="bi bi-plus-circle"></i></div>
                    <div class="quick-action-label">สร้าง PO</div>
                </a>
                <?php endif; ?>
                <?php if ($canViewGR): ?>
                <a href="gr/" class="quick-action">
                    <div class="quick-action-icon"><i class="bi bi-box-seam"></i></div>
                    <div class="quick-action-label">รายการ GR</div>
                </a>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-3 mt-3 small text-muted">
                <?php if ($canViewPR): ?>
                <span>PR: <?= (int)$prStats['total'] ?> ทั้งหมด · <?= (int)$prStats['draft'] ?> แบบร่าง</span>
                <?php endif; ?>
                <?php if ($canViewPO): ?>
                <span>PO: <?= (int)$poStats['total'] ?> ทั้งหมด · <?= (int)$poStats['approved'] ?> รอรับ</span>
                <?php endif; ?>
                <?php if ($canViewGR): ?>
                <span>GR: <?= (int)$grStats['total'] ?> ทั้งหมด</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canViewPR): ?>
    <div class="widget <?= $recentPrCol ?>">
        <div class="card-header">
            <div class="card-title"><i class="bi bi-clock-history me-2"></i>PR ล่าสุด</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="fw-medium">เลขที่</th>
                            <th class="fw-medium">ผู้ขอ</th>
                            <th class="fw-medium">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentPRs)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">ไม่มีข้อมูล</td></tr>
                        <?php else: ?>
                        <?php foreach ($recentPRs as $pr): ?>
                        <tr>
                            <td>
                                <a href="pr/view.php?id=<?= $pr['id'] ?>" class="text-decoration-none fw-medium"><?= e($pr['pr_number']) ?></a>
                            </td>
                            <td class="text-muted"><?= e($pr['requester_name']) ?></td>
                            <td>
                                <span class="badge rounded-pill bg-<?= match($pr['status']) {
                                    'Draft' => 'secondary',
                                    'Submitted' => 'warning text-dark',
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
    <?php endif; ?>
    
    <?php if ($canViewPO): ?>
    <div class="widget <?= $recentPoCol ?>">
        <div class="card-header">
            <div class="card-title"><i class="bi bi-clock-history me-2"></i>PO ล่าสุด</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th class="fw-medium">เลขที่</th>
                            <th class="fw-medium">ผู้ขาย</th>
                            <th class="fw-medium">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentPOs)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">ไม่มีข้อมูล</td></tr>
                        <?php else: ?>
                        <?php foreach ($recentPOs as $po): ?>
                        <tr>
                            <td>
                                <a href="po/view.php?id=<?= $po['id'] ?>" class="text-decoration-none fw-medium"><?= e($po['po_number']) ?></a>
                            </td>
                            <td class="text-muted"><?= e($po['supplier_name']) ?></td>
                            <td>
                                <span class="badge rounded-pill bg-<?= match($po['status']) {
                                    'Draft' => 'secondary',
                                    'Submitted' => 'warning text-dark',
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
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
