<?php
/**
 * Main Dashboard
 * 4ERP - Dashboard Configuration Engine + Modern UI
 */

require_once __DIR__ . '/config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
$currentUser = $auth->getCurrentUser();
$userRoles = $auth->getCurrentRoles();
$primaryRole = $userRoles[0] ?? 'SAL';

$db = getDB();

// Initialize Dashboard Config
$dashboardConfig = new DashboardConfig($db);
$dashboardRenderer = new DashboardRenderer($db, $primaryRole);

// Get enabled widgets for this role (with fallback if table doesn't exist)
$enabledWidgets = [];
try {
    $enabledWidgets = $dashboardConfig->getEnabledWidgets($primaryRole);
} catch (Exception $e) {
    // Table might not exist yet - use default widgets
    $enabledWidgets = [
        ['code' => 'stat_total_jobs', 'name' => 'Total Jobs', 'icon' => 'bi-briefcase', 'icon_bg_color' => 'primary', 'category' => 'stats'],
        ['code' => 'stat_pending_approvals', 'name' => 'Pending Approvals', 'icon' => 'bi-hourglass-split', 'icon_bg_color' => 'warning', 'category' => 'stats'],
        ['code' => 'stat_active_users', 'name' => 'Active Users', 'icon' => 'bi-people', 'icon_bg_color' => 'success', 'category' => 'stats'],
        ['code' => 'action_quick_actions', 'name' => 'Quick Actions', 'icon' => 'bi-lightning', 'category' => 'action'],
        ['code' => 'timeline_activity', 'name' => 'Activity Timeline', 'icon' => 'bi-journal-text', 'category' => 'timeline'],
    ];
}

// Role info for display
$roleInfo = [
    'ADM' => ['label' => 'Admin Dashboard', 'icon' => 'bi-shield-lock', 'color' => '#7C3AED', 'desc' => 'ภาพรวมระบบและการจัดการ'],
    'SAL' => ['label' => 'Sales Dashboard', 'icon' => 'bi-graph-up', 'color' => '#EC4899', 'desc' => 'ติดตามงานขายและลูกค้า'],
    'PLN' => ['label' => 'Planner Dashboard', 'icon' => 'bi-calendar-check', 'color' => '#14B8A6', 'desc' => 'วางแผนทรัพยากรและตารางงาน'],
    'PUR' => ['label' => 'Purchase Dashboard', 'icon' => 'bi-cart-check', 'color' => '#F97316', 'desc' => 'จัดการการจัดซื้อจัดจ้าง'],
    'HR' => ['label' => 'HR Dashboard', 'icon' => 'bi-people', 'color' => '#8B5CF6', 'desc' => 'จัดการบุคลากรและเวลาทำงาน'],
    'WH' => ['label' => 'Warehouse Dashboard', 'icon' => 'bi-box-seam', 'color' => '#06B6D4', 'desc' => 'ควบคุมสต็อกและคลังสินค้า'],
    'ACC' => ['label' => 'Accounting Dashboard', 'icon' => 'bi-calculator', 'color' => '#10B981', 'desc' => 'บัญชีและการเงิน'],
    'MGR' => ['label' => 'Manager Dashboard', 'icon' => 'bi-briefcase', 'color' => '#EF4444', 'desc' => 'ภาพรวมและการอนุมัติ']
];

$currentRoleInfo = $roleInfo[$primaryRole] ?? $roleInfo['SAL'];

