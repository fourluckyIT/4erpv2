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
$canByPerm = function (string $action, string $entityType, array $roleFallback = []) use ($rbac, $auth): bool {
    if ($rbac->permissionExists($action, $entityType)) {
        return $rbac->can($action, $entityType);
    }
    if (!empty($roleFallback)) {
        return $rbac->hasAnyRole($roleFallback) || $auth->isAdmin();
    }
    return $auth->isAdmin();
};

$canViewJobs = $canByPerm('view', 'JOB', [ROLE_SALE, ROLE_PLANNER, ROLE_MANAGER, ROLE_ADMIN]);
$canViewPlanning = $canByPerm('view', 'PLAN', [ROLE_PLANNER, ROLE_MANAGER, ROLE_ADMIN])
    || $canByPerm('create', 'PLAN', [ROLE_PLANNER, ROLE_MANAGER, ROLE_ADMIN]);
$canReleaseRoute = false;
$showOperations = $canViewJobs || $canViewPlanning;

// Procurement visibility (align with RBAC)
$canViewPR = $canByPerm('view', 'PR', [ROLE_PURCHASE, ROLE_MANAGER, ROLE_ADMIN]);
$canCreatePR = $canByPerm('create', 'PR', [ROLE_PURCHASE, ROLE_ADMIN]);
$canViewPO = $canByPerm('view', 'PO', [ROLE_PURCHASE, ROLE_MANAGER, ROLE_ADMIN]);
$canCreatePO = $canByPerm('create', 'PO', [ROLE_PURCHASE, ROLE_ADMIN]);
$canViewGR = $canByPerm('view', 'GR', [ROLE_WAREHOUSE, ROLE_ADMIN]);
$showProcurement = $canViewPR || $canCreatePR || $canViewPO || $canCreatePO || $canViewGR;

// Other modules
$canViewWarehouse = $canByPerm('view', 'WH', [ROLE_WAREHOUSE, ROLE_MANAGER, ROLE_ADMIN]);
$canViewAccounting = $canByPerm('view', 'INVOICE', [ROLE_ACCOUNTANT, ROLE_MANAGER, ROLE_ADMIN]);
$canViewHRM = $canByPerm('view', 'TIMESHEET', [ROLE_HRM, ROLE_MANAGER, ROLE_ADMIN])
    || $canByPerm('view', 'SALARY', [ROLE_HRM, ROLE_MANAGER, ROLE_ADMIN])
    || $canByPerm('view', 'OVERTIME', [ROLE_HRM, ROLE_MANAGER, ROLE_ADMIN]);
$canViewAdmin = $canByPerm('edit', 'PERMISSION', [ROLE_ADMIN]);
$canViewUsers = $canByPerm('view', 'USER', [ROLE_ADMIN]);
$canManagePerm = $canByPerm('edit', 'PERMISSION', [ROLE_ADMIN]);

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

$basePath = parse_url(BASE_URL, PHP_URL_PATH) ?: '';
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
$normalizePath = function (string $path): string {
    $path = '/' . ltrim($path, '/');
    return $path === '' ? '/' : $path;
};
$basePath = $normalizePath($basePath);
$currentPath = $normalizePath($currentPath);
if ($basePath !== '/' && str_starts_with($currentPath, $basePath)) {
    $currentPath = substr($currentPath, strlen($basePath));
    if ($currentPath === '' || $currentPath === false) {
        $currentPath = '/';
    }
}
$isActiveExact = function (string $path) use ($currentPath): bool {
    $path = '/' . ltrim($path, '/');
    return rtrim($currentPath, '/') === rtrim($path, '/');
};
$isActivePrefix = function (string $prefix) use ($currentPath): bool {
    $prefix = '/' . ltrim($prefix, '/');
    $prefix = rtrim($prefix, '/');
    return $currentPath === $prefix || str_starts_with($currentPath, $prefix . '/');
};

