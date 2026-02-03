<?php
/**
 * Custom Permissions Management
 * 4ERP - Phase 1
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireRole([ROLE_ADMIN]);

$rbac = new RBAC();
$auditLog = new AuditLog();
$db = getDB();

$action = get('action', 'list');
$userId = (int) get('user_id', 0);

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('permissions.php');
    }
    
    $formAction = post('action');
    
    if ($formAction === 'assign') {
        $targetUserId = (int) post('user_id');
        $permissionId = (int) post('permission_id');
        $isGranted = post('is_granted') ? true : false;
        $reason = sanitize(post('reason'));
        $expiresAt = post('expires_at') ?: null;
        
        $rbac->assignCustomPermission(
            $targetUserId,
            $permissionId,
            $isGranted,
            $auth->getCurrentUserId(),
            null,
            $reason,
            $expiresAt
        );
        
        $auditLog->log(
            AUDIT_ACTION_PERMISSION_CHANGE,
            'CUSTOM_PERMISSION',
            $targetUserId,
            null,
            ['permission_id' => $permissionId, 'is_granted' => $isGranted, 'reason' => $reason]
        );
        
        setFlash('success', 'Assigned custom permission');
        redirect("permissions.php?action=user&user_id=$targetUserId");
    }
    
    if ($formAction === 'remove') {
        $targetUserId = (int) post('user_id');
        $permissionId = (int) post('permission_id');
        
        $rbac->removeCustomPermission($targetUserId, $permissionId);
        
        $auditLog->log(
            AUDIT_ACTION_PERMISSION_CHANGE,
            'CUSTOM_PERMISSION',
            $targetUserId,
            ['permission_id' => $permissionId],
            null,
            'Removed by admin'
        );
        
        setFlash('success', 'Removed custom permission');
        redirect("permissions.php?action=user&user_id=$targetUserId");
    }
}

$pageTitle = 'Custom Permissions - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';

// Get all users with custom permissions count
$users = $db->query("
    SELECT u.*, COUNT(cp.id) as custom_perm_count
    FROM users u
    LEFT JOIN custom_permissions cp ON u.id = cp.user_id
    GROUP BY u.id
    ORDER BY custom_perm_count DESC, u.username
")->fetchAll();

// Get all permissions
$permissions = $rbac->getAllPermissions();
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-key me-2"></i>Custom Permissions
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
                    <li class="breadcrumb-item active">Custom Permissions</li>
                </ol>
            </nav>
        </div>
        <?php if ($action !== 'list'): ?>
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    Custom permissions override role-based permissions. Use sparingly for special cases.
</div>

<?php if ($action === 'list'): ?>

<div class="card">
    <div class="card-header">
        <i class="bi bi-people me-2"></i>Users with Custom Permissions
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Custom Permissions</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><strong><?= e($user['username']) ?></strong></td>
                    <td><?= e($user['full_name']) ?></td>
                    <td>
                        <?php if ($user['custom_perm_count'] > 0): ?>
                        <span class="badge bg-warning"><?= $user['custom_perm_count'] ?> custom</span>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?action=user&user_id=<?= $user['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-gear"></i> Manage
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'user' && $userId): ?>
    <?php
    // Get user info
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        setFlash('error', 'User not found');
        redirect('permissions.php');
    }
    
    // Get user's custom permissions
    $customPerms = $rbac->getUserCustomPermissions($userId);
    $existingPermIds = array_column($customPerms, 'permission_id');
    ?>
    
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-person me-2"></i>
            Custom Permissions for: <strong><?= e($user['username']) ?> - <?= e($user['full_name']) ?></strong>
        </div>
        <div class="card-body">
            <?php if (empty($customPerms)): ?>
            <p class="text-muted text-center">No custom permissions assigned</p>
            <?php else: ?>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Permission</th>
                        <th>Entity</th>
                        <th>Action</th>
                        <th>Status</th>
                        <th>Reason</th>
                        <th>Expires</th>
                        <th>Assigned By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customPerms as $perm): ?>
                    <tr>
                        <td><?= e($perm['permission_name']) ?></td>
                        <td><?= e($perm['entity_type']) ?></td>
                        <td><?= e($perm['action']) ?></td>
                        <td>
                            <?php if ($perm['is_granted']): ?>
                            <span class="badge bg-success">Granted</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Revoked</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($perm['reason'] ?? '-') ?></td>
                        <td><?= $perm['expires_at'] ? formatDate($perm['expires_at']) : 'Never' ?></td>
                        <td><?= e($perm['assigned_by_name']) ?></td>
                        <td>
                            <form method="POST" action="" class="d-inline" 
                                  onsubmit="return confirm('Remove this permission?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="user_id" value="<?= $userId ?>">
                                <input type="hidden" name="permission_id" value="<?= $perm['permission_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Add New Permission -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-plus-circle me-2"></i>Assign New Custom Permission
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="user_id" value="<?= $userId ?>">
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Permission <span class="text-danger">*</span></label>
                        <select class="form-select" name="permission_id" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($permissions as $perm): ?>
                            <option value="<?= $perm['id'] ?>" <?= in_array($perm['id'], $existingPermIds) ? 'disabled' : '' ?>>
                                <?= e($perm['entity_type']) ?> - <?= e($perm['name']) ?>
                                <?= in_array($perm['id'], $existingPermIds) ? '(already assigned)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Grant/Revoke</label>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="is_granted" id="is_granted" checked>
                            <label class="form-check-label" for="is_granted">Grant</label>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Reason</label>
                        <input type="text" class="form-control" name="reason" placeholder="Why assigning this?">
                    </div>
                    
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Expires</label>
                        <input type="date" class="form-control" name="expires_at">
                    </div>
                    
                    <div class="col-md-1 mb-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
