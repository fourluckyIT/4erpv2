<?php
/**
 * Invoice List
 * Accounting Module - ERP v2
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../InvoiceService.php';

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
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$where = ['1=1'];
$params = [];

if ($status) {
    $where[] = 'inv.status = :status';
    $params['status'] = $status;
}
if ($dateFrom) {
    $where[] = 'inv.invoice_date >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo) {
    $where[] = 'inv.invoice_date <= :date_to';
    $params['date_to'] = $dateTo;
}

$sql = "SELECT inv.*, 
               c.name as customer_name,
               j.job_number
        FROM ar_invoices inv
        LEFT JOIN customers c ON inv.customer_id = c.id
        LEFT JOIN jobs j ON inv.job_id = j.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY inv.created_at DESC
        LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Stats
$stats = $db->query("
    SELECT status, COUNT(*) as cnt, SUM(total_amount) as total
    FROM ar_invoices
    GROUP BY status
")->fetchAll(PDO::FETCH_UNIQUE);

$pageTitle = 'Invoices - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="bi bi-receipt me-2"></i>Invoices
        </h2>
        <p class="text-muted">จัดการใบแจ้งหนี้</p>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-bg-secondary">
            <div class="card-body">
                <h5>Draft</h5>
                <h3><?= number_format($stats['Draft']['cnt'] ?? 0) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-primary">
            <div class="card-body">
                <h5>Issued</h5>
                <h3><?= number_format($stats['Issued']['cnt'] ?? 0) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-warning">
            <div class="card-body">
                <h5>Partial Paid</h5>
                <h3><?= number_format($stats['PartialPaid']['cnt'] ?? 0) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-success">
            <div class="card-body">
                <h5>Paid</h5>
                <h3><?= number_format($stats['Paid']['cnt'] ?? 0) ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <option value="Draft" <?= $status === 'Draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="Issued" <?= $status === 'Issued' ? 'selected' : '' ?>>Issued</option>
                    <option value="PartialPaid" <?= $status === 'PartialPaid' ? 'selected' : '' ?>>Partial Paid</option>
                    <option value="Paid" <?= $status === 'Paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="Voided" <?= $status === 'Voided' ? 'selected' : '' ?>>Voided</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search"></i> Filter
                </button>
                <a href="index.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Invoice Table -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-2"></i>Invoices (<?= count($invoices) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Job</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Balance</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No invoices found</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td><strong><?= e($inv['invoice_number']) ?></strong></td>
                            <td><?= $inv['invoice_date'] ?></td>
                            <td><?= e($inv['customer_name'] ?? '-') ?></td>
                            <td><?= e($inv['job_number'] ?? '-') ?></td>
                            <td class="text-end"><?= number_format($inv['total_amount'], 2) ?></td>
                            <td class="text-end <?= $inv['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= number_format($inv['balance'], 2) ?>
                            </td>
                            <td>
                                <?php
                                $statusColor = match($inv['status']) {
                                    'Draft' => 'secondary',
                                    'Issued' => 'primary',
                                    'PartialPaid' => 'warning',
                                    'Paid' => 'success',
                                    'Voided' => 'danger',
                                    default => 'secondary'
                                };
                                ?>
                                <span class="badge bg-<?= $statusColor ?>"><?= e($inv['status']) ?></span>
                            </td>
                            <td>
                                <a href="view.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i>
                                </a>
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