$activeDashboard = $isActiveExact('/index.php') || $isActiveExact('/');
$activeJobs = $isActivePrefix('/modules/jobs');
$activePlanning = $isActivePrefix('/modules/planning');
$activeProcurement = $isActivePrefix('/modules/procurement');
$activeWarehouseMovements = $isActiveExact('/modules/warehouse/movements.php');
$activeWarehouseStock = $isActivePrefix('/modules/warehouse') && !$activeWarehouseMovements;
$activeInvoices = $isActivePrefix('/modules/accounting/invoices');
$activePayments = $isActivePrefix('/modules/accounting/payments');
$activeAPInvoices = $isActivePrefix('/modules/accounting/ap');
$activeAPPayments = $isActivePrefix('/modules/accounting/ap-payments');
$activeHrmPeople = $isActivePrefix('/modules/hrm/people');
$activeHrmManpower = $isActivePrefix('/modules/hrm/manpower');
$activeTimesheet = $isActivePrefix('/modules/timesheet');
$activeAdminUsers = $isActiveExact('/modules/admin/users.php');
$activeAdminRoles = $isActiveExact('/modules/admin/roles.php');
$activeAdminDashboard = $isActivePrefix('/modules/admin/dashboard-config');
$activeAdminAudit = $isActiveExact('/modules/admin/audit_logs.php');
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">4E</div>
        <span class="sidebar-title">4ERP</span>
    </div>
    
    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">หน้าหลัก</div>
            <a href="<?= BASE_URL ?>/index.php" class="nav-item <?= $activeDashboard ? 'active' : '' ?>">
                <i class="bi bi-grid nav-icon"></i>
                <span class="nav-text">Dashboard</span>
            </a>
        </div>

        <?php if ($showOperations): ?>
        <div class="nav-section">
            <div class="nav-section-title">Operations</div>
            <?php if ($canViewJobs): ?>
            <a href="<?= BASE_URL ?>/modules/jobs/" class="nav-item <?= $activeJobs ? 'active' : '' ?>">
                <i class="bi bi-briefcase nav-icon"></i>
                <span class="nav-text">Jobs</span>
                <?php if ($pendingJobsCount > 0 && ($auth->isAdmin() || $auth->hasRole(ROLE_MANAGER))): ?>
                <span class="nav-badge"><?= $pendingJobsCount ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if ($canViewPlanning): ?>
            <a href="<?= BASE_URL ?>/modules/planning/" class="nav-item <?= $activePlanning ? 'active' : '' ?>">
                <i class="bi bi-calendar3 nav-icon"></i>
                <span class="nav-text">Planning</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($showProcurement): ?>
        <div class="nav-section">
            <div class="nav-section-title">Procurement</div>
            <a href="<?= BASE_URL ?>/modules/procurement/" class="nav-item <?= $activeProcurement ? 'active' : '' ?>">
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
            <a href="<?= BASE_URL ?>/modules/warehouse/" class="nav-item <?= $activeWarehouseStock ? 'active' : '' ?>">
                <i class="bi bi-box-seam nav-icon"></i>
                <span class="nav-text">Stock</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/warehouse/movements.php" class="nav-item <?= $activeWarehouseMovements ? 'active' : '' ?>">
                <i class="bi bi-arrow-left-right nav-icon"></i>
                <span class="nav-text">Movements</span>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($canViewAccounting): ?>
        <div class="nav-section">
            <div class="nav-section-title">Accounting (AR)</div>
            <a href="<?= BASE_URL ?>/modules/accounting/invoices/" class="nav-item <?= $activeInvoices ? 'active' : '' ?>">
                <i class="bi bi-receipt nav-icon"></i>
                <span class="nav-text">AR Invoices</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/accounting/payments/" class="nav-item <?= $activePayments ? 'active' : '' ?>">
                <i class="bi bi-credit-card nav-icon"></i>
                <span class="nav-text">AR Payments</span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-title">Accounting (AP)</div>
            <a href="<?= BASE_URL ?>/modules/accounting/ap/" class="nav-item <?= $activeAPInvoices ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-text nav-icon"></i>
                <span class="nav-text">AP Invoices</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/accounting/ap-payments/" class="nav-item <?= $activeAPPayments ? 'active' : '' ?>">
                <i class="bi bi-cash-stack nav-icon"></i>
                <span class="nav-text">AP Payments</span>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($canViewHRM): ?>
        <div class="nav-section">
            <div class="nav-section-title">HRM</div>
            <a href="<?= BASE_URL ?>/modules/hrm/people/" class="nav-item <?= $activeHrmPeople ? 'active' : '' ?>">
                <i class="bi bi-people nav-icon"></i>
                <span class="nav-text">People</span>
            </a>
            <?php if ($auth->hasRole(ROLE_HRM) || $auth->isAdmin()): ?>
            <a href="<?= BASE_URL ?>/modules/hrm/manpower/" class="nav-item <?= $activeHrmManpower ? 'active' : '' ?>">
                <i class="bi bi-person-vcard nav-icon"></i>
                <span class="nav-text">Manpower PO</span>
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/modules/timesheet/" class="nav-item <?= $activeTimesheet ? 'active' : '' ?>">
                <i class="bi bi-clock-history nav-icon"></i>
                <span class="nav-text">Timesheet</span>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($canViewAdmin): ?>
        <div class="nav-section">
            <div class="nav-section-title">Admin</div>
            <?php if ($canViewUsers): ?>
            <a href="<?= BASE_URL ?>/modules/admin/users.php" class="nav-item <?= $activeAdminUsers ? 'active' : '' ?>">
                <i class="bi bi-person-gear nav-icon"></i>
                <span class="nav-text">Users</span>
            </a>
            <?php endif; ?>
            <?php if ($canManagePerm): ?>
            <a href="<?= BASE_URL ?>/modules/admin/roles.php" class="nav-item <?= $activeAdminRoles ? 'active' : '' ?>">
                <i class="bi bi-shield-lock nav-icon"></i>
                <span class="nav-text">Roles & Permissions</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/admin/dashboard-config/" class="nav-item <?= $activeAdminDashboard ? 'active' : '' ?>">
                <i class="bi bi-sliders nav-icon"></i>
                <span class="nav-text">Dashboard Config</span>
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/modules/admin/audit_logs.php" class="nav-item <?= $activeAdminAudit ? 'active' : '' ?>">
                <i class="bi bi-journal-text nav-icon"></i>
                <span class="nav-text">Audit Logs</span>
            </a>
        </div>
        <?php endif; ?>
    </nav>
</aside>
