<?php
/**
 * AP Payment Create
 * Accounts Payable Module - 4ERP
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/APInvoice.php';
require_once __DIR__ . '/../../../core/APPayment.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: ACC, ADM can create payment
if (!$auth->isAdmin() && !$auth->hasRole(ROLE_ACCOUNTANT)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: /4erpv2/index.php');
    exit;
}

$db = getDB();
$apInvoice = new APInvoice();
$apPayment = new APPayment();

// Get invoice if specified
$invoiceId = (int)($_GET['invoice_id'] ?? 0);
$invoice = null;
$balance = 0;

if ($invoiceId) {
    $invoice = $apInvoice->getById($invoiceId);
    if ($invoice) {
        $balance = $apInvoice->getBalance($invoiceId);
    }
}

// Get invoices for selection
$invoices = $db->query("
    SELECT api.id, api.invoice_no, api.supplier_invoice_no, api.total_amount, api.paid_amount,
           (api.total_amount - api.paid_amount) as balance,
           s.name as supplier_name
    FROM ap_invoices api
    JOIN suppliers s ON api.supplier_id = s.id
    WHERE api.status IN ('Approved', 'Partial')
    ORDER BY api.due_date ASC
")->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $apPayment->requestPayment((int)$_POST['invoice_id'], [
        'amount' => (float)$_POST['amount'],
        'payment_method' => $_POST['payment_method'],
        'payment_date' => $_POST['payment_date'],
        'reference_no' => $_POST['reference_no'] ?: null,
        'bank_account' => $_POST['bank_account'] ?: null,
        'notes' => $_POST['notes'] ?: null
    ]);
    
    if ($result['success']) {
        setFlash('success', "สร้างคำขอชำระเงิน {$result['payment_no']} เรียบร้อย (รออนุมัติ)");
        header("Location: view.php?id={$result['id']}");
        exit;
    } else {
        setFlash('error', $result['error']);
    }
}

$pageTitle = 'บันทึกการชำระเงิน - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">AP Payments</a></li>
                <li class="breadcrumb-item active">บันทึกการชำระ</li>
            </ol>
        </nav>
        <h2>
            <i class="bi bi-cash me-2"></i>บันทึกการชำระเงิน
        </h2>
    </div>
</div>

<?php displayFlash(); ?>

<form method="post">
    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">ข้อมูลการชำระ</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Invoice *</label>
                            <select name="invoice_id" class="form-select" required id="invoiceSelect" onchange="updateBalance()">
                                <option value="">-- เลือก Invoice --</option>
                                <?php foreach ($invoices as $inv): ?>
                                <option value="<?= $inv['id'] ?>" 
                                        data-balance="<?= $inv['balance'] ?>"
                                        data-supplier="<?= htmlspecialchars($inv['supplier_name']) ?>"
                                        <?= $invoiceId == $inv['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($inv['invoice_no']) ?>
                                    <?php if ($inv['supplier_invoice_no']): ?>
                                    (<?= htmlspecialchars($inv['supplier_invoice_no']) ?>)
                                    <?php endif; ?>
                                    - <?= htmlspecialchars($inv['supplier_name']) ?>
                                    - คงเหลือ ฿<?= number_format($inv['balance'], 2) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Supplier</label>
                            <input type="text" class="form-control" id="supplierName" readonly 
                                   value="<?= $invoice ? htmlspecialchars($invoice['supplier_name']) : '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ยอดคงเหลือ</label>
                            <input type="text" class="form-control fw-bold text-danger" id="balanceDisplay" readonly
                                   value="<?= $balance ? number_format($balance, 2) : '' ?>">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">จำนวนเงินที่ชำระ *</label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" 
                                   max="<?= $balance ?>" value="<?= $balance ?>" required id="amountInput">
                            <small class="text-muted">สูงสุด: ฿<span id="maxAmount"><?= number_format($balance, 2) ?></span></small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">วันที่ชำระ *</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">วิธีชำระ *</label>
                            <select name="payment_method" class="form-select" required>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cash">Cash</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Credit Card">Credit Card</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">เลขอ้างอิง</label>
                            <input type="text" name="reference_no" class="form-control" placeholder="เลขที่เช็ค, เลข Transfer">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">บัญชีที่จ่าย</label>
                            <input type="text" name="bank_account" class="form-control" placeholder="ชื่อบัญชี / เลขบัญชี">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>ส่งคำขอชำระเงิน
                </button>
                <a href="index.php" class="btn btn-secondary">ยกเลิก</a>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">ขั้นตอนการชำระ</h5>
                </div>
                <div class="card-body">
                    <ol class="mb-0">
                        <li class="mb-2">
                            <strong>Request</strong>
                            <small class="d-block text-muted">สร้างคำขอชำระเงิน</small>
                        </li>
                        <li class="mb-2">
                            <strong>Approval</strong>
                            <small class="d-block text-muted">รอผู้จัดการอนุมัติ</small>
                        </li>
                        <li class="mb-2">
                            <strong>Post</strong>
                            <small class="d-block text-muted">บันทึกเข้าระบบ</small>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function updateBalance() {
    const select = document.getElementById('invoiceSelect');
    const option = select.options[select.selectedIndex];
    
    if (option.value) {
        const balance = parseFloat(option.dataset.balance) || 0;
        const supplier = option.dataset.supplier || '';
        
        document.getElementById('supplierName').value = supplier;
        document.getElementById('balanceDisplay').value = balance.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('amountInput').value = balance;
        document.getElementById('amountInput').max = balance;
        document.getElementById('maxAmount').textContent = balance.toLocaleString('en-US', {minimumFractionDigits: 2});
    } else {
        document.getElementById('supplierName').value = '';
        document.getElementById('balanceDisplay').value = '';
        document.getElementById('amountInput').value = '';
        document.getElementById('maxAmount').textContent = '0.00';
    }
}
</script>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
