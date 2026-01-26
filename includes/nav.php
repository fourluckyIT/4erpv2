<?php
/**
 * Navigation Template
 * ERP v2 - Bootstrap 5
 */

$auth = new Auth();
$rbac = new RBAC();
$currentUser = $auth->getCurrentUser();
$userRoles = $auth->getCurrentRoles();
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= BASE_URL ?>/index.php">
            <i class="bi bi-box-seam me-2"></i>ERP v2
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/index.php">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboard
                    </a>
                </li>
                
                <!-- Jobs (Phase 2) -->
                <?php if ($rbac->can('view', 'JOB')): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/modules/jobs/">
                        <i class="bi bi-briefcase me-1"></i>Jobs
                    </a>
                </li>
                <?php endif; ?>
                
                <!-- Planning (Phase 5) -->
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/modules/planning/">
                        <i class="bi bi-calendar-check me-1"></i>Planning
                    </a>
                </li>
                
                <!-- Logistics Dropdown (Phase 5) -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-truck me-1"></i>Logistics
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/logistics/dispatch/">Dispatch</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/logistics/routes/">Routes</a></li>
                    </ul>
                </li>
                
                <!-- Warehouse Dropdown -->
                <?php if ($rbac->can('view', 'WH') || $auth->hasRole(ROLE_WAREHOUSE) || $auth->isAdmin()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-box-seam me-1"></i>Warehouse
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/warehouse/">Stock Overview</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/warehouse/movements.php">Stock Movements</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/warehouse/receive.php">WH Receive</a></li>
                    </ul>
                </li>
                <?php endif; ?>
                
                <!-- Procurement Dropdown -->
                <?php if ($rbac->can('view', 'PR') || $rbac->can('view', 'PO')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-cart me-1"></i>Procurement
                    </a>
                    <ul class="dropdown-menu">
                        <?php if ($rbac->can('view', 'PR')): ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/procurement/pr/">Purchase Requests</a></li>
                        <?php endif; ?>
                        <?php if ($rbac->can('view', 'PO')): ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/procurement/po/">Purchase Orders</a></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/procurement/gr/">Goods Receipts</a></li>
                    </ul>
                </li>
                <?php endif; ?>
                
                <!-- HR Dropdown -->
                <?php if ($auth->hasRole(ROLE_HRM) || $auth->isAdmin() || $auth->hasRole(ROLE_MANAGER)): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-people me-1"></i>HR
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/hrm/people/">People</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/hrm/salary/">Salary</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/hrm/overtime/">Overtime</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/timesheet/">Timesheet</a></li>
                    </ul>
                </li>
                <?php endif; ?>
                
                <!-- Accounting Dropdown -->
                <?php if ($rbac->can('view', 'INVOICE') || $auth->hasRole(ROLE_ACCOUNTANT) || $auth->isAdmin()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-calculator me-1"></i>Accounting
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/accounting/invoices/">Invoices</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/accounting/payments/">Payments</a></li>
                    </ul>
                </li>
                <?php endif; ?>
                
                <!-- Admin Menu -->
                <?php if ($auth->isAdmin() || $auth->hasRole(ROLE_MANAGER)): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" data-testid="nav-admin">
                        <i class="bi bi-gear me-1"></i>Admin
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/admin/">Dashboard</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/admin/users.php">Users</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/admin/roles.php">Roles</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/admin/permissions.php">Permissions</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/admin/item_types.php">Item Types</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/admin/doc_numbers.php">Document Numbers</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/admin/line_bindings.php">LINE Bindings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/admin/audit_logs.php">Audit Logs</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            
            <!-- User Menu -->
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i>
                        <?= e($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?>
                        <span class="badge bg-secondary ms-1">
                            <?= e(implode(', ', $userRoles)) ?>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/auth/profile.php">
                            <i class="bi bi-person me-2"></i>Profile
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="<?= BASE_URL ?>/modules/auth/logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>Logout
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
