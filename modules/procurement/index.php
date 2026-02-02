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
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="bi bi-cart3 me-2"></i>Procurement
        </h2>
        <p class="text-muted mb-0">จัดซื้อ-จัดจ้าง</p>
    </div>
</div>

<!-- Stats Cards (Minimalist) -->
<div class="row mb-4 g-3">
    <?php if ($canViewPR): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-3 p-2" style="background: var(--primary-light);">
                        <i class="bi bi-file-text fs-4" style="color: var(--primary);"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-semibold"><?= (int)$prStats['pending'] ?></div>
                        <div class="text-muted small">PR รออนุมัติ</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($canViewPO): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-3 p-2" style="background: var(--info-light);">
                        <i class="bi bi-receipt fs-4" style="color: var(--info);"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-semibold"><?= (int)$poStats['pending'] ?></div>
                        <div class="text-muted small">PO รออนุมัติ</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($canViewGR): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-3 p-2" style="background: var(--success-light);">
                        <i class="bi bi-box-seam fs-4" style="color: var(--success);"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-semibold"><?= (int)$grStats['total'] ?></div>
                        <div class="text-muted small">GR ทั้งหมด</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Quick Actions (Minimalist) -->
<div class="row mb-4 g-3">
    <?php if ($canViewPR): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-file-text" style="color: var(--primary);"></i>
                    <span class="fw-semibold">Purchase Request</span>
                </div>
                <div class="text-muted small mb-3">
                    <?= (int)$prStats['total'] ?> ทั้งหมด · <?= (int)$prStats['draft'] ?> แบบร่าง · <?= (int)$prStats['approved'] ?> อนุมัติ
                </div>
                <div class="d-flex gap-2">
                    <a href="pr/" class="btn btn-sm" style="background: var(--primary); color: white;">รายการ PR</a>
                    <?php if ($canCreatePR): ?>
                    <a href="pr/create.php" class="btn btn-sm btn-outline-secondary">+ สร้าง</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($canViewPO): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-receipt" style="color: var(--info);"></i>
                    <span class="fw-semibold">Purchase Order</span>
                </div>
                <div class="text-muted small mb-3">
                    <?= (int)$poStats['total'] ?> ทั้งหมด · <?= (int)$poStats['approved'] ?> รอรับ
                </div>
                <div class="d-flex gap-2">
                    <a href="po/" class="btn btn-sm" style="background: var(--info); color: white;">รายการ PO</a>
                    <?php if ($canCreatePO): ?>
                    <a href="po/create.php" class="btn btn-sm btn-outline-secondary">+ สร้าง</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($canViewGR): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-box-seam" style="color: var(--success);"></i>
                    <span class="fw-semibold">Goods Receipt</span>
                </div>
                <div class="text-muted small mb-3">
                    <?= (int)$grStats['total'] ?> ทั้งหมด
                </div>
                <a href="gr/" class="btn btn-sm" style="background: var(--success); color: white;">รายการ GR</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="row g-3">
    <?php if ($canViewPR): ?>
    <!-- Recent PRs -->
    <div class="col-md-6 mb-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header border-0 bg-transparent">
                <i class="bi bi-clock-history me-2 text-muted"></i><span class="fw-semibold">PR ล่าสุด</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
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
    </div>
    <?php endif; ?>
    
    <?php if ($canViewPO): ?>
    <!-- Recent POs -->
    <div class="col-md-6 mb-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header border-0 bg-transparent">
                <i class="bi bi-clock-history me-2 text-muted"></i><span class="fw-semibold">PO ล่าสุด</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
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
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
