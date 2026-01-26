<?php
/**
 * Modern Sidebar Template
 * ERP v2 - New UI Design System
 */

$auth = $auth ?? new Auth();
$rbac = $rbac ?? new RBAC();
$currentUser = $auth->getCurrentUser();
$userRoles = $auth->getCurrentRoles();
$primaryRole = $userRoles[0] ?? 'SAL';

// Get menu counts (with error handling)
$db = getDB();
$pendingJobsCount = 0;
$pendingPRCount = 0;
try {
    $pendingJobsCount = $db->query("SELECT COUNT(*) FROM jobs WHERE status = 'Submitted'")->fetchColumn();
    $pendingPRCount = $db->query("SELECT COUNT(*) FROM purchase_requests WHERE status IN ('Draft', 'Submitted')")->fetchColumn();
} catch (Exception $e) {
    // Tables might not exist
}

// Role color mapping
$roleColors = [
    'ADM' => '#7C3AED',
    'SAL' => '#EC4899',
    'PLN' => '#14B8A6',
    'PUR' => '#F97316',
    'HR' => '#8B5CF6',
    'WH' => '#06B6D4',
    'ACC' => '#10B981',
    'MGR' => '#EF4444'
];
$roleColor = $roleColors[$primaryRole] ?? '#4F46E5';
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">E2</div>
        <span class="sidebar-title">ERP v2</span>
    </div>
    
    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">หน้าหลัก</div>
            <a href="<?= BASE_URL ?>/dashboard.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">
                <i class="bi bi-grid nav-icon"></i>
                <span class="nav-text">Dashboard</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Operations</div>
            <?php if ($rbac->can('view', 'JOB')): ?>
            <a href="<?= BASE_URL ?>/modules/jobs/" class="nav-item">
                <i class="bi bi-briefcase nav-icon"></i>
                <span class="nav-text">Jobs</span>
                <?php if ($pendingJobsCount > 0 && ($auth->isAdmin() || $auth->hasRole(ROLE_MANAGER))): ?>
                <span class="nav-badge"><?= $pendingJobsCount ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/modules/planning/" class="nav-item">
                <i class="bi bi-calendar3 nav-icon"></i>
                <span class="nav-text">Planning</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/logistics/dispatch/" class="nav-item">
                <i class="bi bi-truck nav-icon"></i>
                <span class="nav-text">Logistics</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Procurement</div>
            <?php if ($rbac->can('view', 'PR')): ?>
            <a href="<?= BASE_URL ?>/modules/procurement/pr/" class="nav-item">
                <i class="bi bi-file-text nav-icon"></i>
                <span class="nav-text">PR - ใบขอซื้อ</span>
                <?php if ($pendingPRCount > 0): ?>
                <span class="nav-badge"><?= $pendingPRCount ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if ($rbac->can('view', 'PO')): ?>
            <a href="<?= BASE_URL ?>/modules/procurement/po/" class="nav-item">
                <i class="bi bi-cart-check nav-icon"></i>
                <span class="nav-text">PO - ใบสั่งซื้อ</span>
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/modules/procurement/gr/" class="nav-item">
                <i class="bi bi-box-arrow-in-down nav-icon"></i>
                <span class="nav-text">GR - รับเข้า</span>
            </a>
        </div>

        <?php if ($rbac->can('view', 'WH') || $auth->hasRole(ROLE_WAREHOUSE) || $auth->isAdmin()): ?>
        <div class="nav-section">
            <div class="nav-section-title">Warehouse</div>
            <a href="<?= BASE_URL ?>/modules/warehouse/" class="nav-item">
                <i class="bi bi-box-seam nav-icon"></i>
                <span class="nav-text">Stock</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/warehouse/movements.php" class="nav-item">
                <i class="bi bi-arrow-left-right nav-icon"></i>
                <span class="nav-text">Movements</span>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($rbac->can('view', 'INVOICE') || $auth->hasRole(ROLE_ACCOUNTANT) || $auth->isAdmin()): ?>
        <div class="nav-section">
            <div class="nav-section-title">Accounting</div>
            <a href="<?= BASE_URL ?>/modules/accounting/invoices/" class="nav-item">
                <i class="bi bi-receipt nav-icon"></i>
                <span class="nav-text">Invoices</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/accounting/payments/" class="nav-item">
                <i class="bi bi-credit-card nav-icon"></i>
                <span class="nav-text">Payments</span>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($auth->hasRole(ROLE_HRM) || $auth->isAdmin() || $auth->hasRole(ROLE_MANAGER)): ?>
        <div class="nav-section">
            <div class="nav-section-title">HRM</div>
            <a href="<?= BASE_URL ?>/modules/hrm/people/" class="nav-item">
                <i class="bi bi-people nav-icon"></i>
                <span class="nav-text">People</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/timesheet/" class="nav-item">
                <i class="bi bi-clock-history nav-icon"></i>
                <span class="nav-text">Timesheet</span>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($auth->isAdmin() || $auth->hasRole(ROLE_MANAGER)): ?>
        <div class="nav-section">
            <div class="nav-section-title">Admin</div>
            <a href="<?= BASE_URL ?>/modules/admin/users.php" class="nav-item">
                <i class="bi bi-person-gear nav-icon"></i>
                <span class="nav-text">Users</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/admin/roles.php" class="nav-item">
                <i class="bi bi-shield-lock nav-icon"></i>
                <span class="nav-text">Roles & Permissions</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/admin/dashboard-config/" class="nav-item">
                <i class="bi bi-sliders nav-icon"></i>
                <span class="nav-text">Dashboard Config</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/admin/audit_logs.php" class="nav-item">
                <i class="bi bi-journal-text nav-icon"></i>
                <span class="nav-text">Audit Logs</span>
            </a>
        </div>
        <?php endif; ?>
    </nav>
</aside>
