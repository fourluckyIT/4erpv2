<?php
/**
 * Modern Sidebar Template
 * 4ERP - New UI Design System
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

// Core visibility (align with RBAC; fallback to role matrix where permissions are not defined)
$canViewJobs = $rbac->can('view', 'JOB');
$canViewPlanning = $rbac->can('view', 'PLAN')
    || $rbac->can('create', 'PLAN')
    || $auth->hasRole(ROLE_PLANNER)
    || $auth->hasRole(ROLE_MANAGER)
    || $auth->isAdmin();
$canReleaseRoute = $rbac->can('dispatch', 'JOB')
    || $auth->hasRole(ROLE_PLANNER)
    || $auth->hasRole(ROLE_WAREHOUSE)
    || $auth->isAdmin();
$showOperations = $canViewJobs || $canViewPlanning || $canReleaseRoute;

// Procurement visibility (align with RBAC)
$canViewPR = $rbac->can('view', 'PR');
$canCreatePR = $rbac->can('create', 'PR');
$canViewPO = $rbac->can('view', 'PO');
$canCreatePO = $rbac->can('create', 'PO');
$canViewGR = $auth->isAdmin() || $auth->hasRole(ROLE_WAREHOUSE);
$showProcurement = $canViewPR || $canCreatePR || $canViewPO || $canCreatePO || $canViewGR;

// Other modules
$canViewWarehouse = $rbac->can('view', 'WH') || $auth->hasRole(ROLE_WAREHOUSE) || $auth->isAdmin();
$canViewAccounting = $rbac->can('view', 'INVOICE') || $auth->hasRole(ROLE_ACCOUNTANT) || $auth->isAdmin();
$canViewHRM = $auth->hasRole(ROLE_HRM) || $auth->isAdmin() || $auth->hasRole(ROLE_MANAGER);
$canViewAdmin = $auth->isAdmin();

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
        <div class="sidebar-logo">4E</div>
        <span class="sidebar-title">4ERP</span>
    </div>
    
    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">หน้าหลัก</div>
            <a href="<?= BASE_URL ?>/index.php" class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>">
                <i class="bi bi-grid nav-icon"></i>
                <span class="nav-text">Dashboard</span>
            </a>
        </div>

        <?php if ($showOperations): ?>
        <div class="nav-section">
            <div class="nav-section-title">Operations</div>
            <?php if ($canViewJobs): ?>
            <a href="<?= BASE_URL ?>/modules/jobs/" class="nav-item">
                <i class="bi bi-briefcase nav-icon"></i>
                <span class="nav-text">Jobs</span>
                <?php if ($pendingJobsCount > 0 && ($auth->isAdmin() || $auth->hasRole(ROLE_MANAGER))): ?>
                <span class="nav-badge"><?= $pendingJobsCount ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if ($canViewPlanning): ?>
            <a href="<?= BASE_URL ?>/modules/planning/" class="nav-item">
                <i class="bi bi-calendar3 nav-icon"></i>
                <span class="nav-text">Planning</span>
            </a>
            <?php endif; ?>
            <?php if ($canReleaseRoute): ?>
            <a href="<?= BASE_URL ?>/modules/logistics/dispatch/release.php" class="nav-item">
                <i class="bi bi-send nav-icon"></i>
                <span class="nav-text">ปล่อย Route</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($showProcurement): ?>
        <div class="nav-section">
            <div class="nav-section-title">Procurement</div>
            <a href="<?= BASE_URL ?>/modules/procurement/" class="nav-item">
                <i class="bi bi-cart3 nav-icon"></i>
                <span class="nav-text">Procurement</span>
                <?php if ($pendingPRCount > 0): ?>
                <span class="nav-badge"><?= $pendingPRCount ?></span>
                <?php endif; ?>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($canViewWarehouse): ?>
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

        <?php if ($canViewAccounting): ?>
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

        <?php if ($canViewHRM): ?>
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

        <?php if ($canViewAdmin): ?>
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
