<?php
/**
 * Dashboard Configuration Page
 * ERP v2 - Admin Dashboard Visibility Control
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
require_once BASE_PATH . '/includes/header.php';
?>

<style>
    .role-option { cursor: pointer; transition: all 0.2s; border: 2px solid transparent; }
    .role-option:hover { background: #f8f9fa; }
    .role-option.active { background: #e7f1ff; border-color: #0d6efd; }
    .role-option-icon { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    .widget-item { transition: all 0.2s; }
    .widget-item:hover { border-color: #0d6efd !important; background: #f8f9fa; }
    .widget-item.disabled { opacity: 0.5; }
    .widget-item-icon { width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; }
    .size-btn { width: 28px; height: 28px; padding: 0; font-size: 0.7rem; }
    .form-switch .form-check-input { width: 3em; height: 1.5em; cursor: pointer; }
    
    /* Role icon colors */
    .role-adm .role-option-icon { background: #EDE9FE; color: #7C3AED; }
    .role-sal .role-option-icon { background: #FCE7F3; color: #EC4899; }
    .role-pln .role-option-icon { background: #CCFBF1; color: #14B8A6; }
    .role-pur .role-option-icon { background: #FFEDD5; color: #F97316; }
    .role-hr .role-option-icon { background: #EDE9FE; color: #8B5CF6; }
    .role-wh .role-option-icon { background: #CFFAFE; color: #06B6D4; }
    .role-acc .role-option-icon { background: #D1FAE5; color: #10B981; }
    .role-mgr .role-option-icon { background: #FEE2E2; color: #EF4444; }
</style>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">
            <i class="bi bi-sliders text-primary me-2"></i>Dashboard Configuration
        </h1>
        <p class="text-muted mb-0">ควบคุมการแสดงผล Widgets บน Dashboard ของแต่ละ Role</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" id="btn-reset-config">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
        </button>
        <button class="btn btn-primary" id="btn-save-config">
            <i class="bi bi-check-lg me-1"></i>Save Changes
        </button>
    </div>
</div>

<div class="row">
    <!-- Role Selector -->
    <div class="col-md-3">
        <div class="card sticky-top" style="top: 70px;">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-people me-2"></i>Select Role</h5>
            </div>
            <div class="card-body p-2">
                <?php foreach ($roles as $role): ?>
                <div class="role-option role-<?= strtolower($role['code']) ?> d-flex align-items-center gap-2 p-2 rounded mb-1" data-role="<?= e($role['code']) ?>">
                    <div class="role-option-icon rounded">
                        <i class="bi bi-<?= getRoleIcon($role['code']) ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold small"><?= e($role['name']) ?> (<?= e($role['code']) ?>)</div>
                        <div class="text-muted" style="font-size: 0.75rem;"><?= e($role['description']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Widget Configuration -->
    <div class="col-md-9">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-grid-3x3-gap me-2"></i>
                    Configure Widgets for 
                    <span id="selected-role" class="badge bg-secondary">Select a role</span>
                </h5>
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
                    <i class="bi bi-arrow-left" style="font-size: 2rem;"></i>
                    <p class="mt-2">เลือก Role จากด้านซ้ายเพื่อกำหนดค่า Widgets</p>
                </div>
            </div>
        </div>
        
        <!-- Preview Section -->
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-eye me-2"></i>Dashboard Preview</h5>
            </div>
            <div class="card-body bg-light" id="dashboard-preview">
                <div class="text-center text-muted">
                    Select a role to see preview
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/dashboard-config.js"></script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
