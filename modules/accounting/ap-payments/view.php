<?php
/**
 * AP Payment View
 * Accounts Payable Module - 4ERP
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/APPayment.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: ACC, ADM, MGR can view
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

$apPayment = new APPayment();
$payment = $apPayment->getById($id);

if (!$payment) {
    setFlash('error', 'ไม่พบข้อมูลการชำระเงิน');
    header('Location: index.php');
    exit;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'approve':
            // Only MGR/ADM can approve
            if (!$auth->isAdmin() && !$auth->hasRole(ROLE_MANAGER)) {
                setFlash('error', 'ไม่มีสิทธิ์อนุมัติ');
                break;
            }
            $result = $apPayment->approve($id, $_POST['notes'] ?? null);
            if ($result['success']) {
                setFlash('success', 'อนุมัติการชำระเงินเรียบร้อย');
            } else {
                setFlash('error', $result['error']);
            }
            break;
            
        case 'reject':
            // Only MGR/ADM can reject
            if (!$auth->isAdmin() && !$auth->hasRole(ROLE_MANAGER)) {
                setFlash('error', 'ไม่มีสิทธิ์ปฏิเสธ');
                break;
            }
            $reason = $_POST['reason'] ?? '';
            if (empty($reason)) {
                setFlash('error', 'กรุณาระบุเหตุผล');
            } else {
                $result = $apPayment->reject($id, $reason);
                if ($result['success']) {
                    setFlash('success', 'ปฏิเสธการชำระเงินเรียบร้อย');
                } else {
                    setFlash('error', $result['error']);
                }
            }
            break;
            
        case 'post':
            $result = $apPayment->post($id);
            if ($result['success']) {
                setFlash('success', 'บันทึกการชำระเงินเรียบร้อย');
            } else {
                setFlash('error', $result['error']);
            }
            break;
            
        case 'reverse':
            $reason = $_POST['reason'] ?? '';
            if (empty($reason)) {
                setFlash('error', 'กรุณาระบุเหตุผล');
            } else {
                $result = $apPayment->reverse($id, $reason);
                if ($result['success']) {
                    setFlash('success', "กลับรายการเรียบร้อย (Reversal: {$result['reversal_no']})");
                } else {
                    setFlash('error', $result['error']);
                }
            }
            break;
    }
    
    header("Location: view.php?id={$id}");
    exit;
}

// Refresh payment data
$payment = $apPayment->getById($id);

$pageTitle = "AP Payment: {$payment['payment_no']} - 4ERP";
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">AP Payments</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($payment['payment_no']) ?></li>
            </ol>
        </nav>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-8">
        <h2 class="mb-0">
            <i class="bi bi-cash me-2"></i><?= htmlspecialchars($payment['payment_no']) ?>
        </h2>
    </div>
    <div class="col-md-4 text-end">
        <?php
        $statusClass = match($payment['status']) {
            'Pending' => 'warning',
            'Approved' => 'info',
            'Posted' => 'success',
            'Reversed' => 'danger',
            default => 'secondary'
        };
        ?>
        <span class="badge bg-<?= $statusClass ?> fs-5"><?= $payment['status'] ?></span>
    </div>
</div>

<?php displayFlash(); ?>

<div class="row">
    <div class="col-md-8">
        <!-- Payment Details -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">รายละเอียดการชำระ</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th class="text-muted" width="40%">Invoice:</th>
                                <td>
                                    <a href="/4erpv2/modules/accounting/ap/view.php?id=<?= $payment['invoice_id'] ?>">
                                        <?= htmlspecialchars($payment['invoice_no']) ?>
                                    </a>
                                    <?php if ($payment['supplier_invoice_no']): ?>
                                    <small class="text-muted d-block">(<?= htmlspecialchars($payment['supplier_invoice_no']) ?>)</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">Supplier:</th>
                                <td><?= htmlspecialchars($payment['supplier_name']) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">ยอด Invoice:</th>
                                <td>฿<?= number_format($payment['invoice_total'], 2) ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th class="text-muted" width="40%">วันที่ชำระ:</th>
                                <td><?= date('d/m/Y', strtotime($payment['payment_date'])) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">วิธีชำระ:</th>
                                <td><?= $payment['payment_method'] ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">เลขอ้างอิง:</th>
                                <td><?= htmlspecialchars($payment['reference_no'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">บัญชี:</th>
                                <td><?= htmlspecialchars($payment['bank_account'] ?? '-') ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <hr>
                
                <div class="row">
                    <div class="col-md-6">
                        <h4 class="text-primary">จำนวนเงิน: ฿<?= number_format($payment['amount'], 2) ?></h4>
                    </div>
                </div>
                
                <?php if ($payment['notes']): ?>
                <hr>
                <div>
                    <strong>หมายเหตุ:</strong>
                    <p class="mb-0"><?= nl2br(htmlspecialchars($payment['notes'])) ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($payment['reversal_reason']): ?>
                <hr>
                <div class="alert alert-danger mb-0">
                    <strong>เหตุผลการกลับรายการ/ปฏิเสธ:</strong>
                    <p class="mb-0"><?= htmlspecialchars($payment['reversal_reason']) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Audit Trail -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">ประวัติ</h5>
            </div>
            <div class="card-body">
                <div class="timeline">
                    <div class="mb-3">
                        <strong>ขอชำระเงิน</strong>
                        <small class="text-muted d-block">
                            <?= date('d/m/Y H:i', strtotime($payment['requested_at'])) ?>
                            โดย <?= htmlspecialchars($payment['requested_by_name'] ?? '-') ?>
                        </small>
                    </div>
                    
                    <?php if ($payment['approved_at']): ?>
                    <div class="mb-3">
                        <strong class="text-success">อนุมัติ</strong>
                        <small class="text-muted d-block">
                            <?= date('d/m/Y H:i', strtotime($payment['approved_at'])) ?>
                            โดย <?= htmlspecialchars($payment['approved_by_name'] ?? '-') ?>
                        </small>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($payment['posted_at']): ?>
                    <div class="mb-3">
                        <strong class="text-primary">บันทึกเข้าระบบ</strong>
                        <small class="text-muted d-block">
                            <?= date('d/m/Y H:i', strtotime($payment['posted_at'])) ?>
                            โดย <?= htmlspecialchars($payment['posted_by_name'] ?? '-') ?>
                        </small>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($payment['reversed_at']): ?>
                    <div class="mb-3">
                        <strong class="text-danger">กลับรายการ/ปฏิเสธ</strong>
                        <small class="text-muted d-block">
                            <?= date('d/m/Y H:i', strtotime($payment['reversed_at'])) ?>
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Actions</h5>
            </div>
            <div class="card-body">
                <?php if ($payment['status'] === 'Pending'): ?>
                    <?php if ($auth->isAdmin() || $auth->hasRole(ROLE_MANAGER)): ?>
                    <!-- Approve -->
                    <form method="post" class="mb-2">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-check-lg me-1"></i>อนุมัติ
                        </button>
                    </form>
                    
                    <!-- Reject -->
                    <button type="button" class="btn btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#rejectModal">
                        <i class="bi bi-x-lg me-1"></i>ปฏิเสธ
                    </button>
                    <?php else: ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-hourglass me-1"></i>รอการอนุมัติจากผู้จัดการ
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($payment['status'] === 'Approved'): ?>
                <!-- Post -->
                <form method="post" class="mb-2">
                    <input type="hidden" name="action" value="post">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-journal-check me-1"></i>บันทึกเข้าระบบ (Post)
                    </button>
                </form>
                <?php endif; ?>
                
                <?php if ($payment['status'] === 'Posted'): ?>
                <!-- Reverse -->
                <button type="button" class="btn btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#reverseModal">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>กลับรายการ
                </button>
                <?php endif; ?>
                
                <?php if ($payment['status'] === 'Reversed'): ?>
                <div class="alert alert-danger mb-0">
                    <i class="bi bi-x-circle me-1"></i>รายการนี้ถูกกลับรายการแล้ว
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Links -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Links</h5>
            </div>
            <div class="card-body">
                <a href="/4erpv2/modules/accounting/ap/view.php?id=<?= $payment['invoice_id'] ?>" class="btn btn-outline-secondary w-100 mb-2">
                    <i class="bi bi-file-earmark-text me-1"></i>ดู Invoice
                </a>
                <a href="index.php" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-list me-1"></i>รายการทั้งหมด
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="reject">
                <div class="modal-header">
                    <h5 class="modal-title">ปฏิเสธการชำระเงิน</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">เหตุผล *</label>
                        <textarea name="reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-danger">ปฏิเสธ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reverse Modal -->
<div class="modal fade" id="reverseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="reverse">
                <div class="modal-header">
                    <h5 class="modal-title">กลับรายการชำระเงิน</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        การกลับรายการจะสร้างรายการย้อนกลับใหม่ และคืนยอดให้ Invoice
                    </div>
                    <div class="mb-3">
                        <label class="form-label">เหตุผล *</label>
                        <textarea name="reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-danger">ยืนยันกลับรายการ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
