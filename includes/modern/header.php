<?php
/**
 * Modern Header Template
 * 4ERP - New UI Design System
 */

$auth = $auth ?? new Auth();
$rbac = $rbac ?? new RBAC();
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

$backUrl = $backUrl ?? (BASE_URL . '/index.php');
$canViewJobs = $rbac->can('view', 'JOB')
    || $auth->isAdmin()
    || $auth->hasRole(ROLE_SALE)
    || $auth->hasRole(ROLE_PLANNER)
    || $auth->hasRole(ROLE_MANAGER)
    || $auth->hasRole(ROLE_ACCOUNTANT)
    || $auth->hasRole(ROLE_WAREHOUSE);
?>
<header class="header">
    <div class="header-left">
        <button class="header-toggle" type="button" onclick="toggleSidebar(event)">
            <i class="bi bi-list" style="font-size: 1.25rem;"></i>
        </button>
        <nav class="header-breadcrumb">
            <a href="<?= BASE_URL ?>/index.php">Dashboard</a>
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
        <?php if ($canViewJobs): ?>
        <a href="<?= BASE_URL ?>/modules/jobs/" class="btn btn-outline-primary btn-sm header-jobs-btn">
            <i class="bi bi-briefcase"></i>
            <span>Jobs</span>
        </a>
        <?php endif; ?>
        <div class="header-search">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="ค้นหา..." id="globalSearch">
        </div>
        
        <button class="header-icon-btn" id="notifyBtn" title="Notifications" onclick="toggleNotifications(event)">
            <i class="bi bi-bell" style="font-size: 1.1rem;"></i>
            <span class="badge" id="notifyBadge" style="display: none;"></span>
        </button>
        
        <!-- Notification Dropdown -->
        <div class="notification-dropdown" id="notificationDropdown" style="display: none; position: absolute; right: 80px; top: 56px; background: var(--white); border-radius: var(--border-radius); box-shadow: var(--shadow-lg); min-width: 320px; max-height: 400px; overflow-y: auto; z-index: 1000;">
            <div style="padding: 12px 16px; border-bottom: 1px solid var(--gray-200); font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
                <span><i class="bi bi-bell me-2"></i>การแจ้งเตือน</span>
                <button type="button" class="btn btn-sm btn-light" id="notificationMarkAll">อ่านทั้งหมด</button>
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
        <script>
        const BASE_URL = "<?= BASE_URL ?>";
        </script>
</header>

<script>
window.toggleSidebar = function(event) {
    event?.preventDefault();
};

(function initSidebarToggle() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    if (document.querySelector('.sidebar-backdrop')) return;

    const sidebarBackdrop = document.createElement('div');
    sidebarBackdrop.className = 'sidebar-backdrop';
    document.body.appendChild(sidebarBackdrop);

    const isMobileViewport = () => {
        const width = Math.max(
            document.documentElement?.clientWidth || 0,
            window.innerWidth || 0
        );
        return width <= 1024;
    };

    function setMobileOpen(open) {
        sidebar.classList.toggle('open', open);
        sidebarBackdrop.classList.toggle('show', open);
        document.body.classList.toggle('sidebar-open', open);
    }

    function syncSidebarState() {
        if (isMobileViewport()) {
            sidebar.classList.remove('collapsed');
            setMobileOpen(false);
        } else if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
            setMobileOpen(false);
        } else {
            sidebar.classList.remove('collapsed');
            setMobileOpen(false);
        }
    }

    syncSidebarState();
    window.addEventListener('resize', syncSidebarState);

    window.toggleSidebar = function(event) {
        event?.preventDefault();
        if (isMobileViewport()) {
            setMobileOpen(!sidebar.classList.contains('open'));
        } else {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        }
    };

    sidebarBackdrop.addEventListener('click', () => setMobileOpen(false));
    document.addEventListener('keydown', (e) => {
        if (!isMobileViewport()) return;
        if (e.key === 'Escape') setMobileOpen(false);
    });
    sidebar.addEventListener('click', (e) => {
        if (isMobileViewport() && e.target.closest('.nav-item')) {
            setMobileOpen(false);
        }
    });
})();

let notificationsLoaded = false;
const notifyBadge = document.getElementById('notifyBadge');
const notificationList = document.getElementById('notificationList');

function goBackFallback(event) {
    event?.preventDefault();
    event?.stopPropagation();

    const btn = document.getElementById('globalBackBtn');
    const url = btn?.getAttribute('href') || `${BASE_URL}/index.php`;
    window.location.href = url;
}

function placeGlobalBackButton() {
    const btn = document.getElementById('globalBackBtn');
    if (!btn) return;

    const pageHeader = document.querySelector('.page-header');
    if (!pageHeader) return;

    let actions = pageHeader.querySelector('.page-header-actions');
    if (!actions) {
        actions = pageHeader.querySelector(':scope > div:last-child');
        if (!actions || actions === btn || actions.contains(btn)) {
            actions = document.createElement('div');
            pageHeader.appendChild(actions);
        }
        actions.classList.add('page-header-actions');
    }

    if (btn.parentElement !== actions) {
        actions.appendChild(btn);
    }

    const primaryAction = actions.querySelector('.btn-primary, .btn.btn-primary');
    if (primaryAction && btn.previousElementSibling !== primaryAction) {
        primaryAction.insertAdjacentElement('afterend', btn);
    }
}