// Get stats data
function getStatValue($db, $code) {
    switch ($code) {
        case 'stat_total_jobs':
            return $db->query("SELECT COUNT(*) FROM jobs")->fetchColumn();
        case 'stat_revenue':
            $sum = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM ar_invoices WHERE status = 'Paid' AND MONTH(created_at) = MONTH(CURRENT_DATE)")->fetchColumn();
            return '฿ ' . number_format($sum);
        case 'stat_pending_approvals':
            return $db->query("SELECT COUNT(*) FROM jobs WHERE status = 'Submitted'")->fetchColumn();
        case 'stat_active_users':
            return $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
        case 'stat_jobs_awaiting_plan':
            return $db->query("SELECT COUNT(*) FROM jobs WHERE status = 'Approved'")->fetchColumn();
        case 'stat_dispatches_today':
            return $db->query("SELECT COUNT(*) FROM routes WHERE DATE(dispatch_date) = CURRENT_DATE")->fetchColumn();
        case 'stat_stock_items':
            return $db->query("SELECT COUNT(*) FROM items WHERE is_active = 1")->fetchColumn();
        case 'stat_low_stock':
            try {
                return $db->query("
                    SELECT COUNT(*)
                    FROM items i
                    LEFT JOIN item_stock_levels isl
                        ON isl.item_id = i.id AND isl.location = 'WH'
                    WHERE i.is_active = 1
                      AND COALESCE(isl.available, i.min_stock) < i.min_stock
                ")->fetchColumn();
            } catch (Exception $e) {
                return 0;
            }
        case 'stat_outstanding_ar':
            $sum = $db->query("SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM ar_invoices WHERE status NOT IN ('Paid', 'Voided')")->fetchColumn();
            return '฿ ' . number_format($sum);
        case 'stat_total_people':
            return $db->query("SELECT COUNT(*) FROM people WHERE is_active = 1")->fetchColumn();
        case 'stat_pr_pending':
            return $db->query("SELECT COUNT(*) FROM purchase_requests WHERE status IN ('Draft', 'Submitted')")->fetchColumn();
        case 'stat_timesheet_pending':
            return $db->query("SELECT COUNT(*) FROM timesheets WHERE status = 'Pending'")->fetchColumn();
        case 'stat_overdue_invoices':
            return $db->query("SELECT COUNT(*) FROM ar_invoices WHERE status NOT IN ('Paid', 'Voided') AND due_date < CURRENT_DATE")->fetchColumn();
        default:
            return '0';
    }
}

// Get recent audit logs
$recentLogs = [];
try {
    $stmt = $db->query("
        SELECT a.*, u.full_name 
        FROM audit_logs a 
        LEFT JOIN users u ON a.user_id = u.id 
        ORDER BY a.created_at DESC 
        LIMIT 5
    ");
    $recentLogs = $stmt->fetchAll();
} catch (Exception $e) {
    // Table might not exist yet
}

// Get pending approvals
$pendingApprovals = [];
try {
    // Jobs pending approval
    $stmt = $db->query("
        SELECT 'JOB' as type, job_no as doc_no, customer_name, contract_value as amount, created_at, 'Job Approval' as label
        FROM jobs WHERE status = 'Submitted'
        ORDER BY created_at DESC LIMIT 3
    ");
    $pendingApprovals = array_merge($pendingApprovals, $stmt->fetchAll());
    
    // PRs pending
    $stmt = $db->query("
        SELECT 'PR' as type, pr_no as doc_no, '' as customer_name, total_amount as amount, created_at, 'PR Approval' as label
        FROM purchase_requests WHERE status = 'Submitted'
        ORDER BY created_at DESC LIMIT 3
    ");
    $pendingApprovals = array_merge($pendingApprovals, $stmt->fetchAll());
} catch (Exception $e) {
    // Tables might not exist
}

// Helper function to format time
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'เมื่อกี้';
    if ($diff < 3600) return floor($diff / 60) . ' นาทีที่แล้ว';
    if ($diff < 86400) return floor($diff / 3600) . ' ชั่วโมงที่แล้ว';
    if ($diff < 604800) return floor($diff / 86400) . ' วันที่แล้ว';
    return date('d M', $time);
}

// Icon color map
$iconColorMap = [
    'primary' => ['bg' => '#E0E7FF', 'color' => '#4F46E5'],
    'success' => ['bg' => '#D1FAE5', 'color' => '#10B981'],
    'warning' => ['bg' => '#FEF3C7', 'color' => '#F59E0B'],
    'danger' => ['bg' => '#FEE2E2', 'color' => '#EF4444'],
    'info' => ['bg' => '#DBEAFE', 'color' => '#3B82F6'],
];

$pageTitle = $currentRoleInfo['label'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/modern-ui.css" rel="stylesheet">
    <style>
        .flash-message {
            position: fixed;
            top: 80px;
            right: 24px;
            z-index: 1000;
            min-width: 300px;
            padding: 16px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            animation: slideIn 0.3s ease;
        }
        .flash-message.success { background: var(--success-light); color: var(--success); border-left: 4px solid var(--success); }
        .flash-message.error { background: var(--danger-light); color: var(--danger); border-left: 4px solid var(--danger); }
        .flash-message.warning { background: var(--warning-light); color: var(--warning); border-left: 4px solid var(--warning); }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .stat-widget-value { font-size: 2rem; font-weight: 700; color: var(--gray-900); }
        .stat-widget-label { color: var(--gray-500); font-size: 0.9rem; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="app-container">
        <?php require_once __DIR__ . '/includes/modern/sidebar.php'; ?>

        <main class="main-content">
            <?php require_once __DIR__ . '/includes/modern/header.php'; ?>

            <?php
            // Display flash messages
            $flash = getFlash();
            if ($flash): 
            ?>
            <div class="flash-message <?= $flash['type'] === 'error' ? 'error' : e($flash['type']) ?>">
                <?= e($flash['message']) ?>
            </div>
            <?php endif; ?>

            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1 class="page-title">
                            <i class="<?= e($currentRoleInfo['icon']) ?>" style="color: <?= e($currentRoleInfo['color']) ?>;"></i> 
                            <?= e($currentRoleInfo['label']) ?>
                        </h1>
                        <p class="page-subtitle"><?= e($currentRoleInfo['desc']) ?></p>
                    </div>
                    <div style="display: flex; gap: 12px;">
                        <?php if ($auth->isAdmin() || $auth->hasRole(ROLE_MANAGER)): ?>
                        <a href="<?= BASE_URL ?>/modules/admin/dashboard-config/" class="btn btn-outline">
                            <i class="bi bi-sliders"></i> Dashboard Config
                        </a>
                        <?php endif; ?>
                        <button class="btn btn-primary" onclick="window.print()">
                            <i class="bi bi-download"></i> Export Report
                        </button>
                    </div>
                </div>

                <!-- Stat Cards from Engine -->
                <?php
                $statWidgets = array_filter($enabledWidgets, fn($w) => $w['category'] === 'stats');
                if (!empty($statWidgets)):
                ?>
                <div class="stat-cards">
                    <?php foreach ($statWidgets as $widget): ?>
                    <div class="stat-card">
                        <div class="stat-icon <?= e($widget['icon_bg_color'] ?? 'primary') ?>">
                            <i class="<?= e($widget['icon']) ?>" style="font-size: 1.5rem;"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= getStatValue($db, $widget['code']) ?></div>
                            <div class="stat-label"><?= e($widget['name']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Widgets Grid -->
                <div class="widgets-grid">
                    <?php
                    // Quick Actions Widget
                    $actionWidget = array_filter($enabledWidgets, fn($w) => $w['category'] === 'action');
                    if (!empty($actionWidget)):
                    ?>
                    <div class="widget col-4">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-lightning"></i> Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="quick-actions">
                                <?php if ($rbac->can('create', 'JOB')): ?>
                                <a href="<?= BASE_URL ?>/modules/jobs/create.php" class="quick-action">
                                    <div class="quick-action-icon"><i class="bi bi-plus-circle"></i></div>
                                    <span class="quick-action-label">New Job</span>
                                </a>
                                <?php endif; ?>
                                <?php if ($rbac->can('create', 'PR')): ?>
                                <a href="<?= BASE_URL ?>/modules/procurement/pr/create.php" class="quick-action">
                                    <div class="quick-action-icon"><i class="bi bi-file-plus"></i></div>
                                    <span class="quick-action-label">Create PR</span>
                                </a>
                                <?php endif; ?>
                                <?php if ($auth->hasRole(ROLE_PLANNER) || $auth->isAdmin()): ?>
                                <a href="<?= BASE_URL ?>/modules/planning/" class="quick-action">
                                    <div class="quick-action-icon"><i class="bi bi-calendar-plus"></i></div>
                                    <span class="quick-action-label">Plan Job</span>
                                </a>
                                <?php endif; ?>
                                <?php if ($auth->isAdmin()): ?>
                                <a href="<?= BASE_URL ?>/modules/admin/" class="quick-action">
                                    <div class="quick-action-icon"><i class="bi bi-gear"></i></div>
                                    <span class="quick-action-label">Settings</span>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($auth->isAdmin() || $auth->hasRole(ROLE_MANAGER)): ?>
                    <!-- System Health (Admin/Manager) -->
                    <div class="widget col-8">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-heart-pulse"></i> System Health</h3>
                            <span class="badge badge-approved">All Systems Operational</span>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;">
                                <div style="text-align: center; padding: 16px; background: var(--success-light); border-radius: var(--border-radius);">
                                    <i class="bi bi-database-check" style="font-size: 1.5rem; color: var(--success);"></i>
                                    <div style="font-weight: 600; margin-top: 8px;">Database</div>
                                    <div style="font-size: 0.8rem; color: var(--success);">Connected</div>
                                </div>
                                <div style="text-align: center; padding: 16px; background: var(--success-light); border-radius: var(--border-radius);">
                                    <i class="bi bi-hdd-network" style="font-size: 1.5rem; color: var(--success);"></i>
                                    <div style="font-weight: 600; margin-top: 8px;">Server</div>
                                    <div style="font-size: 0.8rem; color: var(--success);">Running</div>
                                </div>
                                <div style="text-align: center; padding: 16px; background: var(--success-light); border-radius: var(--border-radius);">
                                    <i class="bi bi-shield-check" style="font-size: 1.5rem; color: var(--success);"></i>
                                    <div style="font-weight: 600; margin-top: 8px;">Security</div>
                                    <div style="font-size: 0.8rem; color: var(--success);">Active</div>
                                </div>
                                <div style="text-align: center; padding: 16px; background: var(--info-light); border-radius: var(--border-radius);">
                                    <i class="bi bi-person-check" style="font-size: 1.5rem; color: var(--info);"></i>
                                    <div style="font-weight: 600; margin-top: 8px;">Session</div>
                                    <div style="font-size: 0.8rem; color: var(--info);"><?= e($primaryRole) ?> Role</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- My Jobs Widget (Non-Admin) -->
                    <div class="widget col-8">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-briefcase"></i> Recent Jobs</h3>
                            <a href="<?= BASE_URL ?>/modules/jobs/" class="btn btn-sm btn-outline">View All</a>
                        </div>
                        <div class="card-body">
                            <?php
                            try {
                                $stmt = $db->query("SELECT job_no, customer_name, status, job_type, created_at FROM jobs ORDER BY created_at DESC LIMIT 5");
                                $recentJobs = $stmt->fetchAll();
                            } catch (Exception $e) {
                                $recentJobs = [];
                            }
                            ?>
                            <?php if (!empty($recentJobs)): ?>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Job No</th>
                                            <th>Customer</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentJobs as $job): ?>
                                        <tr>
                                            <td><code><?= e($job['job_no']) ?></code></td>
                                            <td><?= e($job['customer_name']) ?></td>
                                            <td><?= e($job['job_type']) ?></td>
                                            <td><span class="badge badge-<?= strtolower(str_replace(' ', '-', $job['status'])) ?>"><?= e($job['status']) ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <p style="text-align: center; color: var(--gray-500); padding: 40px;">No jobs found</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Timeline Widget -->
                    <?php
                    $timelineWidget = array_filter($enabledWidgets, fn($w) => $w['category'] === 'timeline');
                    if (!empty($timelineWidget)):
                    ?>
                    <div class="widget col-6">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-journal-text"></i> Recent Activity</h3>
                            <?php if ($auth->isAdmin()): ?>
                            <a href="<?= BASE_URL ?>/modules/admin/audit_logs.php" class="btn btn-sm btn-outline">View All</a>
                            <?php endif; ?>
                        </div>
                        <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                            <div class="activity-timeline">
                                <?php if (!empty($recentLogs)): ?>
                                    <?php foreach ($recentLogs as $log): ?>
                                    <div class="activity-item">
                                        <div class="activity-time"><?= timeAgo($log['created_at']) ?></div>
                                        <div class="activity-content">
                                            <strong><?= e($log['full_name'] ?? 'System') ?></strong> 
                                            <?= e($log['action'] ?? '') ?> <?= e($log['entity_type'] ?? '') ?>
                                            <?php if ($log['entity_id']): ?>
                                            <code><?= e($log['entity_id']) ?></code>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="activity-item">
                                        <div class="activity-time">Now</div>
                                        <div class="activity-content">No recent activity</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($auth->isAdmin() || $auth->hasRole(ROLE_MANAGER)): ?>
                    <!-- Pending Approvals Table -->
                    <div class="widget col-6">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-hourglass-split"></i> Pending Approvals</h3>
                            <span class="badge badge-warning"><?= count($pendingApprovals) ?> รายการ</span>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($pendingApprovals)): ?>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Document</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($pendingApprovals, 0, 5) as $item): ?>
                                        <tr>
                                            <td><code><?= e($item['doc_no']) ?></code></td>
                                            <td><span class="badge badge-submitted"><?= e($item['label']) ?></span></td>
                                            <td><?= is_numeric($item['amount']) ? '฿ ' . number_format($item['amount']) : e($item['amount']) ?></td>
                                            <td class="table-actions">
                                                <a href="<?= BASE_URL ?>/modules/<?= strtolower($item['type']) === 'job' ? 'jobs' : 'procurement/pr' ?>/view.php?id=<?= e($item['doc_no']) ?>" class="btn btn-sm btn-outline">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <p style="text-align: center; color: var(--gray-500); padding: 40px;">
                                <i class="bi bi-check-circle" style="font-size: 2rem; color: var(--success);"></i><br>
                                No pending approvals
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Chart Placeholder for non-admin -->
                    <div class="widget col-6">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-bar-chart"></i> Performance Overview</h3>
                        </div>
                        <div class="card-body">
                            <div style="height: 200px; display: flex; align-items: center; justify-content: center; background: var(--gray-50); border-radius: var(--border-radius);">
                                <div style="text-align: center; color: var(--gray-400);">
                                    <i class="bi bi-graph-up" style="font-size: 3rem;"></i>
                                    <p style="margin-top: 12px;">Charts coming soon</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- System Info Footer -->
                <div style="margin-top: 24px; padding: 16px 20px; background: var(--white); border-radius: var(--border-radius-lg); box-shadow: var(--shadow);">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                        <div style="display: flex; gap: 24px; color: var(--gray-500); font-size: 0.85rem;">
                            <span><strong>Version:</strong> <?= APP_VERSION ?></span>
                            <span><strong>PHP:</strong> <?= phpversion() ?></span>
                            <span><strong>Time:</strong> <?= date('Y-m-d H:i:s') ?></span>
                        </div>
                        <div style="color: var(--gray-500); font-size: 0.85rem;">
                            <i class="bi bi-heart"></i> Powered by 4ERP
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
    // Auto-hide flash messages
    setTimeout(function() {
        const flash = document.querySelector('.flash-message');
        if (flash) {
            flash.style.opacity = '0';
            flash.style.transform = 'translateX(100%)';
            setTimeout(() => flash.remove(), 300);
        }
    }, 5000);
    </script>
</body>
</html>
