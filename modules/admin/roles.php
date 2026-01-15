<?php
/**
 * Roles & Permissions View
 * ERP v2 - Phase 1
 * 
 * Note: Roles are fixed per agents.md requirements.
 * This page is view-only for role permissions.
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireRole([ROLE_ADMIN, ROLE_MANAGER]);

$rbac = new RBAC();
$db = getDB();

$pageTitle = 'Roles & Permissions - ERP v2';
require_once __DIR__ . '/../../includes/header.php';

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

if ($selectedRoleId) {
    $stmt = $db->prepare("SELECT * FROM roles WHERE id = ?");
    $stmt->execute([$selectedRoleId]);
    $selectedRole = $stmt->fetch();
    
    if ($selectedRole) {
        $rolePermissions = $rbac->getRolePermissions($selectedRoleId);
    }
}
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
    You can view permissions here but cannot add or remove roles.
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
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-key me-2"></i>
                    Permissions for: <strong><?= e($selectedRole['code']) ?> - <?= e($selectedRole['name']) ?></strong>
                </span>
            </div>
            <div class="card-body">
                <?php if ($selectedRole['description']): ?>
                <p class="text-muted"><?= e($selectedRole['description']) ?></p>
                <?php endif; ?>
                
                <?php if (empty($rolePermissions)): ?>
                <p class="text-muted text-center">No permissions assigned</p>
                <?php else: ?>
                    <?php
                    // Group by entity type
                    $grouped = [];
                    foreach ($rolePermissions as $perm) {
                        $grouped[$perm['entity_type']][] = $perm;
                    }
                    ?>
                    
                    <?php foreach ($grouped as $entityType => $perms): ?>
                    <h6 class="mt-3 mb-2"><?= e($entityType) ?></h6>
                    <div class="row">
                        <?php foreach ($perms as $perm): ?>
                        <div class="col-md-6 mb-2">
                            <div class="d-flex align-items-center">
                                <?php if ($perm['is_granted']): ?>
                                    <i class="bi bi-check-circle text-success me-2"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle text-danger me-2"></i>
                                <?php endif; ?>
                                <span>
                                    <?= e($perm['name']) ?>
                                    <?php if ($perm['entity_status']): ?>
                                        <small class="text-muted">(<?= e($perm['entity_status']) ?>)</small>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-arrow-left-circle text-muted" style="font-size: 3rem;"></i>
                <p class="text-muted mt-3">Select a role to view its permissions</p>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