document.addEventListener('DOMContentLoaded', placeGlobalBackButton);

function formatNotificationTime(value) {
    if (!value) return '';
    const dt = new Date(value.replace(' ', 'T'));
    return dt.toLocaleString('th-TH');
}

function renderNotifications(items) {
    notificationList.innerHTML = '';
    if (!items || items.length === 0) {
        notificationList.innerHTML = `
            <div style="padding: 16px; text-align: center; color: var(--gray-500);">
                <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                <p class="mb-0 mt-2">ไม่มีการแจ้งเตือนใหม่</p>
            </div>
        `;
        return;
    }

    items.forEach(item => {
        const link = document.createElement('a');
        link.href = item.action_url || '#';
        link.className = 'notification-item' + (item.is_read ? '' : ' unread');
        link.dataset.id = item.id;

        const title = document.createElement('div');
        title.className = 'notification-title';
        title.textContent = item.title;

        const message = document.createElement('div');
        message.className = 'notification-message';
        message.textContent = item.message;

        const meta = document.createElement('div');
        meta.className = 'notification-meta';
        meta.textContent = formatNotificationTime(item.created_at);

        link.appendChild(title);
        link.appendChild(message);
        link.appendChild(meta);

        link.addEventListener('click', (e) => {
            const payload = JSON.stringify({ id: item.id });
            fetch(`${BASE_URL}/modules/notifications/api/mark_read.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: payload,
                keepalive: true
            }).then(() => {
                link.classList.remove('unread');
                refreshNotificationBadge();
            }).catch(() => {});

            if (!item.action_url) {
                e.preventDefault();
            }
        });

        notificationList.appendChild(link);
    });
}

async function refreshNotificationBadge() {
    try {
        const res = await fetch(`${BASE_URL}/modules/notifications/api/list.php?limit=1`);
        const data = await res.json();
        if (data.success) {
            notifyBadge.textContent = data.unread_count > 0 ? data.unread_count : '';
            notifyBadge.style.display = data.unread_count > 0 ? 'flex' : 'none';
        }
    } catch (e) {}
}

async function loadNotifications() {
    try {
        const res = await fetch(`${BASE_URL}/modules/notifications/api/list.php?limit=10`);
        const data = await res.json();
        if (data.success) {
            notifyBadge.textContent = data.unread_count > 0 ? data.unread_count : '';
            notifyBadge.style.display = data.unread_count > 0 ? 'flex' : 'none';
            renderNotifications(data.items);
            notificationsLoaded = true;
        }
    } catch (e) {
        notificationList.innerHTML = '<div class="text-danger p-3">โหลดการแจ้งเตือนไม่สำเร็จ</div>';
    }
}

let notificationPollTimer = null;
let notificationPollInFlight = false;
const NOTIFICATION_POLL_INTERVAL_MS = 15000;

async function pollNotifications() {
    if (notificationPollInFlight) return;
    if (document.hidden) return;

    notificationPollInFlight = true;
    try {
        const dropdown = document.getElementById('notificationDropdown');
        const isOpen = dropdown && dropdown.style.display !== 'none';
        if (isOpen) {
            await loadNotifications();
        } else {
            await refreshNotificationBadge();
        }
    } catch (e) {
    } finally {
        notificationPollInFlight = false;
    }
}

function startNotificationPolling() {
    if (notificationPollTimer) return;
    notificationPollTimer = setInterval(pollNotifications, NOTIFICATION_POLL_INTERVAL_MS);
}

function stopNotificationPolling() {
    if (!notificationPollTimer) return;
    clearInterval(notificationPollTimer);
    notificationPollTimer = null;
}

document.getElementById('userDropdown').addEventListener('click', function(e) {
    e.stopPropagation();
    const menu = document.getElementById('userDropdownMenu');
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
    document.getElementById('notificationDropdown').style.display = 'none';
});

function toggleNotifications(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('notificationDropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    document.getElementById('userDropdownMenu').style.display = 'none';
    if (dropdown.style.display === 'block' && !notificationsLoaded) {
        loadNotifications();
    }
}

document.addEventListener('click', function() {
    document.getElementById('userDropdownMenu').style.display = 'none';
    document.getElementById('notificationDropdown').style.display = 'none';
});

document.getElementById('notificationMarkAll')?.addEventListener('click', async function(e) {
    e.stopPropagation();
    try {
        await fetch(`${BASE_URL}/modules/notifications/api/mark_all_read.php`, { method: 'POST' });
        notificationsLoaded = false;
        loadNotifications();
    } catch (err) {}
});

refreshNotificationBadge();
startNotificationPolling();

document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        stopNotificationPolling();
        return;
    }
    pollNotifications();
    startNotificationPolling();
});
</script>
