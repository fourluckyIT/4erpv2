<?php
/**
 * AP Invoice View
 * Accounts Payable Module - 4ERP
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/APInvoice.php';
require_once __DIR__ . '/../../../core/APPayment.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: ACC, ADM, MGR can view AP
if (!$auth->isAdmin() && !$auth->hasRole(ROLE_ACCOUNTANT) && !$auth->hasRole(ROLE_MANAGER)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: /4erpv2/index.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'ไม่พบข้อมูล');
    header('Location: index.php');
    exit;
}

$apInvoice = new APInvoice();
$apPayment = new APPayment();

$invoice = $apInvoice->getById($id);
if (!$invoice) {
    setFlash('error', 'ไม่พบใบแจ้งหนี้');
    header('Location: index.php');
    exit;
}

$lines = $apInvoice->getLines($id);
$payments = $apPayment->getPaymentsForInvoice($id);
$balance = $apInvoice->getBalance($id);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'submit':
            $result = $apInvoice->submit($id);
            if ($result['success']) {
                setFlash('success', 'ส่งใบแจ้งหนี้เรียบร้อย');
            } else {
                setFlash('error', $result['error']);
            }
            break;
            
        case 'approve':
            $notes = $_POST['notes'] ?? null;
            $result = $apInvoice->approve($id, $notes);
            if ($result['success']) {
                setFlash('success', 'อนุมัติใบแจ้งหนี้เรียบร้อย');
            } else {
                setFlash('error', $result['error']);
            }
            break;
            
        case 'void':
            $reason = $_POST['reason'] ?? '';
            if (empty($reason)) {
                setFlash('error', 'กรุณาระบุเหตุผลในการยกเลิก');
            } else {
                $result = $apInvoice->void($id, $reason);
                if ($result['success']) {
                    setFlash('success', "ยกเลิกใบแจ้งหนี้เรียบร้อย (Debit Note: {$result['debit_note_no']})");
                } else {
                    setFlash('error', $result['error']);
                }
            }
            break;
    }
    
    header("Location: view.php?id={$id}");
    exit;
}

$pageTitle = "AP Invoice: {$invoice['invoice_no']} - 4ERP";
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">AP Invoices</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($invoice['invoice_no']) ?></li>
            </ol>
        </nav>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-8">
        <h2 class="mb-0">
            <i class="bi bi-file-earmark-text me-2"></i><?= htmlspecialchars($invoice['invoice_no']) ?>
        </h2>
        <p class="text-muted mb-0">
            Supplier Invoice: <?= htmlspecialchars($invoice['supplier_invoice_no'] ?? '-') ?>
        </p>
    </div>
    <div class="col-md-4 text-end">
        <?php
        $statusClass = match($invoice['status']) {
            'Draft' => 'secondary',
            'Submitted' => 'info',
            'Approved' => 'primary',
            'Partial' => 'warning',
            'Paid' => 'success',
            'Voided' => 'danger',
            default => 'secondary'
        };
        ?>
        <span class="badge bg-<?= $statusClass ?> fs-5"><?= $invoice['status'] ?></span>
    </div>
</div>

<?php displayFlash(); ?>

<div class="row">
    <!-- Left Column: Invoice Details -->
    <div class="col-md-8">
        <!-- Header Info -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">ข้อมูลใบแจ้งหนี้</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th class="text-muted" width="40%">Supplier:</th>
                                <td><?= htmlspecialchars($invoice['supplier_name']) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">PO Number:</th>
                                <td>
                                    <?php if ($invoice['po_number']): ?>
                                    <a href="/4erpv2/modules/procurement/po/view.php?id=<?= $invoice['po_id'] ?>">
                                        <?= htmlspecialchars($invoice['po_number']) ?>
                                    </a>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">GR Number:</th>
                                <td>
                                    <?php if ($invoice['gr_number']): ?>
                                    <a href="/4erpv2/modules/procurement/gr/view.php?id=<?= $invoice['gr_id'] ?>">
                                        <?= htmlspecialchars($invoice['gr_number']) ?>
                                    </a>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">Job:</th>
                                <td>
                                    <?php if ($invoice['job_number']): ?>
                                    <a href="/4erpv2/modules/jobs/view.php?id=<?= $invoice['job_id'] ?>">
                                        <?= htmlspecialchars($invoice['job_number']) ?>
                                    </a>
                                    - <?= htmlspecialchars($invoice['job_scope'] ?? '') ?>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th class="text-muted" width="40%">วันที่ Invoice:</th>
                                <td><?= date('d/m/Y', strtotime($invoice['invoice_date'])) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">วันที่รับ:</th>
                                <td><?= $invoice['received_date'] ? date('d/m/Y', strtotime($invoice['received_date'])) : '-' ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">ครบกำหนดชำระ:</th>
                                <td>
                                    <?= date('d/m/Y', strtotime($invoice['due_date'])) ?>
                                    <?php if (strtotime($invoice['due_date']) < time() && !in_array($invoice['status'], ['Paid', 'Voided'])): ?>
                                    <span class="badge bg-danger">เกินกำหนด</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">3-Way Matching:</th>
                                <td>
                                    <?php
                                    $matchClass = match($invoice['matching_status']) {
                                        'Matched' => 'success',
                                        'Variance' => 'warning',
                                        'Override' => 'info',
                                        default => 'secondary'
                                    };
                                    ?>
                                    <span class="badge bg-<?= $matchClass ?>"><?= $invoice['matching_status'] ?></span>
                                    <?php if ($invoice['matching_notes']): ?>
                                    <small class="text-muted d-block"><?= htmlspecialchars($invoice['matching_notes']) ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Line Items -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">รายการ</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="5%">#</th>
                            <th width="35%">รายการ</th>
                            <th width="10%">ประเภท</th>
                            <th class="text-end" width="15%">จำนวน</th>
                            <th class="text-end" width="15%">ราคา/หน่วย</th>
                            <th class="text-end" width="20%">รวม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $line): ?>
                        <tr>
                            <td><?= $line['line_no'] ?></td>
                            <td>
                                <?= htmlspecialchars($line['description']) ?>
                                <?php if ($line['item_code']): ?>
                                <small class="text-muted d-block"><?= htmlspecialchars($line['item_code']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-secondary"><?= $line['cost_type'] ?></span></td>
                            <td class="text-end"><?= number_format($line['quantity'], 2) ?> <?= $line['unit'] ?></td>
                            <td class="text-end"><?= number_format($line['unit_price'], 2) ?></td>
                            <td class="text-end"><?= number_format($line['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="5" class="text-end"><strong>Subtotal:</strong></td>
                            <td class="text-end"><?= number_format($invoice['subtotal'], 2) ?></td>
                        </tr>
                        <tr>
                            <td colspan="5" class="text-end">VAT <?= $invoice['tax_rate'] ?>%:</td>
                            <td class="text-end"><?= number_format($invoice['tax_amount'], 2) ?></td>
                        </tr>
                        <?php if ($invoice['withholding_amount'] > 0): ?>
                        <tr>
                            <td colspan="5" class="text-end">หัก ณ ที่จ่าย <?= $invoice['withholding_rate'] ?>%:</td>
                            <td class="text-end text-danger">-<?= number_format($invoice['withholding_amount'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="fw-bold">
                            <td colspan="5" class="text-end">ยอดรวม:</td>
                            <td class="text-end"><?= number_format($invoice['total_amount'], 2) ?></td>
                        </tr>
                        <tr class="text-success">
                            <td colspan="5" class="text-end">ชำระแล้ว:</td>
                            <td class="text-end"><?= number_format($invoice['paid_amount'], 2) ?></td>
                        </tr>
                        <tr class="fw-bold <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                            <td colspan="5" class="text-end">คงเหลือ:</td>
                            <td class="text-end"><?= number_format($balance, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Payments -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">การชำระเงิน</h5>
                <?php if (in_array($invoice['status'], ['Approved', 'Partial'])): ?>
                <a href="/4erpv2/modules/accounting/ap-payments/create.php?invoice_id=<?= $id ?>" class="btn btn-sm btn-success">
                    <i class="bi bi-plus-lg me-1"></i>บันทึกการชำระ
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>เลขที่</th>
                            <th>วันที่</th>
                            <th>วิธีชำระ</th>
                            <th>อ้างอิง</th>
                            <th class="text-end">จำนวน</th>
                            <th>Status</th>
                            <th>ผู้ขอ/อนุมัติ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-3">ยังไม่มีการชำระเงิน</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($payments as $pay): ?>
                        <tr class="<?= $pay['status'] === 'Reversed' ? 'table-secondary text-decoration-line-through' : '' ?>">
                            <td>
                                <a href="/4erpv2/modules/accounting/ap-payments/view.php?id=<?= $pay['id'] ?>">
                                    <?= htmlspecialchars($pay['payment_no']) ?>
                                </a>
                            </td>
                            <td><?= date('d/m/Y', strtotime($pay['payment_date'])) ?></td>
                            <td><?= $pay['payment_method'] ?></td>
                            <td><?= htmlspecialchars($pay['reference_no'] ?? '-') ?></td>
                            <td class="text-end"><?= number_format($pay['amount'], 2) ?></td>
                            <td>
                                <?php
                                $payStatusClass = match($pay['status']) {
                                    'Pending' => 'warning',
                                    'Approved' => 'info',
                                    'Posted' => 'success',
                                    'Reversed' => 'danger',
                                    default => 'secondary'
                                };
                                ?>
                                <span class="badge bg-<?= $payStatusClass ?>"><?= $pay['status'] ?></span>
                            </td>
                            <td>
                                <small>
                                    <?= htmlspecialchars($pay['requested_by_name'] ?? '-') ?>
                                    <?php if ($pay['approved_by_name']): ?>
                                    / <?= htmlspecialchars($pay['approved_by_name']) ?>
                                    <?php endif; ?>
                                </small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Notes -->
        <?php if ($invoice['notes']): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">หมายเหตุ</h5>
            </div>
            <div class="card-body">
                <?= nl2br(htmlspecialchars($invoice['notes'])) ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Actions -->
    <div class="col-md-4">
        <!-- Actions Card -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Actions</h5>
            </div>
            <div class="card-body">
                <?php if ($invoice['status'] === 'Draft'): ?>
                <form method="post" class="mb-2">
                    <input type="hidden" name="action" value="submit">
                    <button type="submit" class="btn btn-info w-100">
                        <i class="bi bi-send me-1"></i>Submit for Approval
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($invoice['status'] === 'Submitted' && ($auth->isAdmin() || $auth->hasRole(ROLE_MANAGER))): ?>
                <form method="post" class="mb-2">
                    <input type="hidden" name="action" value="approve">
                    <?php if ($invoice['matching_status'] === 'Variance'): ?>
                    <div class="mb-2">
                        <label class="form-label">Override Notes (required for variance):</label>
                        <textarea name="notes" class="form-control" rows="2" required></textarea>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-check-lg me-1"></i>Approve
                    </button>
                </form>
                <?php endif; ?>

                <?php if (in_array($invoice['status'], ['Approved', 'Partial'])): ?>
                <a href="/4erpv2/modules/accounting/ap-payments/create.php?invoice_id=<?= $id ?>" class="btn btn-primary w-100 mb-2">
                    <i class="bi bi-cash me-1"></i>Record Payment
                </a>
                <?php endif; ?>

                <?php if (!in_array($invoice['status'], ['Paid', 'Voided']) && $invoice['paid_amount'] == 0): ?>
                <button type="button" class="btn btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#voidModal">
                    <i class="bi bi-x-circle me-1"></i>Void Invoice
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Audit Info -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">ประวัติ</h5>
            </div>
            <div class="card-body">
                <small class="text-muted">
                    <div><strong>สร้างเมื่อ:</strong> <?= date('d/m/Y H:i', strtotime($invoice['created_at'])) ?></div>
                    <?php if ($invoice['submitted_at']): ?>
                    <div><strong>ส่งเมื่อ:</strong> <?= date('d/m/Y H:i', strtotime($invoice['submitted_at'])) ?></div>
                    <?php endif; ?>
                    <?php if ($invoice['approved_at']): ?>
                    <div><strong>อนุมัติเมื่อ:</strong> <?= date('d/m/Y H:i', strtotime($invoice['approved_at'])) ?></div>
                    <?php endif; ?>
                    <?php if ($invoice['voided_at']): ?>
                    <div class="text-danger"><strong>ยกเลิกเมื่อ:</strong> <?= date('d/m/Y H:i', strtotime($invoice['voided_at'])) ?></div>
                    <div class="text-danger"><strong>เหตุผล:</strong> <?= htmlspecialchars($invoice['void_reason']) ?></div>
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Void Modal -->
<div class="modal fade" id="voidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="void">
                <div class="modal-header">
                    <h5 class="modal-title">ยกเลิกใบแจ้งหนี้</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        การยกเลิกจะสร้าง Debit Note อัตโนมัติ
                    </div>
                    <div class="mb-3">
                        <label class="form-label">เหตุผลในการยกเลิก *</label>
                        <textarea name="reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-danger">ยืนยันการยกเลิก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
