<?php
/**
 * User Management
 * ERP v2 - Phase 1
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireRole([ROLE_ADMIN, ROLE_MANAGER]);

$rbac = new RBAC();
$auditLog = new AuditLog();
$db = getDB();

$action = get('action', 'list');
$userId = (int) get('id', 0);

// Handle form submissions
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('users.php');
    }
    
    $formAction = post('action');
    
    if ($formAction === 'create') {
        $username = sanitize(post('username'));
        $email = sanitize(post('email'));
        $fullName = sanitize(post('full_name'));
        $password = post('password');
        $roles = post('roles', []);
        
        // Validate
        if (empty($username) || empty($email) || empty($fullName) || empty($password)) {
            setFlash('error', 'กรุณากรอกข้อมูลให้ครบ');
            redirect('users.php?action=create');
        }
        
        // Check duplicate
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            setFlash('error', 'Username หรือ Email นี้มีอยู่แล้ว');
            redirect('users.php?action=create');
        }
        
        // Create user
        $passwordHash = $auth->hashPassword($password);
        $stmt = $db->prepare("
            INSERT INTO users (username, email, full_name, password_hash, is_active, created_at)
            VALUES (?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$username, $email, $fullName, $passwordHash]);
        $newUserId = $db->lastInsertId();
        
        // Assign roles
        foreach ($roles as $roleId) {
            $rbac->assignRole($newUserId, (int)$roleId, $auth->getCurrentUserId());
        }
        
        // Audit log
        $auditLog->log(
            AUDIT_ACTION_CREATE,
            'USER',
            $newUserId,
            null,
            ['username' => $username, 'email' => $email, 'roles' => $roles]
        );
        
        setFlash('success', 'สร้างผู้ใช้ใหม่เรียบร้อย');
        redirect('users.php');
    }
    
    if ($formAction === 'update' && $userId) {
        $email = sanitize(post('email'));
        $fullName = sanitize(post('full_name'));
        $phone = sanitize(post('phone'));
        $isActive = post('is_active') ? 1 : 0;
        $roles = post('roles', []);
        $newPassword = post('new_password');
        
        // Get old data for audit
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $oldUser = $stmt->fetch();
        
        // Update user
        $stmt = $db->prepare("
            UPDATE users SET email = ?, full_name = ?, phone = ?, is_active = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$email, $fullName, $phone, $isActive, $userId]);
        
        // Update password if provided
        if (!empty($newPassword)) {
            $passwordHash = $auth->hashPassword($newPassword);
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$passwordHash, $userId]);
        }
        
        // Update roles - remove all and re-add
        $stmt = $db->prepare("DELETE FROM user_roles WHERE user_id = ?");
        $stmt->execute([$userId]);
        foreach ($roles as $roleId) {
            $rbac->assignRole($userId, (int)$roleId, $auth->getCurrentUserId());
        }
        
        // Audit log
        $auditLog->log(
            AUDIT_ACTION_UPDATE,
            'USER',
            $userId,
            $oldUser,
            ['email' => $email, 'full_name' => $fullName, 'is_active' => $isActive, 'roles' => $roles]
        );
        
        setFlash('success', 'อัพเดทข้อมูลเรียบร้อย');
        redirect('users.php');
    }
}

$pageTitle = 'User Management - ERP v2';
require_once __DIR__ . '/../../includes/header.php';

// Get all roles for dropdown
$roles = $rbac->getAllRoles();
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-people me-2"></i>User Management
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
                    <li class="breadcrumb-item active">Users</li>
                </ol>
            </nav>
        </div>
        <?php if ($action === 'list'): ?>
        <a href="?action=create" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>Add User
        </a>
        <?php else: ?>
        <a href="users.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($action === 'list'): ?>
    <!-- User List -->
    <?php
    $stmt = $db->query("
        SELECT u.*, GROUP_CONCAT(r.code SEPARATOR ', ') as role_codes
        FROM users u
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        GROUP BY u.id
        ORDER BY u.id
    ");
    $users = $stmt->fetchAll();
    ?>
    
    <div class="card">
        <div class="card-header">
            <input type="text" class="form-control form-control-sm w-25" 
                   data-table-search="usersTable" placeholder="Search...">
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0" id="usersTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Roles</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= $user['id'] ?></td>
                        <td><strong><?= e($user['username']) ?></strong></td>
                        <td><?= e($user['full_name']) ?></td>
                        <td><?= e($user['email']) ?></td>
                        <td>
                            <?php 
                            $userRoles = $user['role_codes'] ? explode(', ', $user['role_codes']) : [];
                            foreach ($userRoles as $role): 
                            ?>
                                <span class="badge bg-secondary"><?= e($role) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php if ($user['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?= formatDateTime($user['last_login_at']) ?></td>
                        <td>
                            <a href="?action=edit&id=<?= $user['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($action === 'create'): ?>
    <!-- Create User Form -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-person-plus me-2"></i>Create New User
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="create">
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="username" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" required>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Roles</label>
                    <div class="row">
                        <?php foreach ($roles as $role): ?>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" 
                                       name="roles[]" value="<?= $role['id'] ?>" 
                                       id="role_<?= $role['id'] ?>">
                                <label class="form-check-label" for="role_<?= $role['id'] ?>">
                                    <?= e($role['code']) ?> - <?= e($role['name']) ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check me-1"></i>Create User
                </button>
            </form>
        </div>
    </div>

<?php elseif ($action === 'edit' && $userId): ?>
    <!-- Edit User Form -->
    <?php
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        setFlash('error', 'ไม่พบผู้ใช้');
        redirect('users.php');
    }
    
    // Get user's current roles
    $stmt = $db->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userRoleIds = array_column($stmt->fetchAll(), 'role_id');
    ?>
    
    <div class="card">
        <div class="card-header">
            <i class="bi bi-pencil me-2"></i>Edit User: <?= e($user['username']) ?>
        </div>
        <div class="card-body">
            <form method="POST" action="?action=edit&id=<?= $userId ?>">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="update">
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?= e($user['username']) ?>" disabled>
                        <small class="text-muted">Username cannot be changed</small>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" value="<?= e($user['email']) ?>" required>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" value="<?= e($user['full_name']) ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone" value="<?= e($user['phone'] ?? '') ?>">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password">
                        <small class="text-muted">Leave blank to keep current password</small>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Status</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" 
                                   id="is_active" <?= $user['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Roles</label>
                    <div class="row">
                        <?php foreach ($roles as $role): ?>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" 
                                       name="roles[]" value="<?= $role['id'] ?>" 
                                       id="role_<?= $role['id'] ?>"
                                       <?= in_array($role['id'], $userRoleIds) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="role_<?= $role['id'] ?>">
                                    <?= e($role['code']) ?> - <?= e($role['name']) ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check me-1"></i>Save Changes
                </button>
            </form>
        </div>
    </div>
    
    <!-- User Audit Log -->
    <div class="card mt-4">
        <div class="card-header">
            <i class="bi bi-journal-text me-2"></i>Recent Activity
        </div>
        <div class="card-body">
            <?php $userLogs = $auditLog->getUserLogs($userId, 20); ?>
            <?php if (empty($userLogs)): ?>
                <p class="text-muted text-center">No activity recorded</p>
            <?php else: ?>
                <?php foreach ($userLogs as $log): ?>
                <div class="audit-entry action-<?= e($log['action_name']) ?>">
                    <strong><?= e($log['action_name']) ?></strong>
                    <?= e($log['entity_type']) ?>
                    <?php if ($log['entity_id']): ?>#<?= $log['entity_id'] ?><?php endif; ?>
                    <span class="float-end text-muted"><?= formatDateTime($log['created_at']) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
