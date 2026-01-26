<?php
/**
 * Audit Logs Viewer
 * ERP v2 - Phase 1
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireRole([ROLE_ADMIN, ROLE_MANAGER]);

$auditLog = new AuditLog();
$db = getDB();

// Filters
$filters = [
    'user_id' => get('user_id'),
    'entity_type' => get('entity_type'),
    'action_name' => get('action'),
    'date_from' => get('date_from'),
    'date_to' => get('date_to'),
];

// Get logs
$limit = 100;
$logs = $auditLog->search(array_filter($filters), $limit);

// Get filter options
$users = $db->query("SELECT id, username, full_name FROM users ORDER BY username")->fetchAll();
$entityTypes = $db->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);
$actions = $db->query("SELECT DISTINCT action_name FROM audit_logs ORDER BY action_name")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Audit Logs - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="bi bi-journal-text me-2"></i>Audit Logs
        </h2>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
                <li class="breadcrumb-item active">Audit Logs</li>
            </ol>
        </nav>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-funnel me-2"></i>Filters
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">User</label>
                <select class="form-select form-select-sm" name="user_id">
                    <option value="">All Users</option>
                    <?php foreach ($users as $user): ?>
                    <option value="<?= $user['id'] ?>" <?= $filters['user_id'] == $user['id'] ? 'selected' : '' ?>>
                        <?= e($user['username']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Entity Type</label>
                <select class="form-select form-select-sm" name="entity_type">
                    <option value="">All Types</option>
                    <?php foreach ($entityTypes as $type): ?>
                    <option value="<?= e($type) ?>" <?= $filters['entity_type'] == $type ? 'selected' : '' ?>>
                        <?= e($type) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Action</label>
                <select class="form-select form-select-sm" name="action">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $action): ?>
                    <option value="<?= e($action) ?>" <?= $filters['action_name'] == $action ? 'selected' : '' ?>>
                        <?= e($action) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control form-control-sm" name="date_from" 
                       value="<?= e($filters['date_from'] ?? '') ?>">
            </div>
            
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control form-control-sm" name="date_to"
                       value="<?= e($filters['date_to'] ?? '') ?>">
            </div>
            
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm me-2">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                <a href="audit_logs.php" class="btn btn-outline-secondary btn-sm">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Results -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list me-2"></i>Results (<?= count($logs) ?><?= count($logs) >= $limit ? '+' : '' ?>)</span>
    </div>
    
    <?php if (empty($logs)): ?>
    <div class="card-body text-center py-5">
        <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
        <p class="text-muted mt-3">No logs found</p>
    </div>
    <?php else: ?>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Changes</th>
                        <th>Reason</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr class="audit-entry action-<?= e($log['action_name']) ?>">
                        <td class="text-nowrap">
                            <?= formatDateTime($log['created_at'], 'M d H:i:s') ?>
                        </td>
                        <td>
                            <strong><?= e($log['full_name'] ?? $log['username'] ?? 'System') ?></strong>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?= e($log['user_role'] ?? '-') ?></span>
                        </td>
                        <td>
                            <span class="badge bg-<?= getActionBadgeClass($log['action_name']) ?>">
                                <?= e($log['action_name']) ?>
                            </span>
                        </td>
                        <td>
                            <?= e($log['entity_type']) ?>
                            <?php if ($log['entity_id']): ?>
                                <span class="text-muted">#<?= $log['entity_id'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($log['old_value'] || $log['new_value']): ?>
                            <button type="button" class="btn btn-sm btn-outline-info" 
                                    data-bs-toggle="modal" data-bs-target="#diffModal"
                                    data-old='<?= e($log['old_value'] ?? '{}') ?>'
                                    data-new='<?= e($log['new_value'] ?? '{}') ?>'>
                                <i class="bi bi-diff"></i> View
                            </button>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($log['reason']): ?>
                            <span class="text-truncate d-inline-block" style="max-width: 150px;" 
                                  title="<?= e($log['reason']) ?>">
                                <?= e($log['reason']) ?>
                            </span>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted"><?= e($log['ip_address'] ?? '-') ?></small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Diff Modal -->
<div class="modal fade" id="diffModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Value Changes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Old Value</h6>
                        <pre id="oldValuePre" class="bg-light p-2 rounded" style="max-height: 400px; overflow: auto;"></pre>
                    </div>
                    <div class="col-md-6">
                        <h6>New Value</h6>
                        <pre id="newValuePre" class="bg-light p-2 rounded" style="max-height: 400px; overflow: auto;"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('diffModal').addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    try {
        const oldVal = JSON.parse(button.dataset.old || '{}');
        const newVal = JSON.parse(button.dataset.new || '{}');
        document.getElementById('oldValuePre').textContent = JSON.stringify(oldVal, null, 2);
        document.getElementById('newValuePre').textContent = JSON.stringify(newVal, null, 2);
    } catch (e) {
        document.getElementById('oldValuePre').textContent = button.dataset.old || '-';
        document.getElementById('newValuePre').textContent = button.dataset.new || '-';
    }
});
</script>

<?php 
function getActionBadgeClass($action) {
    return match($action) {
        'create' => 'success',
        'update' => 'primary',
        'delete', 'void', 'cancel' => 'danger',
        'approve' => 'success',
        'login' => 'info',
        'logout' => 'secondary',
        default => 'dark'
    };
}

require_once __DIR__ . '/../../includes/footer.php'; 
?>
