<?php
/**
 * AP Invoice Create
 * Accounts Payable Module - 4ERP
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../core/APInvoice.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: ACC, ADM can create AP
if (!$auth->isAdmin() && !$auth->hasRole(ROLE_ACCOUNTANT)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: /4erpv2/index.php');
    exit;
}

$db = getDB();
$apInvoice = new APInvoice();

// Check if creating from GR
$grId = (int)($_GET['gr_id'] ?? 0);
$gr = null;
$grItems = [];

if ($grId) {
    $stmt = $db->prepare("
        SELECT gr.*, po.supplier_id, po.po_number, s.name as supplier_name, s.payment_terms
        FROM goods_receipts gr
        LEFT JOIN purchase_orders po ON gr.po_id = po.id
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        WHERE gr.id = ?
    ");
    $stmt->execute([$grId]);
    $gr = $stmt->fetch();
    
    if ($gr) {
        $stmt = $db->prepare("
            SELECT gi.*, i.code as item_code, i.name as item_name
            FROM gr_items gi
            LEFT JOIN items i ON gi.item_id = i.id
            WHERE gi.gr_id = ?
        ");
        $stmt->execute([$grId]);
        $grItems = $stmt->fetchAll();
    }
}

// Get suppliers
$suppliers = $db->query("SELECT id, code, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();

// Get jobs for linking
$jobs = $db->query("
    SELECT id, job_number, scope_short 
    FROM jobs 
    WHERE status NOT IN ('Voided', 'Closed', 'Draft')
    ORDER BY created_at DESC
    LIMIT 100
")->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    
    if ($action === 'create_from_gr' && $grId) {
        // Create from GR
        $result = $apInvoice->createFromGR($grId, [
            'supplier_invoice_no' => $_POST['supplier_invoice_no'] ?? null,
            'invoice_date' => $_POST['invoice_date'] ?? date('Y-m-d'),
            'due_date' => $_POST['due_date'] ?? null,
            'tax_rate' => $_POST['tax_rate'] ?? 7,
            'withholding_rate' => $_POST['withholding_rate'] ?? 0,
            'job_id' => $_POST['job_id'] ?: null,
            'notes' => $_POST['notes'] ?? null
        ]);
    } else {
        // Create manually
        $lines = [];
        if (!empty($_POST['lines'])) {
            foreach ($_POST['lines'] as $line) {
                if (!empty($line['description']) && $line['quantity'] > 0) {
                    $lines[] = [
                        'description' => $line['description'],
                        'quantity' => $line['quantity'],
                        'unit' => $line['unit'] ?? 'pcs',
                        'unit_price' => $line['unit_price'],
                        'cost_type' => $line['cost_type'] ?? 'Material'
                    ];
                }
            }
        }
        
        if (empty($lines)) {
            setFlash('error', 'กรุณาเพิ่มรายการอย่างน้อย 1 รายการ');
            header('Location: create.php');
            exit;
        }
        
        $result = $apInvoice->create([
            'supplier_id' => $_POST['supplier_id'],
            'supplier_invoice_no' => $_POST['supplier_invoice_no'] ?? null,
            'invoice_date' => $_POST['invoice_date'] ?? date('Y-m-d'),
            'due_date' => $_POST['due_date'] ?? null,
            'tax_rate' => $_POST['tax_rate'] ?? 7,
            'withholding_rate' => $_POST['withholding_rate'] ?? 0,
            'po_id' => $_POST['po_id'] ?: null,
            'gr_id' => $_POST['gr_id'] ?: null,
            'job_id' => $_POST['job_id'] ?: null,
            'notes' => $_POST['notes'] ?? null
        ], $lines);
    }
    
    if ($result['success']) {
        setFlash('success', "สร้างใบแจ้งหนี้ {$result['invoice_no']} เรียบร้อย");
        header("Location: view.php?id={$result['id']}");
        exit;
    } else {
        setFlash('error', $result['error']);
    }
}

$pageTitle = 'สร้าง AP Invoice - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">AP Invoices</a></li>
                <li class="breadcrumb-item active">สร้างใหม่</li>
            </ol>
        </nav>
        <h2>
            <i class="bi bi-plus-circle me-2"></i>สร้าง AP Invoice
        </h2>
        <?php if ($gr): ?>
        <p class="text-muted">สร้างจาก GR: <?= htmlspecialchars($gr['gr_number']) ?></p>
        <?php endif; ?>
    </div>
</div>

<?php displayFlash(); ?>

<?php if ($gr): ?>
<!-- Create from GR -->
<form method="post">
    <input type="hidden" name="action" value="create_from_gr">
    
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">ข้อมูล GR</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <th width="30%">GR Number:</th>
                            <td><?= htmlspecialchars($gr['gr_number']) ?></td>
                        </tr>
                        <tr>
                            <th>PO Number:</th>
                            <td><?= htmlspecialchars($gr['po_number'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th>Supplier:</th>
                            <td><?= htmlspecialchars($gr['supplier_name']) ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <th width="30%">วันที่รับ:</th>
                            <td><?= date('d/m/Y', strtotime($gr['received_date'])) ?></td>
                        </tr>
                        <tr>
                            <th>ยอดรวม:</th>
                            <td class="fw-bold"><?= number_format($gr['total_amount'] ?? 0, 2) ?></td>
                        </tr>
                        <tr>
                            <th>Payment Terms:</th>
                            <td><?= $gr['payment_terms'] ?? 30 ?> วัน</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">รายการ</h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>รายการ</th>
                        <th class="text-end">จำนวน</th>
                        <th class="text-end">ราคา/หน่วย</th>
                        <th class="text-end">รวม</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $subtotal = 0;
                    foreach ($grItems as $i => $item): 
                        $qty = $item['received_qty'] ?? $item['qty'] ?? 1;
                        $price = $item['unit_price'] ?? 0;
                        $amount = $qty * $price;
                        $subtotal += $amount;
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <?= htmlspecialchars($item['description'] ?? $item['item_name'] ?? 'Item') ?>
                            <?php if ($item['item_code']): ?>
                            <small class="text-muted d-block"><?= htmlspecialchars($item['item_code']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= number_format($qty, 2) ?> <?= $item['unit'] ?? 'pcs' ?></td>
                        <td class="text-end"><?= number_format($price, 2) ?></td>
                        <td class="text-end"><?= number_format($amount, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr class="fw-bold">
                        <td colspan="4" class="text-end">Subtotal:</td>
                        <td class="text-end"><?= number_format($subtotal, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">ข้อมูล Invoice</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">เลข Invoice ของ Supplier</label>
                    <input type="text" name="supplier_invoice_no" class="form-control" placeholder="INV-XXX">
                </div>
                <div class="col-md-4">
                    <label class="form-label">วันที่ Invoice *</label>
                    <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">วันครบกำหนด</label>
                    <input type="date" name="due_date" class="form-control" 
                           value="<?= date('Y-m-d', strtotime('+' . ($gr['payment_terms'] ?? 30) . ' days')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">VAT %</label>
                    <input type="number" name="tax_rate" class="form-control" value="7" step="0.01">
                </div>
                <div class="col-md-3">
                    <label class="form-label">หัก ณ ที่จ่าย %</label>
                    <input type="number" name="withholding_rate" class="form-control" value="0" step="0.01">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Link to Job</label>
                    <select name="job_id" class="form-select">
                        <option value="">-- ไม่เลือก --</option>
                        <?php foreach ($jobs as $job): ?>
                        <option value="<?= $job['id'] ?>"><?= htmlspecialchars($job['job_number']) ?> - <?= htmlspecialchars($job['scope_short'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
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
            <i class="bi bi-check-lg me-1"></i>สร้าง AP Invoice
        </button>
        <a href="index.php" class="btn btn-secondary">ยกเลิก</a>
    </div>
</form>

<?php else: ?>
<!-- Manual Create -->
<form method="post" id="invoiceForm">
    <input type="hidden" name="action" value="create">
    
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">ข้อมูลทั่วไป</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Supplier *</label>
                    <select name="supplier_id" class="form-select" required>
                        <option value="">-- เลือก Supplier --</option>
                        <?php foreach ($suppliers as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['code']) ?> - <?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">เลข Invoice ของ Supplier</label>
                    <input type="text" name="supplier_invoice_no" class="form-control" placeholder="INV-XXX">
                </div>
                <div class="col-md-4">
                    <label class="form-label">วันที่ Invoice *</label>
                    <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">วันครบกำหนด</label>
                    <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Link to Job</label>
                    <select name="job_id" class="form-select">
                        <option value="">-- ไม่เลือก --</option>
                        <?php foreach ($jobs as $job): ?>
                        <option value="<?= $job['id'] ?>"><?= htmlspecialchars($job['job_number']) ?> - <?= htmlspecialchars($job['scope_short'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">VAT %</label>
                    <input type="number" name="tax_rate" class="form-control" value="7" step="0.01">
                </div>
                <div class="col-md-3">
                    <label class="form-label">หัก ณ ที่จ่าย %</label>
                    <input type="number" name="withholding_rate" class="form-control" value="0" step="0.01">
                </div>
                <div class="col-md-6">
                    <label class="form-label">หมายเหตุ</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">รายการ</h5>
            <button type="button" class="btn btn-sm btn-success" onclick="addLine()">
                <i class="bi bi-plus-lg me-1"></i>เพิ่มรายการ
            </button>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0" id="linesTable">
                <thead class="table-light">
                    <tr>
                        <th width="5%">#</th>
                        <th width="30%">รายการ</th>
                        <th width="15%">ประเภท</th>
                        <th width="10%">จำนวน</th>
                        <th width="10%">หน่วย</th>
                        <th width="15%">ราคา/หน่วย</th>
                        <th width="10%">รวม</th>
                        <th width="5%"></th>
                    </tr>
                </thead>
                <tbody id="linesBody">
                    <!-- Dynamic rows -->
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="6" class="text-end fw-bold">Subtotal:</td>
                        <td class="text-end fw-bold" id="subtotal">0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i>สร้าง AP Invoice
        </button>
        <a href="index.php" class="btn btn-secondary">ยกเลิก</a>
    </div>
</form>

<script>
let lineCount = 0;

function addLine() {
    lineCount++;
    const tbody = document.getElementById('linesBody');
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>${lineCount}</td>
        <td><input type="text" name="lines[${lineCount}][description]" class="form-control form-control-sm" required></td>
        <td>
            <select name="lines[${lineCount}][cost_type]" class="form-select form-select-sm">
                <option value="Material">Material</option>
                <option value="Manpower">Manpower</option>
                <option value="Transport">Transport</option>
                <option value="Outsource">Outsource</option>
                <option value="Other">Other</option>
            </select>
        </td>
        <td><input type="number" name="lines[${lineCount}][quantity]" class="form-control form-control-sm line-qty" value="1" step="0.01" min="0.01" onchange="calcLine(this)"></td>
        <td><input type="text" name="lines[${lineCount}][unit]" class="form-control form-control-sm" value="pcs"></td>
        <td><input type="number" name="lines[${lineCount}][unit_price]" class="form-control form-control-sm line-price" value="0" step="0.01" min="0" onchange="calcLine(this)"></td>
        <td class="text-end line-amount">0.00</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLine(this)"><i class="bi bi-trash"></i></button></td>
    `;
    tbody.appendChild(row);
}

function removeLine(btn) {
    btn.closest('tr').remove();
    calcTotal();
}

function calcLine(input) {
    const row = input.closest('tr');
    const qty = parseFloat(row.querySelector('.line-qty').value) || 0;
    const price = parseFloat(row.querySelector('.line-price').value) || 0;
    const amount = qty * price;
    row.querySelector('.line-amount').textContent = amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    calcTotal();
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('.line-amount').forEach(el => {
        total += parseFloat(el.textContent.replace(/,/g, '')) || 0;
    });
    document.getElementById('subtotal').textContent = total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// Add first line on load
addLine();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
