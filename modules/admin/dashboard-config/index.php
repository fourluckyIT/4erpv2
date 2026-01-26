<?php
/**
 * Dashboard Configuration Page
 * ERP v2 - Admin Dashboard Visibility Control
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit;
}

// Only ADM can access
if (!RBAC::hasRole(Auth::user()['id'], 'ADM')) {
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

$pageTitle = 'Dashboard Configuration';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
    .config-grid {
        display: grid;
        grid-template-columns: 260px 1fr;
        gap: 24px;
    }
    .role-selector {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        padding: 20px;
        position: sticky;
        top: 88px;
        height: fit-content;
    }
    .role-selector-title {
        font-weight: 600;
        margin-bottom: 16px;
        color: var(--gray-700);
    }
    .role-option {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
        margin-bottom: 8px;
        border: 2px solid transparent;
    }
    .role-option:hover {
        background: var(--gray-100);
    }
    .role-option.active {
        background: var(--primary-light);
        border-color: var(--primary);
    }
    .role-option-icon {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
    }
    .role-option-name {
        font-weight: 600;
        font-size: 0.9rem;
    }
    .role-option-desc {
        font-size: 0.75rem;
        color: var(--gray-500);
    }
    
    .widget-config {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .widget-config-header {
        padding: 20px;
        border-bottom: 1px solid var(--gray-200);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .widget-config-body {
        padding: 20px;
    }
    
    .widget-category {
        margin-bottom: 32px;
    }
    .widget-category-title {
        font-weight: 600;
        color: var(--gray-700);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .widget-list {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 16px;
    }
    .widget-item {
        border: 1px solid var(--gray-200);
        border-radius: 8px;
        padding: 16px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        transition: all 0.2s;
    }
    .widget-item:hover {
        border-color: var(--primary);
        background: var(--gray-50);
    }
    .widget-item.disabled {
        opacity: 0.5;
        background: var(--gray-100);
    }
    .widget-item-icon {
        width: 44px;
        height: 44px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }
    .widget-item-content {
        flex: 1;
    }
    .widget-item-title {
        font-weight: 600;
        font-size: 0.9rem;
        margin-bottom: 4px;
    }
    .widget-item-desc {
        font-size: 0.8rem;
        color: var(--gray-500);
    }
    .widget-item-controls {
        display: flex;
        flex-direction: column;
        gap: 8px;
        align-items: flex-end;
    }
    
    .size-selector {
        display: flex;
        gap: 4px;
    }
    .size-btn {
        width: 24px;
        height: 24px;
        border: 1px solid var(--gray-300);
        background: var(--white);
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.6rem;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .size-btn:hover {
        background: var(--gray-100);
    }
    .size-btn.active {
        background: var(--primary);
        color: var(--white);
        border-color: var(--primary);
    }
    
    .preview-section {
        margin-top: 32px;
        padding-top: 32px;
        border-top: 1px solid var(--gray-200);
    }
    .preview-title {
        font-weight: 600;
        color: var(--gray-700);
        margin-bottom: 16px;
    }
    #dashboard-preview {
        background: var(--gray-100);
        border-radius: 8px;
        padding: 20px;
        min-height: 150px;
    }
    
    /* Role colors */
    .role-adm .role-option-icon { background: #EDE9FE; color: #7C3AED; }
    .role-sal .role-option-icon { background: #FCE7F3; color: #EC4899; }
    .role-pln .role-option-icon { background: #CCFBF1; color: #14B8A6; }
    .role-pur .role-option-icon { background: #FFEDD5; color: #F97316; }
    .role-hr .role-option-icon { background: #EDE9FE; color: #8B5CF6; }
    .role-wh .role-option-icon { background: #CFFAFE; color: #06B6D4; }
    .role-acc .role-option-icon { background: #D1FAE5; color: #10B981; }
    .role-mgr .role-option-icon { background: #FEE2E2; color: #EF4444; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-sliders" style="color: var(--primary);"></i> 
            Dashboard Configuration
        </h1>
        <p class="page-subtitle">ควบคุมการแสดงผล Widgets บน Dashboard ของแต่ละ Role</p>
    </div>
    <div style="display: flex; gap: 12px;">
        <button class="btn btn-outline" id="btn-reset-config">
            <i class="bi bi-arrow-counterclockwise"></i> Reset to Default
        </button>
        <button class="btn btn-primary" id="btn-save-config">
            <i class="bi bi-check-lg"></i> Save Changes
        </button>
    </div>
</div>

<div class="config-grid">
    <!-- Role Selector -->
    <div class="role-selector">
        <div class="role-selector-title">
            <i class="bi bi-people"></i> Select Role
        </div>
        
        <?php foreach ($roles as $role): ?>
        <div class="role-option role-<?= strtolower($role['code']) ?>" data-role="<?= $role['code'] ?>">
            <div class="role-option-icon">
                <i class="bi bi-<?= getRoleIcon($role['code']) ?>"></i>
            </div>
            <div>
                <div class="role-option-name"><?= $role['name'] ?> (<?= $role['code'] ?>)</div>
                <div class="role-option-desc"><?= $role['description'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Widget Configuration -->
    <div class="widget-config">
        <div class="widget-config-header">
            <h2 style="font-size: 1.1rem; font-weight: 600;">
                <i class="bi bi-grid-3x3-gap"></i> 
                Configure Widgets for <span id="selected-role" class="badge" style="background: var(--gray-200);">Select a role</span>
            </h2>
            <div style="display: flex; gap: 12px;">
                <button class="btn btn-sm btn-outline" id="btn-enable-all">
                    <i class="bi bi-check-all"></i> Enable All
                </button>
                <button class="btn btn-sm btn-outline" id="btn-disable-all">
                    <i class="bi bi-x-lg"></i> Disable All
                </button>
            </div>
        </div>
        
        <div class="widget-config-body" id="widget-config-body">
            <div style="text-align: center; color: var(--gray-400); padding: 40px;">
                <i class="bi bi-arrow-left" style="font-size: 2rem;"></i>
                <p style="margin-top: 12px;">เลือก Role จากด้านซ้ายเพื่อกำหนดค่า Widgets</p>
            </div>
        </div>
        
        <!-- Preview Section -->
        <div class="preview-section">
            <div class="preview-title">
                <i class="bi bi-eye"></i> Dashboard Preview
            </div>
            <div id="dashboard-preview">
                <div style="text-align: center; color: var(--gray-400);">
                    Select a role to see preview
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/dashboard-config.js"></script>

<?php
require_once BASE_PATH . '/includes/footer.php';

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
?>
