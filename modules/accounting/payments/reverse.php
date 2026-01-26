<?php
/**
 * Payment Reversal
 * Accounting Module - ERP v2
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../PaymentService.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: ACC, ADM only
if (!$auth->isAdmin() && !$auth->hasRole(ROLE_ACCOUNTANT)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: /4erpv2/index.php');
    exit;
}

$paymentId = (int)($_GET['id'] ?? 0);
if (!$paymentId) {
    setFlash('error', 'Invalid Payment ID');
    header('Location: index.php');
    exit;
}

$paymentService = new PaymentService();
$payment = $paymentService->getById($paymentId);

if (!$payment) {
    setFlash('error', 'Payment not found');
    header('Location: index.php');
    exit;
}

if ($payment['status'] === 'Reversed') {
    setFlash('error', 'This payment is already reversed');
    header('Location: index.php');
    exit;
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim($_POST['reason'] ?? '');
    
    if (empty($reason)) {
        setFlash('error', 'Please provide a reason for reversal');
    } else {
        $result = $paymentService->reversePayment($paymentId, $reason);
        
        if ($result['success']) {
            setFlash('success', 'Payment reversed successfully. New Reversal ID: ' . $result['reversal_no']);
            header('Location: index.php');
            exit;
        } else {
            setFlash('error', 'Error: ' . ($result['error'] ?? 'Unknown error'));
        }
    }
}

$pageTitle = 'Reverse Payment - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row justify-content-center mt-5">
    <div class="col-md-6">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Payment Reversal
            </div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <strong>Warning:</strong> You are about to reverse payment <strong><?= e($payment['payment_no']) ?></strong>.
                    This action cannot be undone and will create a contra-entry in the ledger.
                </div>
                
                <table class="table table-bordered mb-4">
                    <tr>
                        <th class="bg-light w-25">Payment No</th>
                        <td><?= e($payment['payment_no']) ?></td>
                    </tr>
                    <tr>
                        <th class="bg-light">Amount</th>
                        <td><?= number_format($payment['amount'], 2) ?></td>
                    </tr>
                    <tr>
                        <th class="bg-light">Method</th>
                        <td><?= e($payment['payment_method']) ?></td>
                    </tr>
                    <tr>
                        <th class="bg-light">Date</th>
                        <td><?= e($payment['payment_date']) ?></td>
                    </tr>
                </table>
                
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Reversal Reason <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" required placeholder="e.g. Wrong amount entered, Duplicate payment..."></textarea>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="index.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Confirm Reversal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
