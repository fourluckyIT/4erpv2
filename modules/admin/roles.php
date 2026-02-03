<?php
/**
 * Roles & Permissions Management
 * 4ERP - Phase 1
 * 
 * Note: Roles are fixed per agents.md requirements.
 * But permissions can be customized per role.
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireRole([ROLE_ADMIN, ROLE_MANAGER]);

$rbac = new RBAC();
$db = getDB();
$audit = new AuditLog();

// Handle permission toggle
if (isPost() && post('action') === 'toggle_permission') {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('roles.php');
    }
    
    $roleId = (int) post('role_id');
    $permissionId = (int) post('permission_id');
    $grant = post('grant') === '1';
    
    if ($roleId && $permissionId) {
        try {
            if ($grant) {
                $rbac->assignRolePermission($roleId, $permissionId);
                $audit->log('grant_role_permission', 'ROLE', $roleId, null, ['permission_id' => $permissionId]);
            } else {
                $rbac->removeRolePermission($roleId, $permissionId);
                $audit->log('revoke_role_permission', 'ROLE', $roleId, ['permission_id' => $permissionId], null);
            }
            setFlash('success', 'อัปเดต Permission เรียบร้อย');
        } catch (Exception $e) {
            setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
    }
    
    redirect("roles.php?role_id=$roleId");
}

// Get all roles with permission counts
$roles = $db->query("
    SELECT r.*, COUNT(rp.id) as permission_count
    FROM roles r
    LEFT JOIN role_permissions rp ON r.id = rp.role_id AND rp.is_granted = 1
    GROUP BY r.id
    ORDER BY r.id
")->fetchAll();

// Get selected role detail
$selectedRoleId = (int) get('role_id', 0);
$selectedRole = null;
$rolePermissions = [];
$allPermissions = [];

if ($selectedRoleId) {
    $stmt = $db->prepare("SELECT * FROM roles WHERE id = ?");
    $stmt->execute([$selectedRoleId]);
    $selectedRole = $stmt->fetch();
    
    if ($selectedRole) {
        $rolePermissions = $rbac->getRolePermissions($selectedRoleId);
        $allPermissions = $rbac->getAllPermissionsFromDB();
    }
}

$pageTitle = 'Roles & Permissions - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="bi bi-shield-lock me-2"></i>Roles & Permissions
        </h2>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
                <li class="breadcrumb-item active">Roles</li>
            </ol>
        </nav>
    </div>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    <strong>Note:</strong> System roles are fixed per agents.md requirements. 
    You can customize permissions for each role but cannot add or remove roles.
</div>

<div class="row">
    <!-- Role List -->
    <div class="col-md-4 mb-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-list me-2"></i>System Roles
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($roles as $role): ?>
                <a href="?role_id=<?= $role['id'] ?>" 
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $role['id'] == $selectedRoleId ? 'active' : '' ?>">
                    <div>
                        <strong><?= e($role['code']) ?></strong>
                        <br>
                        <small><?= e($role['name']) ?></small>
                    </div>
                    <span class="badge bg-<?= $role['id'] == $selectedRoleId ? 'light text-dark' : 'secondary' ?>">
                        <?= $role['permission_count'] ?> perms
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Role Details -->
    <div class="col-md-8 mb-4">
        <?php if ($selectedRole): ?>
        <div class="card">
            <div class="card-header d-flex align-items-center">
                <span class="ms-auto text-end">
                    <i class="bi bi-key me-2"></i>
                    Permissions for: <strong><?= e($selectedRole['code']) ?> - <?= e($selectedRole['name']) ?></strong>
                </span>
                <span class="badge bg-primary ms-2"><?= count($rolePermissions) ?> / <?= count($allPermissions) ?> permissions</span>
            </div>
            <div class="card-body">
                <?php if ($selectedRole['description']): ?>
                <p class="text-muted"><?= e($selectedRole['description']) ?></p>
                <?php endif; ?>
                
                <?php
                // Create lookup for granted permissions
                $grantedIds = [];
                foreach ($rolePermissions as $rp) {
                    $grantedIds[$rp['id']] = true;
                }
                
                // Group all permissions by entity type
                $grouped = [];
                foreach ($allPermissions as $perm) {
                    $grouped[$perm['entity_type']][] = $perm;
                }
                ?>
                
                <?php if (empty($allPermissions)): ?>
                <p class="text-muted text-center">No permissions defined in system</p>
                <?php else: ?>
                    <?php foreach ($grouped as $entityType => $perms): ?>
                    <div class="mb-4">
                        <h6 class="border-bottom pb-2 mb-3">
                            <i class="bi bi-folder me-2"></i><?= e($entityType) ?>
                            <span class="badge bg-secondary float-end">
                                <?= count(array_filter($perms, fn($p) => isset($grantedIds[$p['id']]))) ?> / <?= count($perms) ?>
                            </span>
                        </h6>
                        <div class="row">
                            <?php foreach ($perms as $perm): ?>
                            <?php $isGranted = isset($grantedIds[$perm['id']]); ?>
                            <div class="col-md-6 col-lg-4 mb-2">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="toggle_permission">
                                    <input type="hidden" name="role_id" value="<?= $selectedRoleId ?>">
                                    <input type="hidden" name="permission_id" value="<?= $perm['id'] ?>">
                                    <input type="hidden" name="grant" value="<?= $isGranted ? '0' : '1' ?>">
                                    <button type="submit" class="btn btn-sm w-100 text-start <?= $isGranted ? 'btn-success' : 'btn-outline-secondary' ?>">
                                        <?php if ($isGranted): ?>
                                            <i class="bi bi-check-circle me-1"></i>
                                        <?php else: ?>
                                            <i class="bi bi-circle me-1"></i>
                                        <?php endif; ?>
                                        <?= e($perm['name']) ?>
                                    </button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-arrow-left-circle text-muted" style="font-size: 3rem;"></i>
                <p class="text-muted mt-3">Select a role to customize its permissions</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Permission Matrix Reference -->
<div class="card mt-4">
    <div class="card-header">
        <i class="bi bi-table me-2"></i>JOB Permission Matrix (Reference from agents.md)
    </div>
    <div class="card-body table-responsive">
        <table class="table table-bordered table-sm">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>View</th>
                    <th>Edit</th>
                    <th>Approve</th>
                    <th>Dispatch</th>
                    <th>Extend</th>
                    <th>Void</th>
                    <th>Close</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><span class="badge badge-status badge-draft">Draft</span></td>
                    <td>ADM SAL PLN MGR</td>
                    <td>ADM SAL PLN</td>
                    <td>–</td>
                    <td>–</td>
                    <td>–</td>
                    <td>–</td>
                    <td>–</td>
                </tr>
                <tr>
                    <td><span class="badge badge-status badge-submitted">Submitted</span></td>
                    <td>ADM SAL PLN MGR</td>
                    <td>ADM SAL PLN</td>
                    <td>ADM MGR PLN</td>
                    <td>–</td>
                    <td>–</td>
                    <td>–</td>
                    <td>–</td>
                </tr>
                <tr>
                    <td><span class="badge badge-status badge-approved">Approved</span></td>
                    <td>ADM PLN MGR ACC</td>
                    <td>PLN ADM</td>
                    <td>–</td>
                    <td>–</td>
                    <td>–</td>
                    <td>ADM MGR</td>
                    <td>–</td>
                </tr>
                <tr>
                    <td><span class="badge badge-status badge-planned">Planned</span></td>
                    <td>ADM PLN MGR</td>
                    <td>PLN</td>
                    <td>–</td>
                    <td>PLN</td>
                    <td>PLN</td>
                    <td>–</td>
                    <td>–</td>
                </tr>
                <tr>
                    <td><span class="badge badge-status badge-dispatched">Dispatched</span></td>
                    <td>ADM PLN MGR WH</td>
                    <td>–</td>
                    <td>–</td>
                    <td>–</td>
                    <td>PLN</td>
                    <td>–</td>
                    <td>–</td>
                </tr>
                <tr>
                    <td><span class="badge badge-status badge-closed">Closed</span></td>
                    <td>ADM ACC MGR</td>
                    <td>–</td>
                    <td>–</td>
                    <td>–</td>
                    <td>–</td>
                    <td>–</td>
                    <td>ADM MGR</td>
                </tr>
            </tbody>
        </table>
        <p class="text-muted small mb-0">
            <i class="bi bi-info-circle me-1"></i>
            This matrix is locked per agents.md. Changes require code modification.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
