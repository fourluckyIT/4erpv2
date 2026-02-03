<?php
/**
 * Credit Note View
 * Accounting Module - 4ERP
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: ACC, ADM can view
if (!$auth->isAdmin() && !$auth->hasRole(ROLE_ACC) && !$auth->hasRole(ROLE_MANAGER)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: /4erpv2/index.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid credit note ID');
    header('Location: /4erpv2/modules/accounting/invoices/');
    exit;
}

$db = getDB();

$stmt = $db->prepare("
    SELECT cn.*, 
           inv.invoice_number, inv.total_amount as invoice_total,
           c.name as customer_name,
           u.full_name as created_by_name
    FROM ar_credit_notes cn
    LEFT JOIN ar_invoices inv ON cn.invoice_id = inv.id
    LEFT JOIN customers c ON inv.customer_id = c.id
    LEFT JOIN users u ON cn.created_by = u.id
    WHERE cn.id = ?
");
$stmt->execute([$id]);
$cn = $stmt->fetch();

if (!$cn) {
    setFlash('error', 'Credit note not found');
    header('Location: /4erpv2/modules/accounting/invoices/');
    exit;
}

$pageTitle = 'Credit Note: ' . $cn['cn_number'] . ' - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-file-earmark-minus me-2"></i><?= e($cn['cn_number']) ?>
            </h2>
            <p class="text-muted">ใบลดหนี้ (Credit Note)</p>
        </div>
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Invoice
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>Credit Note Details
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th style="width: 30%;">CN Number</th>
                        <td><?= e($cn['cn_number']) ?></td>
                    </tr>
                    <tr>
                        <th>Original Invoice</th>
                        <td>
                            <a href="/4erpv2/modules/accounting/invoices/view.php?id=<?= $cn['invoice_id'] ?>">
                                <?= e($cn['invoice_number']) ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <th>Customer</th>
                        <td><?= e($cn['customer_name'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>CN Date</th>
                        <td><?= $cn['cn_date'] ?></td>
                    </tr>
                    <tr>
                        <th>Amount</th>
                        <td class="fs-4 text-danger">
                            <strong>-<?= number_format($cn['amount'], 2) ?></strong>
                        </td>
                    </tr>
                    <tr>
                        <th>Reason</th>
                        <td><?= e($cn['reason'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td>
                            <span class="badge bg-<?= $cn['status'] === 'Issued' ? 'success' : 'secondary' ?>">
                                <?= e($cn['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Created By</th>
                        <td><?= e($cn['created_by_name'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>Created At</th>
                        <td><?= $cn['created_at'] ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-lightbulb me-2"></i>About Credit Notes
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    Credit Notes are created when an invoice is voided. 
                    They offset the original invoice amount while preserving the audit trail.
                </p>
                <p class="text-muted small">
                    Original Invoice Total: <strong><?= number_format($cn['invoice_total'], 2) ?></strong><br>
                    Credit Note Amount: <strong class="text-danger">-<?= number_format($cn['amount'], 2) ?></strong>
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
