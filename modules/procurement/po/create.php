<?php
/**
 * Create PO
 * 4ERP - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
$db = getDB();
$audit = new AuditLog();
$notification = new Notification();
$docNum = new DocumentNumber();

// Check if creating from PR
$prId = (int) get('pr_id', 0);
$pr = null;
$prItems = [];

if ($prId) {
    $stmt = $db->prepare("
        SELECT pr.*, j.job_number
        FROM purchase_requests pr
        LEFT JOIN jobs j ON pr.job_id = j.id
        WHERE pr.id = ? AND pr.status = 'Approved'
    ");
    $stmt->execute([$prId]);
    $pr = $stmt->fetch();

    if (!$pr) {
        setFlash('error', 'ไม่พบ PR หรือ PR ยังไม่ได้รับการอนุมัติ');
        redirect('index.php');
    }

    // Prevent creating PO if an active PO already exists for this PR
    $stmt = $db->prepare("
        SELECT id, po_number, status
        FROM purchase_orders
        WHERE pr_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$prId]);
    $existingPo = $stmt->fetch();
    $existingPoStatus = trim((string) ($existingPo['status'] ?? ''));
    $existingPoInactive = in_array(strtoupper($existingPoStatus), ['CANCELLED', 'CANCELED', 'VOIDED'], true);
    if ($existingPo && !$existingPoInactive) {
        setFlash('error', 'PR นี้มี PO แล้ว: ' . $existingPo['po_number']);
        redirect("../pr/view.php?id=$prId");
    }

    // Get PR items
    $stmt = $db->prepare("
        SELECT pri.*, i.code as item_code
        FROM pr_items pri
        LEFT JOIN items i ON pri.item_id = i.id
        WHERE pri.pr_id = ?
    ");
    $stmt->execute([$prId]);
    $prItems = $stmt->fetchAll();
}

// Get suppliers
$suppliers = $db->query("SELECT id, code, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();

// Get catalog items for search combobox
$catalogItems = $db->query("SELECT id, code, name, unit FROM items WHERE is_active = 1 ORDER BY code")->fetchAll();

// Handle form submit
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('create.php' . ($prId ? "?pr_id=$prId" : ''));
    }

    $supplierId = (int) post('supplier_id');
    $poType = post('po_type', 'Goods');
    $orderDate = post('order_date');
    $deliveryDate = post('delivery_date') ?: null;
    $paymentTerms = (int) post('payment_terms', 30);
    $vatRate = (float) post('vat_rate', 7);
    $notes = sanitize(post('notes'));
    $saveAsDraft = post('save_draft') !== null;

    $items = post('items', []);

    // Validate
    $errors = [];
    if (!$supplierId) $errors[] = 'กรุณาเลือกผู้ขาย';
    if (!$orderDate) $errors[] = 'กรุณาระบุวันที่สั่ง';
    if (empty($items)) $errors[] = 'กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ';

    if (!empty($errors)) {
        setFlash('error', implode('<br>', $errors));
        redirect('create.php' . ($prId ? "?pr_id=$prId" : ''));
    }

    try {
        $db->beginTransaction();

        // Generate PO number
        $poNumber = $docNum->generate('PO');

        // Calculate totals
        $subtotal = 0;
        foreach ($items as $item) {
            if (!empty($item['description']) && $item['qty'] > 0) {
                $subtotal += (float)$item['qty'] * (float)$item['unit_price'];
            }
        }
        $vatAmount = $subtotal * ($vatRate / 100);
        $grandTotal = $subtotal + $vatAmount;

        // Insert PO
        $stmt = $db->prepare("
            INSERT INTO purchase_orders
            (po_number, pr_id, supplier_id, po_type, order_date, delivery_date, status,
             payment_terms, subtotal, vat_rate, vat_amount, grand_total, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $poNumber,
            $prId ?: null,
            $supplierId,
            $poType,
            $orderDate,
            $deliveryDate,
            $saveAsDraft ? 'Draft' : 'Submitted',
            $paymentTerms,
            $subtotal,
            $vatRate,
            $vatAmount,
            $grandTotal,
            $notes,
            $_SESSION['user_id']
        ]);
        $poId = $db->lastInsertId();

        // Insert PO items
        $stmtItem = $db->prepare("
            INSERT INTO po_items (po_id, pr_item_id, item_id, description, qty, unit, unit_price, amount, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($items as $item) {
            if (!empty($item['description']) && $item['qty'] > 0) {
                $qty = (float)$item['qty'];
                $unitPrice = (float)$item['unit_price'];
                $amount = $qty * $unitPrice;
                $itemId = isset($item['item_id']) && $item['item_id'] !== '' ? (int)$item['item_id'] : null;
                $prItemId = isset($item['pr_item_id']) && $item['pr_item_id'] !== '' ? (int)$item['pr_item_id'] : null;

                $stmtItem->execute([
                    $poId,
                    $prItemId,
                    $itemId,
                    $item['description'],
                    $qty,
                    $item['unit'] ?? 'pcs',
                    $unitPrice,
                    $amount,
                    $item['notes'] ?? null
                ]);
            }
        }

        // If submitted, update submitted info
        if (!$saveAsDraft) {
            $db->prepare("
                UPDATE purchase_orders
                SET submitted_at = NOW(), submitted_by = ?
                WHERE id = ?
            ")->execute([$_SESSION['user_id'], $poId]);
        }

        $db->commit();

        $audit->log('create', 'PO', $poId, null, ['po_number' => $poNumber, 'from_pr' => $prId]);

        if (!$saveAsDraft) {
            $approverIds = $rbac->getUserIdsWithPermission('approve', 'PO', 'Submitted');
            if (!empty($approverIds)) {
                $supplierName = '';
                foreach ($suppliers as $s) {
                    if ((int) $s['id'] === (int) $supplierId) {
                        $supplierName = $s['name'];
                        break;
                    }
                }
                if ($supplierName === '') {
                    $stmt = $db->prepare("SELECT name FROM suppliers WHERE id = ?");
                    $stmt->execute([$supplierId]);
                    $supplierName = (string) ($stmt->fetchColumn() ?: '');
                }

                $title = "PO {$poNumber} รออนุมัติ";
                $message = $supplierName !== '' ? "Supplier: {$supplierName}" : "มี PO ใหม่รออนุมัติ";
                $url = "/4erpv2/modules/procurement/po/view.php?id={$poId}";
                $notification->createBulk(
                    $approverIds,
                    Notification::TYPE_APPROVAL_REQUEST,
                    $title,
                    $message,
                    $url,
                    'PO',
                    $poId,
                    Notification::PRIORITY_HIGH
                );
            }
        }
        setFlash('success', "สร้าง PO เรียบร้อย: $poNumber");
        redirect("view.php?id=$poId");

    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect('create.php' . ($prId ? "?pr_id=$prId" : ''));
    }
}

$pageTitle = 'สร้าง PO - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-plus-circle me-2"></i>สร้าง Purchase Order
                <?php if ($pr): ?>
                <span class="badge bg-info ms-2">จาก <?= e($pr['pr_number']) ?></span>
                <?php endif; ?>
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="index.php">PO</a></li>
                    <li class="breadcrumb-item active">สร้างใหม่</li>
                </ol>
            </nav>
        </div>
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<form method="POST" id="poForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

    <?php if ($pr): ?>
    <!-- PR Info Banner -->
    <div class="alert alert-info d-flex align-items-center mb-4">
        <i class="bi bi-info-circle fs-4 me-3"></i>
        <div class="flex-grow-1">
            <strong>สร้าง PO จาก PR:</strong>
            <a href="../pr/view.php?id=<?= $pr['id'] ?>" class="alert-link"><?= e($pr['pr_number']) ?></a>
            <?php if ($pr['job_number']): ?>
            <span class="ms-2">| Job: <?= e($pr['job_number']) ?></span>
            <?php endif; ?>
            <span class="ms-2">| ยอดรวม: <strong><?= formatNumber($pr['total_amount']) ?></strong> บาท</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- PO Info -->
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-building me-2"></i>ข้อมูลผู้ขายและการสั่งซื้อ</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">ผู้ขาย <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <select class="form-select" name="supplier_id" id="supplierSelect" required>
                            <option value="">-- เลือกผู้ขาย --</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= e($s['code']) ?> - <?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addSupplierModal" title="เพิ่มผู้ขายใหม่">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">ประเภท</label>
                    <select class="form-select" name="po_type">
                        <option value="Goods">สินค้า</option>
                        <option value="Service">บริการ</option>
                        <option value="Manpower">แรงงาน</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">เครดิต</label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="payment_terms" value="30" min="0">
                        <span class="input-group-text">วัน</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">วันที่สั่ง <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="order_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">กำหนดส่งของ</label>
                    <input type="date" class="form-control" name="delivery_date">
                </div>
                <div class="col-md-6">
                    <label class="form-label">หมายเหตุ</label>
                    <input type="text" class="form-control" name="notes" value="<?= $pr ? e($pr['purpose']) : '' ?>" placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)">
                </div>
            </div>
        </div>
    </div>

    <!-- Items -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul me-2"></i>รายการสินค้า</span>
            <button type="button" class="btn btn-sm btn-success" onclick="addItem()">
                <i class="bi bi-plus-circle me-1"></i>เพิ่มรายการ
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="itemsTable">
                    <thead class="table-light">
                        <tr>
                            <th width="40">#</th>
                            <th>รายละเอียด (พิมพ์ค้นหาหรือใส่เอง)</th>
                            <th width="70" class="text-center">จำนวน</th>
                            <th width="60" class="text-center">หน่วย</th>
                            <th width="100" class="text-end">ราคา/หน่วย</th>
                            <th width="100" class="text-end">รวม</th>
                            <th width="40"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <?php if (!empty($prItems)): ?>
                            <?php foreach ($prItems as $idx => $item): ?>
                            <tr id="item_<?= $idx ?>">
                                <td class="align-middle text-center text-muted row-num"><?= $idx + 1 ?></td>
                                <td class="position-relative">
                                    <input type="hidden" name="items[<?= $idx ?>][pr_item_id]" value="<?= $item['id'] ?>">
                                    <input type="hidden" name="items[<?= $idx ?>][item_id]" class="item-id-input" value="<?= $item['item_id'] ?>">
                                    <input type="text" class="form-control form-control-sm item-search" name="items[<?= $idx ?>][description]" value="<?= e($item['description']) ?>" data-idx="<?= $idx ?>" autocomplete="off" required>
                                    <div class="item-dropdown"></div>
                                </td>
                                <td>
                                    <input type="number" class="form-control form-control-sm text-center item-qty" name="items[<?= $idx ?>][qty]" value="<?= $item['qty'] ?>" step="0.01" min="0.01" onchange="calcRow(<?= $idx ?>)">
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm text-center item-unit" name="items[<?= $idx ?>][unit]" value="<?= e($item['unit']) ?>">
                                </td>
                                <td>
                                    <input type="number" class="form-control form-control-sm text-end item-price" name="items[<?= $idx ?>][unit_price]" value="<?= $item['unit_price'] ?>" step="0.01" min="0" onchange="calcRow(<?= $idx ?>)">
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm text-end bg-light" id="amount_<?= $idx ?>" value="<?= number_format($item['amount'], 2) ?>" readonly>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(<?= $idx ?>)" title="ลบ">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Summary & Actions -->
    <div class="row">
        <div class="col-md-6">
            <a href="javascript:history.back()" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>ยกเลิก
            </a>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-body py-3">
                    <div class="row align-items-center g-3">
                        <div class="col-auto">
                            <span class="text-muted">VAT</span>
                            <input type="number" class="form-control form-control-sm d-inline-block ms-1"
                                   style="width: 60px" name="vat_rate" id="vatRate" value="7"
                                   step="0.01" min="0" onchange="calculateTotals()">
                            <span class="text-muted">%</span>
                        </div>
                        <div class="col text-end">
                            <small class="text-muted d-block">ก่อน VAT: <span id="subtotal">0.00</span> | VAT: <span id="vatAmount">0.00</span></small>
                            <span class="fs-4">รวม: <strong class="text-primary" id="grandTotal">0.00</strong> บาท</span>
                        </div>
                        <div class="col-auto">
                            <button type="submit" name="save_draft" class="btn btn-outline-secondary">
                                <i class="bi bi-save me-1"></i>แบบร่าง
                            </button>
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-send me-1"></i>ส่งอนุมัติ
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
const catalogItems = <?= json_encode($catalogItems) ?>;
let itemIndex = <?= !empty($prItems) ? count($prItems) : 0 ?>;

function addItem() {
    const tbody = document.getElementById('itemsContainer');
    const tr = document.createElement('tr');
    tr.id = `item_${itemIndex}`;
    tr.innerHTML = `
        <td class="align-middle text-center text-muted row-num">${tbody.children.length + 1}</td>
        <td class="position-relative">
            <input type="hidden" name="items[${itemIndex}][pr_item_id]" value="">
            <input type="hidden" name="items[${itemIndex}][item_id]" class="item-id-input" value="">
            <input type="text" class="form-control form-control-sm item-search" name="items[${itemIndex}][description]" data-idx="${itemIndex}" placeholder="พิมพ์ค้นหาหรือใส่รายละเอียด..." autocomplete="off" required>
            <div class="item-dropdown"></div>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm text-center item-qty" name="items[${itemIndex}][qty]" value="1" step="0.01" min="0.01" onchange="calcRow(${itemIndex})">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm text-center item-unit" name="items[${itemIndex}][unit]" value="pcs">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm text-end item-price" name="items[${itemIndex}][unit_price]" value="0" step="0.01" min="0" onchange="calcRow(${itemIndex})">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm text-end bg-light" id="amount_${itemIndex}" value="0.00" readonly>
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(${itemIndex})" title="ลบ">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    initItemSearch(tr.querySelector('.item-search'));
    itemIndex++;
    renumberRows();
}

function removeItem(idx) {
    const row = document.getElementById(`item_${idx}`);
    if (row) {
        row.remove();
        calculateTotals();
        renumberRows();
    }
}

function renumberRows() {
    document.querySelectorAll('#itemsContainer tr').forEach((tr, i) => {
        const numCell = tr.querySelector('.row-num');
        if (numCell) numCell.textContent = i + 1;
    });
}

function calcRow(idx) {
    const qty = parseFloat(document.querySelector(`[name="items[${idx}][qty]"]`)?.value) || 0;
    const price = parseFloat(document.querySelector(`[name="items[${idx}][unit_price]"]`)?.value) || 0;
    const amount = qty * price;
    const amountEl = document.getElementById(`amount_${idx}`);
    if (amountEl) amountEl.value = formatNumber(amount);
    calculateTotals();
}

function calculateTotals() {
    let subtotal = 0;
    document.querySelectorAll('#itemsContainer tr').forEach(tr => {
        const qty = parseFloat(tr.querySelector('.item-qty')?.value) || 0;
        const price = parseFloat(tr.querySelector('.item-price')?.value) || 0;
        subtotal += qty * price;
    });

    const vatRate = parseFloat(document.getElementById('vatRate').value) || 0;
    const vatAmount = subtotal * (vatRate / 100);
    const grandTotal = subtotal + vatAmount;

    document.getElementById('subtotal').textContent = formatNumber(subtotal);
    document.getElementById('vatAmount').textContent = formatNumber(vatAmount);
    document.getElementById('grandTotal').textContent = formatNumber(grandTotal);
}

function formatNumber(num) {
    return num.toLocaleString('th-TH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// Item Search Combobox
function initItemSearch(input) {
    const tr = input.closest('tr');
    const dropdown = tr.querySelector('.item-dropdown');
    const idx = input.dataset.idx;

    input.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        if (query.length === 0) {
            dropdown.classList.remove('show');
            return;
        }

        const filtered = catalogItems.filter(item =>
            item.code.toLowerCase().includes(query) ||
            item.name.toLowerCase().includes(query)
        ).slice(0, 10);

        if (filtered.length > 0) {
            dropdown.innerHTML = filtered.map(item => `
                <a href="#" onclick="selectCatalogItem(${idx}, ${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.unit}'); return false;">
                    <small class="text-muted">${item.code}</small> - ${item.name}
                </a>
            `).join('');
            dropdown.classList.add('show');
        } else {
            dropdown.innerHTML = `<div style="padding:6px 10px;color:#888;font-size:0.875rem;"><i class="bi bi-pencil me-1"></i>ใช้ "${query}" เป็นรายละเอียด</div>`;
            dropdown.classList.add('show');
        }
    });

    input.addEventListener('blur', function() {
        setTimeout(() => dropdown.classList.remove('show'), 150);
    });
}

function selectCatalogItem(idx, itemId, name, unit) {
    const tr = document.getElementById(`item_${idx}`);
    if (tr) {
        tr.querySelector('.item-search').value = name;
        tr.querySelector('.item-id-input').value = itemId;
        tr.querySelector('.item-unit').value = unit || 'pcs';
        tr.querySelector('.item-dropdown').classList.remove('show');
    }
}

// Supplier Modal
async function saveNewSupplier() {
    const code = document.getElementById('newSupplierCode').value.trim();
    const name = document.getElementById('newSupplierName').value.trim();
    const contact = document.getElementById('newSupplierContact').value.trim();
    const phone = document.getElementById('newSupplierPhone').value.trim();
    const errorDiv = document.getElementById('supplierError');

    if (!code || !name) {
        errorDiv.textContent = 'กรุณากรอกรหัสและชื่อผู้ขาย';
        errorDiv.classList.remove('d-none');
        return;
    }

    try {
        const response = await fetch('<?= BASE_URL ?>/modules/master/api/supplier_create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code, name, contact_person: contact, phone })
        });
        const result = await response.json();

        if (result.success) {
            // Add to select and select it
            const select = document.getElementById('supplierSelect');
            const option = new Option(`${result.code} - ${result.name}`, result.id, true, true);
            select.appendChild(option);
            bootstrap.Modal.getInstance(document.getElementById('addSupplierModal')).hide();
        } else {
            errorDiv.textContent = result.error || 'เกิดข้อผิดพลาด';
            errorDiv.classList.remove('d-none');
        }
    } catch (error) {
        errorDiv.textContent = 'เกิดข้อผิดพลาดในการเชื่อมต่อ';
        errorDiv.classList.remove('d-none');
    }
}

// Initial
document.addEventListener('DOMContentLoaded', function() {
    // Init search for existing items
    document.querySelectorAll('.item-search').forEach(input => initItemSearch(input));

    <?php if (empty($prItems)): ?>
    addItem();
    <?php endif; ?>
    calculateTotals();
    renumberRows();

    // Reset modal on show
    document.getElementById('addSupplierModal')?.addEventListener('show.bs.modal', function() {
        this.querySelector('#newSupplierCode').value = '';
        this.querySelector('#newSupplierName').value = '';
        this.querySelector('#newSupplierContact').value = '';
        this.querySelector('#newSupplierPhone').value = '';
        this.querySelector('#supplierError').classList.add('d-none');
    });
});
</script>

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-building-add me-2"></i>เพิ่มผู้ขายใหม่</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none" id="supplierError"></div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">รหัส <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="newSupplierCode" placeholder="SUP001">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">ชื่อผู้ขาย <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="newSupplierName" placeholder="บริษัท ABC จำกัด">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ผู้ติดต่อ</label>
                        <input type="text" class="form-control" id="newSupplierContact" placeholder="คุณสมชาย">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">โทรศัพท์</label>
                        <input type="text" class="form-control" id="newSupplierPhone" placeholder="02-xxx-xxxx">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-success" onclick="saveNewSupplier()">
                    <i class="bi bi-check-lg me-1"></i>บันทึก
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.item-dropdown { display: none; position: absolute; top: 100%; left: 0; z-index: 1000; width: 100%; max-height: 200px; overflow-y: auto; background: #fff; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
.item-dropdown.show { display: block; }
.item-dropdown a { display: block; padding: 6px 10px; color: #333; text-decoration: none; }
.item-dropdown a:hover { background: #f0f0f0; }
#itemsTable td { padding: 0.4rem; vertical-align: middle; }
#itemsTable th { padding: 0.5rem 0.4rem; font-size: 0.875rem; }
/* Hide number input spinners */
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
input[type=number] { -moz-appearance: textfield; }
</style>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
