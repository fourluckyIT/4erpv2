<?php
/**
 * Record Payment
 * Accounting Module - 4ERP
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../InvoiceService.php';
require_once __DIR__ . '/../PaymentService.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: ACC, ADM can create
if (!$auth->isAdmin() && !$auth->hasRole(ROLE_ACC)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: /4erpv2/index.php');
    exit;
}

$db = getDB();
$invoiceService = new InvoiceService();
$paymentService = new PaymentService();

$invoiceId = (int)($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? 0);

// Get invoice
$invoice = null;
if ($invoiceId) {
    $invoice = $invoiceService->getById($invoiceId);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    
    if (!$invoiceId || $amount <= 0) {
        setFlash('error', 'กรุณาระบุข้อมูลให้ครบถ้วน');
    } else {
        $result = $paymentService->recordPayment($invoiceId, $amount, $paymentMethod, $paymentDate);
        
        if ($result['success']) {
            setFlash('success', 'บันทึกการรับชำระสำเร็จ: ' . $result['payment_number']);
            header('Location: /4erpv2/modules/accounting/invoices/view.php?id=' . $invoiceId);
            exit;
        } else {
            setFlash('error', $result['error'] ?? 'Failed to record payment');
        }
    }
}

$pageTitle = 'Record Payment - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-cash-coin me-2"></i>Record Payment
            </h2>
            <p class="text-muted">บันทึกการรับชำระเงิน</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to List
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-plus-circle me-2"></i>Payment Details
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php if ($invoice): ?>
                    <input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>">
                    
                    <div class="alert alert-info">
                        <strong>Invoice:</strong> <?= e($invoice['invoice_number']) ?><br>
                        <strong>Total:</strong> <?= number_format($invoice['total_amount'], 2) ?><br>
                        <strong>Balance:</strong> <span class="text-danger"><?= number_format($invoice['balance'], 2) ?></span>
                    </div>
                    <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label">Invoice ID <span class="text-danger">*</span></label>
                        <input type="number" name="invoice_id" class="form-control" required>
                    </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" 
                                   value="<?= $invoice ? $invoice['balance'] : '' ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Payment Date</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="Cash">Cash</option>
                            <option value="Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Credit">Credit Card</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="<?= $invoice ? '/4erpv2/modules/accounting/invoices/view.php?id=' . $invoice['id'] : 'index.php' ?>" 
                           class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-lg me-1"></i>Record Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>Info
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    Recording a payment updates the invoice balance. 
                    If payment equals remaining balance, invoice status changes to "Paid".
                    Otherwise, it becomes "PartialPaid".
                </p>
                <p class="text-muted small">
                    Payments use append-only pattern. To reverse, use the Reverse Payment action 
                    (which creates a reversal entry, not delete).
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
