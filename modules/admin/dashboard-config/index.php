<?php
/**
 * Dashboard Configuration Page
 * 4ERP - Admin Dashboard Visibility Control
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

// Check authentication
$auth = new Auth();
if (!$auth->isAuthenticated()) {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit;
}

// Only ADM can access
if (!$auth->hasRole('ADM')) {
    http_response_code(403);
    die('Access denied - Admin only');
}

$db = getDB();
$dashConfig = new DashboardConfig($db);

// Get all roles
$roles = $db->query("SELECT code, name, description FROM roles ORDER BY id")->fetchAll();

// Get widgets by category
$widgetsByCategory = $dashConfig->getWidgetsByCategory();
$categories = DashboardConfig::getCategoryLabels();
$sizes = DashboardConfig::getSizeLabels();

// Helper function
function getRoleIcon($code) {
    $icons = [
        'ADM' => 'shield-lock',
        'SAL' => 'graph-up',
        'PLN' => 'calendar-check',
        'PUR' => 'cart-check',
        'HR' => 'people',
        'WH' => 'box-seam',
        'ACC' => 'calculator',
        'MGR' => 'briefcase'
    ];
    return $icons[$code] ?? 'person';
}

function getRoleBgColor($code) {
    $colors = [
        'ADM' => 'bg-purple-subtle text-purple',
        'SAL' => 'bg-pink-subtle text-pink',
        'PLN' => 'bg-teal-subtle text-teal',
        'PUR' => 'bg-orange-subtle text-orange',
        'HR' => 'bg-indigo-subtle text-indigo',
        'WH' => 'bg-cyan-subtle text-cyan',
        'ACC' => 'bg-success-subtle text-success',
        'MGR' => 'bg-danger-subtle text-danger'
    ];
    return $colors[$code] ?? 'bg-secondary-subtle text-secondary';
}

$pageTitle = 'Dashboard Configuration';
require_once BASE_PATH . '/includes/modern/layout_start.php';
?>

<div class="page-header dashboard-config">
    <div>
        <h1 class="page-title">
            <i class="bi bi-sliders" style="color: var(--primary);"></i> Dashboard Configuration
        </h1>
        <p class="page-subtitle">ควบคุมการแสดงผล Widgets บน Dashboard ของแต่ละ Role</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" id="btn-reset-config">
            <i class="bi bi-arrow-counterclockwise"></i> Reset
        </button>
        <button class="btn btn-primary" id="btn-save-config">
            <i class="bi bi-check-lg"></i> Save Changes
        </button>
    </div>
</div>

<div class="widgets-grid dashboard-config">
    <div class="widget col-4">
        <div class="card role-selector-card">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-people me-2"></i>Select Role</div>
            </div>
            <div class="card-body p-2 role-list">
                <?php foreach ($roles as $role): ?>
                <div class="role-option role-<?= strtolower($role['code']) ?> d-flex align-items-center gap-2 p-2 rounded mb-1" data-role="<?= e($role['code']) ?>">
                    <div class="role-option-icon rounded">
                        <i class="bi bi-<?= getRoleIcon($role['code']) ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold small"><?= e($role['name']) ?> (<?= e($role['code']) ?>)</div>
                        <div class="text-muted role-option-desc"><?= e($role['description']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="widget col-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="card-title">
                    <i class="bi bi-grid-3x3-gap me-2"></i>
                    Configure Widgets
                    <span id="selected-role" class="role-badge role-sal ms-2">Select a role</span>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-success" id="btn-enable-all">
                        <i class="bi bi-check-all"></i> Enable All
                    </button>
                    <button class="btn btn-sm btn-outline-danger" id="btn-disable-all">
                        <i class="bi bi-x-lg"></i> Disable All
                    </button>
                </div>
            </div>
            
            <div class="card-body" id="widget-config-body">
                <div class="text-center text-muted py-5">
                    <i class="bi bi-arrow-left-circle" style="font-size: 2.2rem;"></i>
                    <p class="mt-2">เลือก Role จากด้านซ้ายเพื่อกำหนดค่า Widgets</p>
                </div>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-eye me-2"></i>Dashboard Preview</div>
            </div>
            <div class="card-body preview-panel" id="dashboard-preview">
                <div class="text-center text-muted">
                    Select a role to see preview
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/dashboard-config.js"></script>

<?php require_once BASE_PATH . '/includes/modern/layout_end.php'; ?>
