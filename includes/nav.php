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
        <a class="navbar-brand" href="/4erpv2/index.php">
            <i class="bi bi-box-seam me-2"></i>ERP v2
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link" href="/4erpv2/index.php">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboard
                    </a>
                </li>
                
                <!-- Jobs (Phase 2) -->
                <?php if ($rbac->can('view', 'JOB')): ?>
                <li class="nav-item">
                    <a class="nav-link" href="/4erpv2/modules/jobs/">
                        <i class="bi bi-briefcase me-1"></i>Jobs
                    </a>
                </li>
                <?php endif; ?>
                
                <!-- Planning (Phase 5) -->
                <li class="nav-item">
                    <a class="nav-link" href="/4erpv2/modules/planning/">
                        <i class="bi bi-calendar-check me-1"></i>Planning
                    </a>
                </li>
                
                <!-- Logistics Dropdown (Phase 5) -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-truck me-1"></i>Logistics
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="/4erpv2/modules/logistics/dispatch/">Dispatch</a></li>
                    </ul>
                </li>
                
                <!-- Procurement Dropdown -->
                <?php if ($rbac->can('view', 'PR') || $rbac->can('view', 'PO')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-cart me-1"></i>Procurement
                    </a>
                    <ul class="dropdown-menu">
                        <?php if ($rbac->can('view', 'PR')): ?>
                        <li><a class="dropdown-item" href="/4erpv2/modules/pr/">Purchase Requests</a></li>
                        <?php endif; ?>
                        <?php if ($rbac->can('view', 'PO')): ?>
                        <li><a class="dropdown-item" href="/4erpv2/modules/po/">Purchase Orders</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                
                <!-- Admin Menu -->
                <?php if ($auth->isAdmin() || $auth->hasRole(ROLE_MANAGER)): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-gear me-1"></i>Admin
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="/4erpv2/modules/admin/">Dashboard</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/4erpv2/modules/admin/users.php">Users</a></li>
                        <li><a class="dropdown-item" href="/4erpv2/modules/admin/roles.php">Roles</a></li>
                        <li><a class="dropdown-item" href="/4erpv2/modules/admin/permissions.php">Permissions</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/4erpv2/modules/admin/doc_numbers.php">Document Numbers</a></li>
                        <li><a class="dropdown-item" href="/4erpv2/modules/admin/line_bindings.php">LINE Bindings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/4erpv2/modules/admin/audit_logs.php">Audit Logs</a></li>
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
                        <li><a class="dropdown-item" href="/4erpv2/modules/auth/profile.php">
                            <i class="bi bi-person me-2"></i>Profile
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="/4erpv2/modules/auth/logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>Logout
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
