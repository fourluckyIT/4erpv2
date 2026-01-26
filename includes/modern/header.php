<?php
/**
 * Modern Header Template
 * 4ERP - New UI Design System
 */

$auth = $auth ?? new Auth();
$currentUser = $auth->getCurrentUser();
$userRoles = $auth->getCurrentRoles();
$primaryRole = $userRoles[0] ?? 'SAL';

// Role display info
$roleLabels = [
    'ADM' => 'Admin',
    'SAL' => 'Sale',
    'PLN' => 'Planner',
    'PUR' => 'Purchase',
    'HR' => 'HR',
    'WH' => 'Warehouse',
    'ACC' => 'Accountant',
    'MGR' => 'Manager'
];

$roleClasses = [
    'ADM' => 'role-adm',
    'SAL' => 'role-sal',
    'PLN' => 'role-pln',
    'PUR' => 'role-pur',
    'HR' => 'role-hr',
    'WH' => 'role-wh',
    'ACC' => 'role-acc',
    'MGR' => 'role-mgr'
];

$roleClass = $roleClasses[$primaryRole] ?? 'role-sal';
$initials = strtoupper(substr($currentUser['full_name'] ?? 'U', 0, 2));
?>
<header class="header">
    <div class="header-left">
        <button class="header-toggle" onclick="document.getElementById('sidebar').classList.toggle('collapsed')">
            <i class="bi bi-list" style="font-size: 1.25rem;"></i>
        </button>
        <nav class="header-breadcrumb">
            <a href="<?= BASE_URL ?>/dashboard.php">Dashboard</a>
            <?php if (!empty($breadcrumbs)): ?>
                <?php foreach ($breadcrumbs as $crumb): ?>
                <i class="bi bi-chevron-right"></i>
                <?php if (!empty($crumb['url'])): ?>
                <a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a>
                <?php else: ?>
                <span><?= e($crumb['label']) ?></span>
                <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>
    </div>
    
    <div class="header-right">
        <div class="header-search">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="ค้นหา..." id="globalSearch">
        </div>
        
        <button class="header-icon-btn" id="notifyBtn" title="Notifications" onclick="toggleNotifications()">
            <i class="bi bi-bell" style="font-size: 1.1rem;"></i>
            <span class="badge" id="notifyBadge"></span>
        </button>
        
        <!-- Notification Dropdown -->
        <div class="notification-dropdown" id="notificationDropdown" style="display: none; position: absolute; right: 80px; top: 56px; background: var(--white); border-radius: var(--border-radius); box-shadow: var(--shadow-lg); min-width: 320px; max-height: 400px; overflow-y: auto; z-index: 1000;">
            <div style="padding: 12px 16px; border-bottom: 1px solid var(--gray-200); font-weight: 600;">
                <i class="bi bi-bell me-2"></i>การแจ้งเตือน
            </div>
            <div id="notificationList" style="padding: 8px;">
                <div style="padding: 16px; text-align: center; color: var(--gray-500);">
                    <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                    <p class="mb-0 mt-2">ไม่มีการแจ้งเตือนใหม่</p>
                </div>
            </div>
        </div>
        
        <div class="header-user" id="userDropdown">
            <div class="header-avatar" style="background: var(--<?= $roleClass ?>-bg, #EDE9FE); color: var(--<?= str_replace('role-', 'role-', $roleClass) ?>, #7C3AED);">
                <?= e($initials) ?>
            </div>
            <div class="header-user-info">
                <div class="header-user-name"><?= e($currentUser['full_name'] ?? 'User') ?></div>
                <div class="header-user-role">
                    <span class="role-badge <?= $roleClass ?>"><?= e($primaryRole) ?></span>
                </div>
            </div>
        </div>
        
        <!-- User Dropdown Menu -->
        <div class="user-dropdown-menu" id="userDropdownMenu" style="display: none; position: absolute; right: 24px; top: 56px; background: var(--white); border-radius: var(--border-radius); box-shadow: var(--shadow-lg); min-width: 200px; z-index: 1000;">
            <div style="padding: 12px 16px; border-bottom: 1px solid var(--gray-200);">
                <div style="font-weight: 600;"><?= e($currentUser['full_name'] ?? 'User') ?></div>
                <div style="font-size: 0.8rem; color: var(--gray-500);"><?= e($currentUser['email'] ?? '') ?></div>
            </div>
            <div style="padding: 8px;">
                <a href="<?= BASE_URL ?>/modules/auth/profile.php" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; color: var(--gray-700); text-decoration: none; border-radius: var(--border-radius);">
                    <i class="bi bi-person"></i> Profile
                </a>
                <a href="<?= BASE_URL ?>/index.php" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; color: var(--gray-700); text-decoration: none; border-radius: var(--border-radius);">
                    <i class="bi bi-arrow-left"></i> Classic View
                </a>
                <hr style="margin: 8px 0; border: none; border-top: 1px solid var(--gray-200);">
                <a href="<?= BASE_URL ?>/modules/auth/logout.php" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; color: var(--danger); text-decoration: none; border-radius: var(--border-radius);">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </div>
</header>

<script>
document.getElementById('userDropdown').addEventListener('click', function(e) {
    e.stopPropagation();
    const menu = document.getElementById('userDropdownMenu');
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
    document.getElementById('notificationDropdown').style.display = 'none';
});

function toggleNotifications() {
    event.stopPropagation();
    const dropdown = document.getElementById('notificationDropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    document.getElementById('userDropdownMenu').style.display = 'none';
}

document.addEventListener('click', function() {
    document.getElementById('userDropdownMenu').style.display = 'none';
    document.getElementById('notificationDropdown').style.display = 'none';
});
</script>
