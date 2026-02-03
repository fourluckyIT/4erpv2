<?php
/**
 * LINE Bindings Management
 * 4ERP - Phase 1
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireRole([ROLE_ADMIN]);

$auditLog = new AuditLog();
$db = getDB();

$action = get('action', 'list');

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('line_bindings.php');
    }
    
    $formAction = post('action');
    
    if ($formAction === 'bind') {
        $userId = (int) post('user_id');
        $lineUserId = sanitize(post('line_user_id'));
        $displayName = sanitize(post('display_name'));
        
        if (empty($userId) || empty($lineUserId)) {
            setFlash('error', 'กรุณากรอกข้อมูลให้ครบ');
            redirect('line_bindings.php?action=create');
        }
        
        // Check if user already bound
        $stmt = $db->prepare("SELECT id FROM line_bindings WHERE user_id = ?");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            setFlash('error', 'ผู้ใช้นี้มีการ bind LINE แล้ว');
            redirect('line_bindings.php?action=create');
        }
        
        // Check if LINE userId already used
        $stmt = $db->prepare("SELECT id FROM line_bindings WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        if ($stmt->fetch()) {
            setFlash('error', 'LINE User ID นี้ถูกใช้ไปแล้ว');
            redirect('line_bindings.php?action=create');
        }
        
        // Create binding
        $stmt = $db->prepare("
            INSERT INTO line_bindings (user_id, line_user_id, display_name, is_active, bound_by, created_at)
            VALUES (?, ?, ?, 1, ?, NOW())
        ");
        $stmt->execute([$userId, $lineUserId, $displayName, $auth->getCurrentUserId()]);
        
        $auditLog->log(
            AUDIT_ACTION_LINE_BIND,
            'LINE_BINDING',
            $db->lastInsertId(),
            null,
            ['user_id' => $userId, 'line_user_id' => $lineUserId]
        );
        
        setFlash('success', 'Bind LINE เรียบร้อย');
        redirect('line_bindings.php');
    }
    
    if ($formAction === 'unbind') {
        $bindingId = (int) post('binding_id');
        
        // Get old data
        $stmt = $db->prepare("SELECT * FROM line_bindings WHERE id = ?");
        $stmt->execute([$bindingId]);
        $oldBinding = $stmt->fetch();
        
        if ($oldBinding) {
            $stmt = $db->prepare("DELETE FROM line_bindings WHERE id = ?");
            $stmt->execute([$bindingId]);
            
            $auditLog->log(
                'unbind',
                'LINE_BINDING',
                $bindingId,
                $oldBinding,
                null,
                post('reason', 'Unbound by admin')
            );
            
            setFlash('success', 'ยกเลิกการ bind เรียบร้อย');
        }
        
        redirect('line_bindings.php');
    }
}

$pageTitle = 'LINE Bindings - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';

// Get all bindings
$bindings = $db->query("
    SELECT lb.*, u.username, u.full_name, b.username as bound_by_name
    FROM line_bindings lb
    JOIN users u ON lb.user_id = u.id
    JOIN users b ON lb.bound_by = b.id
    ORDER BY lb.created_at DESC
")->fetchAll();

// Get unbound users for dropdown
$unboundUsers = $db->query("
    SELECT u.id, u.username, u.full_name
    FROM users u
    LEFT JOIN line_bindings lb ON u.id = lb.user_id
    WHERE lb.id IS NULL AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-chat-dots me-2"></i>LINE Bindings
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
                    <li class="breadcrumb-item active">LINE Bindings</li>
                </ol>
            </nav>
        </div>
        <?php if ($action === 'list'): ?>
        <a href="?action=create" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>New Binding
        </a>
        <?php else: ?>
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($action === 'list'): ?>

<div class="card">
    <div class="card-header">
        <i class="bi bi-link me-2"></i>Current Bindings
    </div>
    <?php if (empty($bindings)): ?>
    <div class="card-body text-center py-5">
        <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
        <p class="text-muted mt-3">No LINE bindings yet</p>
    </div>
    <?php else: ?>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>User</th>
                    <th>LINE User ID</th>
                    <th>Display Name</th>
                    <th>Status</th>
                    <th>Bound By</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bindings as $binding): ?>
                <tr>
                    <td>
                        <strong><?= e($binding['username']) ?></strong><br>
                        <small class="text-muted"><?= e($binding['full_name']) ?></small>
                    </td>
                    <td><code><?= e($binding['line_user_id']) ?></code></td>
                    <td><?= e($binding['display_name'] ?? '-') ?></td>
                    <td>
                        <?php if ($binding['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($binding['bound_by_name']) ?></td>
                    <td><?= formatDateTime($binding['created_at']) ?></td>
                    <td>
                        <form method="POST" action="" class="d-inline" 
                              onsubmit="return confirm('ต้องการยกเลิกการ bind หรือไม่?')">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="unbind">
                            <input type="hidden" name="binding_id" value="<?= $binding['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-x-circle"></i> Unbind
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($action === 'create'): ?>

<div class="card">
    <div class="card-header">
        <i class="bi bi-link-45deg me-2"></i>Create New LINE Binding
    </div>
    <div class="card-body">
        <?php if (empty($unboundUsers)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            All users already have LINE bindings.
        </div>
        <?php else: ?>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="bind">
            
            <div class="mb-3">
                <label class="form-label">User <span class="text-danger">*</span></label>
                <select class="form-select" name="user_id" required>
                    <option value="">-- Select User --</option>
                    <?php foreach ($unboundUsers as $user): ?>
                    <option value="<?= $user['id'] ?>">
                        <?= e($user['username']) ?> - <?= e($user['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="mb-3">
                <label class="form-label">LINE User ID <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="line_user_id" required
                       placeholder="Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                <small class="text-muted">Get this from LINE webhook or LINE Login</small>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Display Name</label>
                <input type="text" class="form-control" name="display_name"
                       placeholder="LINE display name (optional)">
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check me-1"></i>Create Binding
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
