<?php
/**
 * Create PO
 * ERP v2 - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
$docNum = new DocumentNumber();

// Check if from PR
$prId = (int) get('pr_id');
$prData = null;
$prItems = [];

if ($prId) {
    $stmt = $db->prepare("SELECT * FROM purchase_requests WHERE id = ? AND status = 'Approved'");
    $stmt->execute([$prId]);
    $prData = $stmt->fetch();
    
    if ($prData) {
        $prItems = $db->prepare("
            SELECT pri.*, i.code as item_code, i.name as item_name
            FROM pr_items pri
            LEFT JOIN items i ON pri.item_id = i.id
            WHERE pri.pr_id = ?
        ");
        $prItems->execute([$prId]);
        $prItems = $prItems->fetchAll();
    }
}

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('create.php');
    }
    
    try {
        $db->beginTransaction();
        
        // Generate PO number
        $poNumber = $docNum->generate('PO');
        
        $poType = post('po_type');
        $subtotal = 0;
        
        // Insert PO
        $stmt = $db->prepare("
            INSERT INTO purchase_orders (po_number, pr_id, supplier_id, po_type, order_date, delivery_date, payment_terms, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $poNumber,
            post('pr_id') ?: null,
            post('supplier_id'),
            $poType,
            post('order_date'),
            post('delivery_date') ?: null,
            post('payment_terms', 30),
            post('notes'),
            $_SESSION['user_id']
        ]);
        
        $poId = $db->lastInsertId();
        
        // Insert items (for Goods/Service)
        if ($poType !== 'Manpower') {
            $items = post('items', []);
            
            foreach ($items as $item) {
                if (empty($item['description'])) continue;
                
                $qty = (float) ($item['qty'] ?? 1);
                $unitPrice = (float) ($item['unit_price'] ?? 0);
                $amount = $qty * $unitPrice;
                $subtotal += $amount;
                
                $stmt = $db->prepare("
                    INSERT INTO po_items (po_id, pr_item_id, item_id, description, qty, unit, unit_price, amount)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $poId,
                    $item['pr_item_id'] ?? null,
                    $item['item_id'] ?: null,
                    $item['description'],
                    $qty,
                    $item['unit'] ?? 'pcs',
                    $unitPrice,
                    $amount
                ]);
            }
        }
        
        // Calculate VAT
        $vatRate = (float) post('vat_rate', 7);
        $vatAmount = $subtotal * $vatRate / 100;
        $grandTotal = $subtotal + $vatAmount;
        
        // Update totals
        $db->prepare("
            UPDATE purchase_orders SET subtotal = ?, vat_rate = ?, vat_amount = ?, grand_total = ?
            WHERE id = ?
        ")->execute([$subtotal, $vatRate, $vatAmount, $grandTotal, $poId]);
        
        $db->commit();
        
        $audit->log('create', 'PO', $poId, null, ['po_number' => $poNumber, 'type' => $poType]);
        
        setFlash('success', "สร้าง PO เรียบร้อย: $poNumber");
        redirect("view.php?id=$poId");
        
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect('create.php');
    }
}

// Get suppliers
$suppliers = $db->query("SELECT id, code, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();

// Get items for dropdown
$catalogItems = $db->query("SELECT id, code, name, unit FROM items WHERE is_active = 1 ORDER BY code")->fetchAll();

$pageTitle = 'สร้าง PO - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-receipt me-2"></i>สร้าง Purchase Order</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="index.php">PO</a></li>
                    <li class="breadcrumb-item active">สร้าง</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
        </div>
    </div>
</div>

<?php if ($prData): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    สร้างจาก PR: <strong><?= e($prData['pr_number']) ?></strong> - <?= e($prData['purpose']) ?>
</div>
<?php endif; ?>

<form method="POST" id="poForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="pr_id" value="<?= $prId ?>">
    
    <div class="card mb-4">
        <div class="card-header">ข้อมูลทั่วไป</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">ประเภท <span class="text-danger">*</span></label>
                        <select class="form-select" name="po_type" id="poType" required>
                            <option value="Goods">สินค้า (Goods)</option>
                            <option value="Service">บริการ (Service)</option>
                            <option value="Manpower">แรงงาน (Manpower)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ผู้ขาย <span class="text-danger">*</span></label>
                        <select class="form-select" name="supplier_id" required>
                            <option value="">-- เลือก --</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['code']) ?> - <?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">วันที่สั่ง <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="order_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">วันที่ต้องส่ง</label>
                        <input type="date" class="form-control" name="delivery_date" value="<?= $prData['required_date'] ?? '' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">เครดิต (วัน)</label>
                        <input type="number" class="form-control" name="payment_terms" value="30">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card mb-4" id="itemsCard">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>รายการ</span>
            <button type="button" class="btn btn-sm btn-success" onclick="addItem()">
                <i class="bi bi-plus-circle me-1"></i>เพิ่มรายการ
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width: 200px;">สินค้า</th>
                            <th>รายละเอียด</th>
                            <th style="width: 100px;">จำนวน</th>
                            <th style="width: 80px;">หน่วย</th>
                            <th style="width: 120px;">ราคา/หน่วย</th>
                            <th style="width: 120px;">รวม</th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" class="text-end">ยอดรวม</td>
                            <td><span id="subtotal">0.00</span></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="4" class="text-end">VAT</td>
                            <td><input type="number" class="form-control form-control-sm" name="vat_rate" value="7" step="0.01" style="width: 80px;" onchange="calcTotal()"> %</td>
                            <td><span id="vatAmount">0.00</span></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="5" class="text-end"><strong>รวมทั้งสิ้น</strong></td>
                            <td><strong id="grandTotal">0.00</strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    
    <div class="text-end">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-check-circle me-1"></i>บันทึก PO
        </button>
    </div>
</form>

<script>
const catalogItems = <?= json_encode($catalogItems) ?>;
const prItems = <?= json_encode($prItems) ?>;
let itemIndex = 0;

function addItem(data = null) {
    const tbody = document.getElementById('itemsBody');
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <input type="hidden" name="items[${itemIndex}][pr_item_id]" value="${data?.id || ''}">
            <select class="form-select form-select-sm" name="items[${itemIndex}][item_id]" onchange="selectItem(this, ${itemIndex})">
                <option value="">-- เลือก --</option>
                ${catalogItems.map(i => `<option value="${i.id}" data-name="${i.name}" data-unit="${i.unit}" ${data && data.item_id == i.id ? 'selected' : ''}>${i.code} - ${i.name}</option>`).join('')}
            </select>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" name="items[${itemIndex}][description]" value="${data?.description || ''}" required>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm" name="items[${itemIndex}][qty]" value="${data?.qty || 1}" step="0.01" min="0" onchange="calcRow(${itemIndex})">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" name="items[${itemIndex}][unit]" value="${data?.unit || 'pcs'}">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm" name="items[${itemIndex}][unit_price]" value="${data?.unit_price || 0}" step="0.01" min="0" onchange="calcRow(${itemIndex})">
        </td>
        <td>
            <span id="amount_${itemIndex}">${data ? parseFloat(data.amount || 0).toFixed(2) : '0.00'}</span>
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); calcTotal();">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(row);
    itemIndex++;
    calcTotal();
}

function selectItem(select, idx) {
    const option = select.options[select.selectedIndex];
    if (option.value) {
        document.querySelector(`[name="items[${idx}][description]"]`).value = option.dataset.name || '';
        document.querySelector(`[name="items[${idx}][unit]"]`).value = option.dataset.unit || 'pcs';
    }
}

function calcRow(idx) {
    const qty = parseFloat(document.querySelector(`[name="items[${idx}][qty]"]`).value) || 0;
    const price = parseFloat(document.querySelector(`[name="items[${idx}][unit_price]"]`).value) || 0;
    const amount = qty * price;
    document.getElementById(`amount_${idx}`).textContent = amount.toFixed(2);
    calcTotal();
}

function calcTotal() {
    let subtotal = 0;
    document.querySelectorAll('[id^="amount_"]').forEach(el => {
        subtotal += parseFloat(el.textContent) || 0;
    });
    const vatRate = parseFloat(document.querySelector('[name="vat_rate"]').value) || 0;
    const vatAmount = subtotal * vatRate / 100;
    const grandTotal = subtotal + vatAmount;
    
    document.getElementById('subtotal').textContent = subtotal.toFixed(2);
    document.getElementById('vatAmount').textContent = vatAmount.toFixed(2);
    document.getElementById('grandTotal').textContent = grandTotal.toFixed(2);
}

// Load PR items or add empty row
if (prItems.length > 0) {
    prItems.forEach(item => addItem(item));
} else {
    addItem();
}

// Handle PO type change
document.getElementById('poType').addEventListener('change', function() {
    document.getElementById('itemsCard').style.display = this.value === 'Manpower' ? 'none' : 'block';
});
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
