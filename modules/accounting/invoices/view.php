<?php
/**
 * Invoice View
 * Accounting Module - 4ERP
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../InvoiceService.php';
require_once __DIR__ . '/../PaymentService.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: ACC, ADM can view
if (!$auth->isAdmin() && !$auth->hasRole(ROLE_ACCOUNTANT)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: /4erpv2/index.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid invoice ID');
    header('Location: index.php');
    exit;
}

$db = getDB();
$invoiceService = new InvoiceService();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'issue') {
        $result = $invoiceService->issueInvoice($id);
        if ($result['success']) {
            setFlash('success', 'ออกใบแจ้งหนี้สำเร็จ');
        } else {
            setFlash('error', $result['error'] ?? 'Failed to issue');
        }
    } elseif ($action === 'void') {
        $reason = trim($_POST['reason'] ?? '');
        if (!$reason) {
            setFlash('error', 'กรุณาระบุเหตุผล');
        } else {
            $result = $invoiceService->voidInvoice($id, $reason);
            if ($result['success']) {
                setFlash('success', 'ยกเลิกใบแจ้งหนี้สำเร็จ (Credit Note #' . ($result['credit_note_id'] ?? '') . ')');
            } else {
                setFlash('error', $result['error'] ?? 'Failed to void');
            }
        }
    }
    
    header('Location: view.php?id=' . $id);
    exit;
}

// Get invoice
$invoice = $invoiceService->getById($id);
if (!$invoice) {
    setFlash('error', 'Invoice not found');
    header('Location: index.php');
    exit;
}

// Get line items
$lineItems = $db->prepare("SELECT * FROM ar_invoice_lines WHERE invoice_id = ?");
$lineItems->execute([$id]);
$lines = $lineItems->fetchAll();

// Get payments
$payments = $db->prepare("
    SELECT p.* FROM payments p 
    WHERE p.invoice_id = ? 
    ORDER BY p.payment_date DESC
");
$payments->execute([$id]);
$paymentList = $payments->fetchAll();

// Get credit notes
$creditNotes = $db->prepare("
    SELECT cn.* FROM ar_credit_notes cn 
    WHERE cn.invoice_id = ? 
    ORDER BY cn.created_at DESC
");
$creditNotes->execute([$id]);
$cnList = $creditNotes->fetchAll();

$pageTitle = 'Invoice: ' . $invoice['invoice_number'] . ' - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-receipt me-2"></i><?= e($invoice['invoice_number']) ?>
            </h2>
            <p class="text-muted">รายละเอียดใบแจ้งหนี้</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to List
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <!-- Invoice Details -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between">
                <span><i class="bi bi-info-circle me-2"></i>Invoice Details</span>
                <?php
                $statusColor = match($invoice['status']) {
                    'Draft' => 'secondary',
                    'Issued' => 'primary',
                    'PartialPaid' => 'warning',
                    'Paid' => 'success',
                    'Voided' => 'danger',
                    default => 'secondary'
                };
                ?>
                <span class="badge bg-<?= $statusColor ?> fs-6"><?= e($invoice['status']) ?></span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless table-sm">
                            <tr><th>Invoice Number</th><td><?= e($invoice['invoice_number']) ?></td></tr>
                            <tr><th>Invoice Date</th><td><?= $invoice['invoice_date'] ?></td></tr>
                            <tr><th>Due Date</th><td><?= $invoice['due_date'] ?? '-' ?></td></tr>
                            <tr><th>Job ID</th><td><?= $invoice['job_id'] ?? '-' ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless table-sm">
                            <tr><th>Customer ID</th><td><?= $invoice['customer_id'] ?></td></tr>
                            <tr><th>Subtotal</th><td><?= number_format($invoice['subtotal'], 2) ?></td></tr>
                            <tr><th>Tax</th><td><?= number_format($invoice['tax_amount'], 2) ?></td></tr>
                            <tr><th>Total</th><td class="fs-5"><strong><?= number_format($invoice['total_amount'], 2) ?></strong></td></tr>
                        </table>
                    </div>
                </div>
                
                <hr>
                
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted">Balance:</span>
                        <span class="fs-4 <?= $invoice['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                            <strong><?= number_format($invoice['balance'], 2) ?></strong>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Line Items -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-list-ul me-2"></i>Line Items
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Description</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $i => $line): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= e($line['description']) ?></td>
                            <td class="text-end"><?= number_format($line['qty'], 2) ?></td>
                            <td class="text-end"><?= number_format($line['unit_price'], 2) ?></td>
                            <td class="text-end"><?= number_format($line['line_total'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($lines)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No line items</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Payments -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between">
                <span><i class="bi bi-cash me-2"></i>Payments</span>
                <?php if ($invoice['status'] === 'Issued' || $invoice['status'] === 'PartialPaid'): ?>
                <a href="/4erpv2/modules/accounting/payments/create.php?invoice_id=<?= $id ?>" class="btn btn-sm btn-success">
                    <i class="bi bi-plus"></i> Record Payment
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Payment #</th>
                            <th>Date</th>
                            <th>Method</th>
                            <th class="text-end">Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentList as $pay): ?>
                        <tr>
                            <td><?= e($pay['payment_number']) ?></td>
                            <td><?= $pay['payment_date'] ?></td>
                            <td><?= e($pay['payment_method']) ?></td>
                            <td class="text-end"><?= number_format($pay['amount'], 2) ?></td>
                            <td>
                                <span class="badge bg-<?= $pay['status'] === 'Reversed' ? 'danger' : 'success' ?>">
                                    <?= e($pay['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($paymentList)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No payments</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Actions -->
        <div class="card mb-3">
            <div class="card-header">
                <i class="bi bi-lightning me-2"></i>Actions
            </div>
            <div class="card-body">
                <?php if ($invoice['status'] === 'Draft'): ?>
                <form method="POST" class="mb-2">
                    <input type="hidden" name="action" value="issue">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-send me-1"></i>Issue Invoice
                    </button>
                </form>
                <?php endif; ?>
                
                <?php if ($invoice['status'] === 'Issued' || $invoice['status'] === 'PartialPaid'): ?>
                <a href="/4erpv2/modules/accounting/payments/create.php?invoice_id=<?= $id ?>" class="btn btn-success w-100 mb-2">
                    <i class="bi bi-cash me-1"></i>Record Payment
                </a>
                
                <hr>
                <form method="POST" onsubmit="return confirm('ต้องการยกเลิกใบแจ้งหนี้นี้?')">
                    <input type="hidden" name="action" value="void">
                    <div class="mb-2">
                        <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason for void" required>
                    </div>
                    <button type="submit" class="btn btn-outline-danger w-100">
                        <i class="bi bi-x-circle me-1"></i>Void (Create Credit Note)
                    </button>
                </form>
                <?php endif; ?>
                
                <?php if ($invoice['status'] === 'Paid' || $invoice['status'] === 'Voided'): ?>
                <p class="text-muted text-center">No actions available for <?= $invoice['status'] ?> invoices.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Credit Notes -->
        <?php if (!empty($cnList)): ?>
        <div class="card">
            <div class="card-header">
                <i class="bi bi-file-earmark-minus me-2"></i>Credit Notes
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($cnList as $cn): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= e($cn['cn_number']) ?></span>
                        <a href="/4erpv2/modules/accounting/credit-notes/view.php?id=<?= $cn['id'] ?>" class="btn btn-sm btn-outline-info">View</a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
