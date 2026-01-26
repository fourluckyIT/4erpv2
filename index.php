<?php
/**
 * Main Dashboard
 * ERP v2 - Phase 1
 */

require_once __DIR__ . '/config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
$currentUser = $auth->getCurrentUser();
$userRoles = $auth->getCurrentRoles();

$pageTitle = 'Dashboard - ERP v2';
require_once __DIR__ . '/includes/header.php';

// Get recent audit logs if admin
$recentLogs = [];
if ($auth->isAdmin() || $auth->hasRole(ROLE_MANAGER)) {
    $auditLog = new AuditLog();
    $recentLogs = $auditLog->getRecentLogs(10);
}
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-speedometer2 me-2"></i>Dashboard
            </h2>
            <p class="text-muted mb-0">ยินดีต้อนรับ, <?= e($currentUser['full_name']) ?></p>
        </div>
        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-primary">
            <i class="bi bi-stars me-1"></i>Try New Dashboard
        </a>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number">0</div>
                        <div class="stat-label">Active Jobs</div>
                    </div>
                    <i class="bi bi-briefcase text-primary" style="font-size: 2.5rem; opacity: 0.3;"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number">0</div>
                        <div class="stat-label">Completed This Month</div>
                    </div>
                    <i class="bi bi-check-circle text-success" style="font-size: 2.5rem; opacity: 0.3;"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number">0</div>
                        <div class="stat-label">Pending Approval</div>
                    </div>
                    <i class="bi bi-clock-history text-warning" style="font-size: 2.5rem; opacity: 0.3;"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card danger">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number">0</div>
                        <div class="stat-label">Overdue</div>
                    </div>
                    <i class="bi bi-exclamation-triangle text-danger" style="font-size: 2.5rem; opacity: 0.3;"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Quick Actions -->
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-lightning me-2"></i>Quick Actions
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <?php if ($rbac->can('create', 'JOB')): ?>
                    <a href="/4erpv2/modules/jobs/create.php" class="btn btn-outline-primary">
                        <i class="bi bi-plus-circle me-2"></i>New Job
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($rbac->can('create', 'PR')): ?>
                    <a href="/4erpv2/modules/pr/create.php" class="btn btn-outline-secondary">
                        <i class="bi bi-file-plus me-2"></i>New Purchase Request
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($auth->isAdmin()): ?>
                    <a href="/4erpv2/modules/admin/" class="btn btn-outline-dark">
                        <i class="bi bi-gear me-2"></i>Admin Dashboard
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Activity -->
    <div class="col-lg-8 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-activity me-2"></i>Recent Activity</span>
                <?php if ($auth->isAdmin()): ?>
                <a href="/4erpv2/modules/admin/audit_logs.php" class="btn btn-sm btn-outline-secondary">
                    View All
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($recentLogs)): ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <div class="audit-entry action-<?= e($log['action_name']) ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong><?= e($log['full_name'] ?? $log['username'] ?? 'System') ?></strong>
                                    <span class="badge bg-secondary"><?= e($log['user_role'] ?? '') ?></span>
                                    <br>
                                    <span class="text-muted">
                                        <?= e($log['action_name']) ?> 
                                        <?= e($log['entity_type']) ?>
                                        <?php if ($log['entity_id']): ?>
                                            #<?= e($log['entity_id']) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <small class="text-muted">
                                    <?= formatDateTime($log['created_at'], 'd M H:i') ?>
                                </small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-4">
                        <i class="bi bi-inbox" style="font-size: 2rem;"></i><br>
                        No recent activity
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- System Info for Admins -->
<?php if ($auth->isAdmin()): ?>
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>System Information
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <strong>Version:</strong> <?= APP_VERSION ?>
                    </div>
                    <div class="col-md-4">
                        <strong>PHP Version:</strong> <?= phpversion() ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Server Time:</strong> <?= date('Y-m-d H:i:s') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
