<?php
/**
 * AP Invoice List
 * Accounts Payable Module - 4ERP
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/APInvoice.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: ACC, ADM, MGR can view AP
if (!$auth->isAdmin() && !$auth->hasRole(ROLE_ACCOUNTANT) && !$auth->hasRole(ROLE_MANAGER)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: /4erpv2/index.php');
    exit;
}

$apInvoice = new APInvoice();
$db = getDB();

// Filters
$status = $_GET['status'] ?? '';
$supplierId = $_GET['supplier_id'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$overdue = isset($_GET['overdue']);

$filters = [];
if ($status) $filters['status'] = $status;
if ($supplierId) $filters['supplier_id'] = $supplierId;
if ($dateFrom) $filters['date_from'] = $dateFrom;
if ($dateTo) $filters['date_to'] = $dateTo;
if ($overdue) $filters['overdue'] = true;

$invoices = $apInvoice->getList($filters);

// Stats
$stats = $db->query("
    SELECT status, COUNT(*) as cnt, SUM(total_amount) as total, SUM(total_amount - paid_amount) as balance
    FROM ap_invoices
    GROUP BY status
")->fetchAll(PDO::FETCH_UNIQUE);

// Suppliers for filter
$suppliers = $db->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();

// Totals
$totalOutstanding = $db->query("
    SELECT SUM(total_amount - paid_amount) as total 
    FROM ap_invoices 
    WHERE status IN ('Approved', 'Partial')
")->fetchColumn() ?: 0;

$pageTitle = 'AP Invoices - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-file-earmark-text me-2"></i>AP Invoices
            </h2>
            <p class="text-muted mb-0">ใบแจ้งหนี้จาก Supplier (เจ้าหนี้การค้า)</p>
        </div>
        <a href="create.php" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>สร้างใบแจ้งหนี้
        </a>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card text-bg-secondary">
            <div class="card-body py-2">
                <small>Draft</small>
                <h4 class="mb-0"><?= number_format($stats['Draft']['cnt'] ?? 0) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-bg-info">
            <div class="card-body py-2">
                <small>Submitted</small>
                <h4 class="mb-0"><?= number_format($stats['Submitted']['cnt'] ?? 0) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-bg-primary">
            <div class="card-body py-2">
                <small>Approved</small>
                <h4 class="mb-0"><?= number_format($stats['Approved']['cnt'] ?? 0) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-bg-warning">
            <div class="card-body py-2">
                <small>Partial</small>
                <h4 class="mb-0"><?= number_format($stats['Partial']['cnt'] ?? 0) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-bg-success">
            <div class="card-body py-2">
                <small>Paid</small>
                <h4 class="mb-0"><?= number_format($stats['Paid']['cnt'] ?? 0) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-bg-danger">
            <div class="card-body py-2">
                <small>Outstanding</small>
                <h4 class="mb-0">฿<?= number_format($totalOutstanding, 0) ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">ทั้งหมด</option>
                    <option value="Draft" <?= $status === 'Draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="Submitted" <?= $status === 'Submitted' ? 'selected' : '' ?>>Submitted</option>
                    <option value="Approved" <?= $status === 'Approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="Partial" <?= $status === 'Partial' ? 'selected' : '' ?>>Partial</option>
                    <option value="Paid" <?= $status === 'Paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="Voided" <?= $status === 'Voided' ? 'selected' : '' ?>>Voided</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Supplier</label>
                <select name="supplier_id" class="form-select">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($suppliers as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $supplierId == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">วันที่จาก</label>
                <input type="date" name="date_from" class="form-control" value="<?= $dateFrom ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">วันที่ถึง</label>
                <input type="date" name="date_to" class="form-control" value="<?= $dateTo ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <div class="form-check mt-2">
                    <input type="checkbox" name="overdue" class="form-check-input" <?= $overdue ? 'checked' : '' ?>>
                    <label class="form-check-label">เกินกำหนด</label>
                </div>
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Invoice List -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่</th>
                        <th>เลข Supplier</th>
                        <th>Supplier</th>
                        <th>วันที่</th>
                        <th>ครบกำหนด</th>
                        <th class="text-end">ยอดรวม</th>
                        <th class="text-end">คงเหลือ</th>
                        <th>Matching</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">ไม่พบข้อมูล</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($invoices as $inv): 
                        $balance = $inv['total_amount'] - $inv['paid_amount'];
                        $isOverdue = strtotime($inv['due_date']) < time() && !in_array($inv['status'], ['Paid', 'Voided']);
                    ?>
                    <tr class="<?= $isOverdue ? 'table-danger' : '' ?>">
                        <td>
                            <a href="view.php?id=<?= $inv['id'] ?>" class="fw-bold">
                                <?= htmlspecialchars($inv['invoice_no']) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($inv['supplier_invoice_no'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($inv['supplier_name']) ?></td>
                        <td><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></td>
                        <td>
                            <?= date('d/m/Y', strtotime($inv['due_date'])) ?>
                            <?php if ($isOverdue): ?>
                            <span class="badge bg-danger">เกินกำหนด</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= number_format($inv['total_amount'], 2) ?></td>
                        <td class="text-end <?= $balance > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                            <?= number_format($balance, 2) ?>
                        </td>
                        <td>
                            <?php
                            $matchClass = match($inv['matching_status']) {
                                'Matched' => 'success',
                                'Variance' => 'warning',
                                'Override' => 'info',
                                default => 'secondary'
                            };
                            ?>
                            <span class="badge bg-<?= $matchClass ?>"><?= $inv['matching_status'] ?></span>
                        </td>
                        <td>
                            <?php
                            $statusClass = match($inv['status']) {
                                'Draft' => 'secondary',
                                'Submitted' => 'info',
                                'Approved' => 'primary',
                                'Partial' => 'warning',
                                'Paid' => 'success',
                                'Voided' => 'danger',
                                default => 'secondary'
                            };
                            ?>
                            <span class="badge bg-<?= $statusClass ?>"><?= $inv['status'] ?></span>
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

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
