<?php
/**
 * Document Number Settings
 * ERP v2 - Phase 1
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireRole([ROLE_ADMIN]);

$auditLog = new AuditLog();
$db = getDB();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('doc_numbers.php');
    }
    
    $docType = sanitize(post('doc_type'));
    $prefix = sanitize(post('prefix'));
    $nextNumber = (int) post('next_number', 1);
    $padding = (int) post('padding', 5);
    
    if (empty($docType) || empty($prefix)) {
        setFlash('error', 'กรุณากรอกข้อมูลให้ครบ');
        redirect('doc_numbers.php');
    }
    
    // Get old data
    $stmt = $db->prepare("SELECT * FROM doc_number_settings WHERE doc_type = ?");
    $stmt->execute([$docType]);
    $oldData = $stmt->fetch();
    
    if ($oldData) {
        // Update existing
        $stmt = $db->prepare("
            UPDATE doc_number_settings 
            SET prefix = ?, next_number = ?, padding = ?, updated_by = ?, updated_at = NOW()
            WHERE doc_type = ?
        ");
        $stmt->execute([$prefix, $nextNumber, $padding, $auth->getCurrentUserId(), $docType]);
        
        $auditLog->log(
            AUDIT_ACTION_UPDATE,
            'DOC_NUMBER_SETTINGS',
            $oldData['id'],
            $oldData,
            ['prefix' => $prefix, 'next_number' => $nextNumber, 'padding' => $padding]
        );
    } else {
        // Insert new
        $stmt = $db->prepare("
            INSERT INTO doc_number_settings (doc_type, prefix, current_year, next_number, padding, reset_yearly, updated_by)
            VALUES (?, ?, YEAR(NOW()), ?, ?, 1, ?)
        ");
        $stmt->execute([$docType, $prefix, $nextNumber, $padding, $auth->getCurrentUserId()]);
        
        $auditLog->log(
            AUDIT_ACTION_CREATE,
            'DOC_NUMBER_SETTINGS',
            $db->lastInsertId(),
            null,
            ['doc_type' => $docType, 'prefix' => $prefix, 'next_number' => $nextNumber]
        );
    }
    
    setFlash('success', 'บันทึกการตั้งค่าเรียบร้อย');
    redirect('doc_numbers.php');
}

$pageTitle = 'Document Numbers - ERP v2';
require_once __DIR__ . '/../../includes/header.php';

// Get all settings
$settings = $db->query("SELECT * FROM doc_number_settings ORDER BY doc_type")->fetchAll();
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="bi bi-hash me-2"></i>Document Number Settings
        </h2>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
                <li class="breadcrumb-item active">Document Numbers</li>
            </ol>
        </nav>
    </div>
</div>

<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong>Warning:</strong> Changing next_number may cause duplicate document numbers. 
    Only modify if you know what you're doing.
</div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-gear me-2"></i>Current Settings
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Document Type</th>
                    <th>Prefix</th>
                    <th>Current Year</th>
                    <th>Next Number</th>
                    <th>Padding</th>
                    <th>Sample Output</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($settings as $setting): ?>
                <tr>
                    <td><strong><?= e($setting['doc_type']) ?></strong></td>
                    <td><?= e($setting['prefix']) ?></td>
                    <td><?= e($setting['current_year']) ?></td>
                    <td><?= e($setting['next_number']) ?></td>
                    <td><?= e($setting['padding']) ?></td>
                    <td>
                        <code><?= e($setting['prefix']) ?><?= $setting['current_year'] ?>-<?= str_pad($setting['next_number'], $setting['padding'], '0', STR_PAD_LEFT) ?></code>
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                data-bs-toggle="modal" data-bs-target="#editModal"
                                data-doc-type="<?= e($setting['doc_type']) ?>"
                                data-prefix="<?= e($setting['prefix']) ?>"
                                data-next-number="<?= $setting['next_number'] ?>"
                                data-padding="<?= $setting['padding'] ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="doc_type" id="modal_doc_type">
                
                <div class="modal-header">
                    <h5 class="modal-title">Edit Document Number Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Document Type</label>
                        <input type="text" class="form-control" id="modal_doc_type_display" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Prefix</label>
                        <input type="text" class="form-control" name="prefix" id="modal_prefix" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Next Number</label>
                        <input type="number" class="form-control" name="next_number" id="modal_next_number" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Padding (digits)</label>
                        <input type="number" class="form-control" name="padding" id="modal_padding" min="1" max="10" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('editModal').addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    document.getElementById('modal_doc_type').value = button.dataset.docType;
    document.getElementById('modal_doc_type_display').value = button.dataset.docType;
    document.getElementById('modal_prefix').value = button.dataset.prefix;
    document.getElementById('modal_next_number').value = button.dataset.nextNumber;
    document.getElementById('modal_padding').value = button.dataset.padding;
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
