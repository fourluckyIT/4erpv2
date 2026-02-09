<?php
/**
 * AP Payments List
 * Accounts Payable Module - 4ERP
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/APPayment.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: ACC, ADM, MGR can view AP
if (!$auth->isAdmin() && !$auth->hasRole(ROLE_ACCOUNTANT) && !$auth->hasRole(ROLE_MANAGER)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: /4erpv2/index.php');
    exit;
}

$apPayment = new APPayment();
$db = getDB();

// Filters
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$filters = [];
if ($status) $filters['status'] = $status;
if ($dateFrom) $filters['date_from'] = $dateFrom;
if ($dateTo) $filters['date_to'] = $dateTo;

$payments = $apPayment->getList($filters);

// Stats
$summary = $apPayment->getSummaryByStatus();
$summaryMap = [];
foreach ($summary as $s) {
    $summaryMap[$s['status']] = $s;
}

$pendingCount = $apPayment->getPendingCount();

$pageTitle = 'AP Payments - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-cash-stack me-2"></i>AP Payments
            </h2>
            <p class="text-muted mb-0">การจ่ายเงินให้ Supplier</p>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-bg-warning">
            <div class="card-body py-2">
                <small>Pending Approval</small>
                <h4 class="mb-0"><?= number_format($summaryMap['Pending']['count'] ?? 0) ?></h4>
                <small>฿<?= number_format($summaryMap['Pending']['total_amount'] ?? 0, 0) ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-info">
            <div class="card-body py-2">
                <small>Approved</small>
                <h4 class="mb-0"><?= number_format($summaryMap['Approved']['count'] ?? 0) ?></h4>
                <small>฿<?= number_format($summaryMap['Approved']['total_amount'] ?? 0, 0) ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-success">
            <div class="card-body py-2">
                <small>Posted</small>
                <h4 class="mb-0"><?= number_format($summaryMap['Posted']['count'] ?? 0) ?></h4>
                <small>฿<?= number_format($summaryMap['Posted']['total_amount'] ?? 0, 0) ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-danger">
            <div class="card-body py-2">
                <small>Reversed</small>
                <h4 class="mb-0"><?= number_format($summaryMap['Reversed']['count'] ?? 0) ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">ทั้งหมด</option>
                    <option value="Pending" <?= $status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Approved" <?= $status === 'Approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="Posted" <?= $status === 'Posted' ? 'selected' : '' ?>>Posted</option>
                    <option value="Reversed" <?= $status === 'Reversed' ? 'selected' : '' ?>>Reversed</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">วันที่จาก</label>
                <input type="date" name="date_from" class="form-control" value="<?= $dateFrom ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">วันที่ถึง</label>
                <input type="date" name="date_to" class="form-control" value="<?= $dateTo ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i>ค้นหา
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Payments List -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่</th>
                        <th>Invoice</th>
                        <th>Supplier</th>
                        <th>วันที่จ่าย</th>
                        <th>วิธีชำระ</th>
                        <th class="text-end">จำนวนเงิน</th>
                        <th>Status</th>
                        <th>ผู้ขอ/อนุมัติ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">ไม่พบข้อมูล</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($payments as $pay): ?>
                    <tr class="<?= $pay['status'] === 'Reversed' ? 'table-secondary' : '' ?>">
                        <td>
                            <a href="view.php?id=<?= $pay['id'] ?>" class="fw-bold">
                                <?= htmlspecialchars($pay['payment_no']) ?>
                            </a>
                        </td>
                        <td>
                            <a href="/4erpv2/modules/accounting/ap/view.php?id=<?= $pay['invoice_id'] ?>">
                                <?= htmlspecialchars($pay['invoice_no']) ?>
                            </a>
                            <?php if ($pay['supplier_invoice_no']): ?>
                            <small class="text-muted d-block"><?= htmlspecialchars($pay['supplier_invoice_no']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($pay['supplier_name']) ?></td>
                        <td><?= date('d/m/Y', strtotime($pay['payment_date'])) ?></td>
                        <td><?= $pay['payment_method'] ?></td>
                        <td class="text-end fw-bold"><?= number_format($pay['amount'], 2) ?></td>
                        <td>
                            <?php
                            $statusClass = match($pay['status']) {
                                'Pending' => 'warning',
                                'Approved' => 'info',
                                'Posted' => 'success',
                                'Reversed' => 'danger',
                                default => 'secondary'
                            };
                            ?>
                            <span class="badge bg-<?= $statusClass ?>"><?= $pay['status'] ?></span>
                        </td>
                        <td>
                            <small>
                                <?= htmlspecialchars($pay['requested_by_name'] ?? '-') ?>
                                <?php if (!empty($pay['approved_by_name'])): ?>
                                / <?= htmlspecialchars($pay['approved_by_name']) ?>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <a href="view.php?id=<?= $pay['id'] ?>" class="btn btn-sm btn-outline-primary">
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

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
