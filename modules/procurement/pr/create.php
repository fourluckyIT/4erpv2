<?php
/**
 * Create PR
 * 4ERP - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
$docNum = new DocumentNumber();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('create.php');
    }
    
    // Validation
    $purpose = trim(post('purpose', ''));
    if (empty($purpose)) {
        setFlash('error', 'กรุณาระบุวัตถุประสงค์');
        redirect('create.php');
    }
    
    $items = post('items', []);
    $validItems = array_filter($items, fn($item) => !empty($item['description']));
    if (empty($validItems)) {
        setFlash('error', 'กรุณาเพิ่มอย่างน้อย 1 รายการ');
        redirect('create.php');
    }
    
    try {
        $db->beginTransaction();
        
        // Generate PR number
        $prNumber = $docNum->generate('PR');
        
        // Insert PR
        $stmt = $db->prepare("
            INSERT INTO purchase_requests (pr_number, job_id, requester_id, purpose, required_date, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $prNumber,
            post('job_id') ?: null,
            $_SESSION['user_id'],
            $purpose,
            post('required_date') ?: null,
            post('notes'),
            $_SESSION['user_id']
        ]);
        
        $prId = $db->lastInsertId();
        
        // Insert items
        $totalAmount = 0;
        
        foreach ($validItems as $item) {
            $qty = (float) ($item['qty'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $amount = $qty * $unitPrice;
            $totalAmount += $amount;
            
            $stmt = $db->prepare("
                INSERT INTO pr_items (pr_id, item_id, description, qty, unit, unit_price, amount)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $prId,
                $item['item_id'] ?: null,
                $item['description'],
                $qty,
                $item['unit'] ?? 'pcs',
                $unitPrice,
                $amount
            ]);
        }
        
        // Update total
        $db->prepare("UPDATE purchase_requests SET total_amount = ? WHERE id = ?")->execute([$totalAmount, $prId]);
        
        $db->commit();
        
        $audit->log('create', 'PR', $prId, null, ['pr_number' => $prNumber]);
        
        setFlash('success', "สร้าง PR เรียบร้อย: $prNumber");
        redirect("view.php?id=$prId");
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        // Log failure to audit
        $audit->log('create_failed', 'PR', null, null, ['error' => $e->getMessage()]);
        
        // User-friendly error message
        $errorMsg = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล';
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            $errorMsg = 'เลขที่เอกสารซ้ำ กรุณาลองใหม่';
        }
        setFlash('error', $errorMsg);
        redirect('create.php');
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $audit->log('create_failed', 'PR', null, null, ['error' => $e->getMessage()]);
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect('create.php');
    }
}

// Get jobs for dropdown
$jobs = $db->query("SELECT id, job_number, scope_short FROM jobs WHERE status NOT IN ('Closed', 'Voided', 'Cancelled') ORDER BY job_number DESC")->fetchAll();

// Get items for dropdown
$catalogItems = $db->query("SELECT id, code, name, unit FROM items WHERE is_active = 1 ORDER BY code")->fetchAll();

$pageTitle = 'สร้าง PR - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-file-text me-2"></i>สร้าง Purchase Request</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="index.php">PR</a></li>
                    <li class="breadcrumb-item active">สร้าง</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="manpower.php" class="btn btn-outline-primary me-2">
                <i class="bi bi-people me-1"></i>PR Manpower
            </a>
            <a href="javascript:history.back()" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
        </div>
    </div>
</div>

<form method="POST" id="prForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    
    <div class="card mb-4">
        <div class="card-header">ข้อมูลทั่วไป</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Job (ถ้ามี)</label>
                        <select class="form-select" name="job_id">
                            <option value="">-- ไม่ระบุ --</option>
                            <?php foreach ($jobs as $j): ?>
                            <option value="<?= $j['id'] ?>"><?= e($j['job_number']) ?> - <?= e(mb_substr($j['scope_short'], 0, 30)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">วัตถุประสงค์ <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="purpose" rows="3" required placeholder="ระบุวัตถุประสงค์ในการขอซื้อ..."></textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">วันที่ต้องการ</label>
                        <input type="date" class="form-control" name="required_date">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
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
                            <th width="90" class="text-center">จำนวน</th>
                            <th width="60" class="text-center">หน่วย</th>
                            <th width="100" class="text-end">ราคา/หน่วย</th>
                            <th width="100" class="text-end">รวม</th>
                            <th width="40"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <!-- Items added by JS -->
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="5" class="text-end"><strong>รวมทั้งสิ้น</strong></td>
                            <td class="text-end"><strong class="text-primary" id="grandTotal">0.00</strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>ยกเลิก
        </a>
        <button type="submit" class="btn btn-primary btn-lg px-5">
            <i class="bi bi-check-circle me-1"></i>บันทึก PR
        </button>
    </div>
</form>

<script>
const catalogItems = <?= json_encode($catalogItems) ?>;
let itemIndex = 0;

function addItem() {
    const tbody = document.getElementById('itemsContainer');
    const tr = document.createElement('tr');
    tr.id = `item_${itemIndex}`;
    tr.innerHTML = `
        <td class="align-middle text-center text-muted row-num">${tbody.children.length + 1}</td>
        <td class="position-relative">
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
        calcTotal();
        renumberRows();
    }
}

function renumberRows() {
    document.querySelectorAll('#itemsContainer tr').forEach((tr, i) => {
        const numCell = tr.querySelector('.row-num');
        if (numCell) numCell.textContent = i + 1;
    });
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
                <a href="#" class="dropdown-item py-1" onclick="selectCatalogItem(${idx}, ${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.unit}'); return false;">
                    <small class="text-muted">${item.code}</small> - ${item.name}
                </a>
            `).join('');
            dropdown.classList.add('show');
        } else {
            dropdown.innerHTML = `<div class="dropdown-item text-muted small py-1"><i class="bi bi-pencil me-1"></i>ใช้ "${query}" เป็นรายละเอียด</div>`;
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

function calcRow(idx) {
    const qty = parseFloat(document.querySelector(`[name="items[${idx}][qty]"]`)?.value) || 0;
    const price = parseFloat(document.querySelector(`[name="items[${idx}][unit_price]"]`)?.value) || 0;
    const amount = qty * price;
    const el = document.getElementById(`amount_${idx}`);
    if (el) el.value = formatNumber(amount);
    calcTotal();
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('[id^="amount_"]').forEach(el => {
        total += parseFloat(el.value.replace(/,/g, '')) || 0;
    });
    document.getElementById('grandTotal').textContent = formatNumber(total);
}

function formatNumber(num) {
    return num.toLocaleString('th-TH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// Add first item row
addItem();
</script>

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
