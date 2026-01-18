<?php
/**
 * Payment List
 * Accounting Module - ERP v2
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../PaymentService.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: ACC, ADM can view
if (!$auth->isAdmin() && !$auth->hasRole(ROLE_ACCOUNTANT)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: /4erpv2/index.php');
    exit;
}

$db = getDB();

// Filters
$invoiceId = $_GET['invoice_id'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$status = $_GET['status'] ?? '';

$where = ['1=1'];
$params = [];

if ($invoiceId) {
    $where[] = 'p.invoice_id = :invoice_id';
    $params['invoice_id'] = $invoiceId;
}
if ($dateFrom) {
    $where[] = 'p.payment_date >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo) {
    $where[] = 'p.payment_date <= :date_to';
    $params['date_to'] = $dateTo;
}
if ($status) {
    $where[] = 'p.status = :status';
    $params['status'] = $status;
}

$sql = "SELECT p.*, p.payment_no as payment_number, inv.invoice_no as invoice_number, c.name as customer_name
        FROM payments p
        LEFT JOIN ar_invoices inv ON p.invoice_id = inv.id
        LEFT JOIN customers c ON inv.customer_id = c.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Stats
$stats = [
    'total' => array_sum(array_column($payments, 'amount')),
    'count' => count($payments)
];

$pageTitle = 'Payments - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="bi bi-cash-stack me-2"></i>Payments
        </h2>
        <p class="text-muted">รายการรับชำระเงิน</p>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Invoice ID</label>
                <input type="number" name="invoice_id" class="form-control" value="<?= e($invoiceId) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <option value="Posted" <?= $status === 'Posted' ? 'selected' : '' ?>>Posted</option>
                    <option value="Reversed" <?= $status === 'Reversed' ? 'selected' : '' ?>>Reversed</option>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search"></i> Filter
                </button>
                <a href="index.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Payments Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <span><i class="bi bi-table me-2"></i>Payments (<?= $stats['count'] ?>)</span>
        <span class="text-success">Total: <?= number_format($stats['total'], 2) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Payment #</th>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Method</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No payments found</td></tr>
                    <?php else: ?>
                        <?php foreach ($payments as $pay): ?>
                        <tr>
                            <td><strong><?= e($pay['payment_number']) ?></strong></td>
                            <td><?= $pay['payment_date'] ?></td>
                            <td>
                                <a href="/4erpv2/modules/accounting/invoices/view.php?id=<?= $pay['invoice_id'] ?>">
                                    <?= e($pay['invoice_number'] ?? '#' . $pay['invoice_id']) ?>
                                </a>
                            </td>
                            <td><?= e($pay['customer_name'] ?? '-') ?></td>
                            <td><?= e($pay['payment_method']) ?></td>
                            <td class="text-end"><?= number_format($pay['amount'], 2) ?></td>
                            <td>
                                <span class="badge bg-<?= $pay['status'] === 'Reversed' ? 'danger' : 'success' ?>">
                                    <?= e($pay['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($pay['status'] === 'Posted' && ($auth->isAdmin() || $auth->hasRole(ROLE_ACCOUNTANT))): ?>
                                <a href="reverse.php?id=<?= $pay['id'] ?>" class="btn btn-sm btn-outline-danger" title="Reverse Payment">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
