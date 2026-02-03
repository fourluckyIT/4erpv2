<?php
/**
 * Admin Dashboard
 * 4ERP - Phase 1
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireRole([ROLE_ADMIN, ROLE_MANAGER]);

$pageTitle = 'Admin Dashboard - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';

$db = getDB();

// Get stats
$userCount = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
$roleCount = $db->query("SELECT COUNT(*) FROM roles")->fetchColumn();
$logCount = $db->query("SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="bi bi-gear me-2"></i>Admin Dashboard
        </h2>
        <p class="text-muted">Manage users, roles, permissions, and system settings</p>
    </div>
</div>

<!-- Stats -->
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= $userCount ?></div>
                        <div class="stat-label">Active Users</div>
                    </div>
                    <i class="bi bi-people text-primary" style="font-size: 2.5rem; opacity: 0.3;"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4 mb-3">
        <div class="card stat-card info">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= $roleCount ?></div>
                        <div class="stat-label">System Roles</div>
                    </div>
                    <i class="bi bi-shield-lock text-info" style="font-size: 2.5rem; opacity: 0.3;"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4 mb-3">
        <div class="card stat-card success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= $logCount ?></div>
                        <div class="stat-label">Today's Actions</div>
                    </div>
                    <i class="bi bi-journal-text text-success" style="font-size: 2.5rem; opacity: 0.3;"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Links -->
<div class="row">
    <div class="col-md-6 col-lg-4 mb-3">
        <a href="users.php" class="card text-decoration-none">
            <div class="card-body text-center py-4">
                <i class="bi bi-people text-primary" style="font-size: 3rem;"></i>
                <h5 class="mt-3 mb-1 text-dark">User Management</h5>
                <p class="text-muted mb-0">Create, edit, and manage users</p>
            </div>
        </a>
    </div>
    
    <div class="col-md-6 col-lg-4 mb-3">
        <a href="roles.php" class="card text-decoration-none">
            <div class="card-body text-center py-4">
                <i class="bi bi-shield-lock text-info" style="font-size: 3rem;"></i>
                <h5 class="mt-3 mb-1 text-dark">Roles & Permissions</h5>
                <p class="text-muted mb-0">View role permissions</p>
            </div>
        </a>
    </div>
    
    <div class="col-md-6 col-lg-4 mb-3">
        <a href="permissions.php" class="card text-decoration-none">
            <div class="card-body text-center py-4">
                <i class="bi bi-key text-warning" style="font-size: 3rem;"></i>
                <h5 class="mt-3 mb-1 text-dark">Custom Permissions</h5>
                <p class="text-muted mb-0">Assign custom permissions</p>
            </div>
        </a>
    </div>
    
    <div class="col-md-6 col-lg-4 mb-3">
        <a href="doc_numbers.php" class="card text-decoration-none">
            <div class="card-body text-center py-4">
                <i class="bi bi-hash text-secondary" style="font-size: 3rem;"></i>
                <h5 class="mt-3 mb-1 text-dark">Document Numbers</h5>
                <p class="text-muted mb-0">Configure document numbering</p>
            </div>
        </a>
    </div>
    
    <div class="col-md-6 col-lg-4 mb-3">
        <a href="line_bindings.php" class="card text-decoration-none">
            <div class="card-body text-center py-4">
                <i class="bi bi-chat-dots text-success" style="font-size: 3rem;"></i>
                <h5 class="mt-3 mb-1 text-dark">LINE Bindings</h5>
                <p class="text-muted mb-0">Manage LINE user bindings</p>
            </div>
        </a>
    </div>
    
    <div class="col-md-6 col-lg-4 mb-3">
        <a href="audit_logs.php" class="card text-decoration-none">
            <div class="card-body text-center py-4">
                <i class="bi bi-journal-text text-danger" style="font-size: 3rem;"></i>
                <h5 class="mt-3 mb-1 text-dark">Audit Logs</h5>
                <p class="text-muted mb-0">View system activity logs</p>
            </div>
        </a>
    </div>

    <?php if ($auth->isAdmin()): ?>
    <div class="col-md-6 col-lg-4 mb-3">
        <a href="dashboard-config/" class="card text-decoration-none">
            <div class="card-body text-center py-4">
                <i class="bi bi-sliders text-primary" style="font-size: 3rem;"></i>
                <h5 class="mt-3 mb-1 text-dark">Dashboard Config</h5>
                <p class="text-muted mb-0">Configure widgets by role</p>
            </div>
        </a>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
