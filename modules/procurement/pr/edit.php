<?php
/**
 * Edit PR
 * ERP v2 - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
$id = (int) get('id');

if (!$id) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get PR
$stmt = $db->prepare("SELECT * FROM purchase_requests WHERE id = ?");
$stmt->execute([$id]);
$pr = $stmt->fetch();

if (!$pr) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Can only edit Draft
if ($pr['status'] !== 'Draft') {
    setFlash('error', 'ไม่สามารถแก้ไข PR ที่ไม่ใช่สถานะแบบร่าง');
    redirect("view.php?id=$id");
}

// Get items
$existingItems = $db->prepare("
    SELECT pri.*, i.code as item_code
    FROM pr_items pri
    LEFT JOIN items i ON pri.item_id = i.id
    WHERE pri.pr_id = ?
");
$existingItems->execute([$id]);
$existingItems = $existingItems->fetchAll();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("edit.php?id=$id");
    }
    
    try {
        $db->beginTransaction();
        
        // Update PR
        $stmt = $db->prepare("
            UPDATE purchase_requests SET 
                job_id = ?, purpose = ?, required_date = ?, notes = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            post('job_id') ?: null,
            post('purpose'),
            post('required_date') ?: null,
            post('notes'),
            $id
        ]);
        
        // Delete old items
        $db->prepare("DELETE FROM pr_items WHERE pr_id = ?")->execute([$id]);
        
        // Insert new items
        $items = post('items', []);
        $totalAmount = 0;
        
        foreach ($items as $item) {
            if (empty($item['description'])) continue;
            
            $qty = (float) ($item['qty'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $amount = $qty * $unitPrice;
            $totalAmount += $amount;
            
            $stmt = $db->prepare("
                INSERT INTO pr_items (pr_id, item_id, description, qty, unit, unit_price, amount)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $id,
                $item['item_id'] ?: null,
                $item['description'],
                $qty,
                $item['unit'] ?? 'pcs',
                $unitPrice,
                $amount
            ]);
        }
        
        // Update total
        $db->prepare("UPDATE purchase_requests SET total_amount = ? WHERE id = ?")->execute([$totalAmount, $id]);
        
        $db->commit();
        
        $audit->log('update', 'PR', $id);
        
        setFlash('success', 'บันทึกเรียบร้อย');
        redirect("view.php?id=$id");
        
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect("edit.php?id=$id");
    }
}

// Get jobs for dropdown
$jobs = $db->query("SELECT id, job_number, scope_short FROM jobs WHERE status NOT IN ('Closed', 'Voided', 'Cancelled') ORDER BY job_number DESC")->fetchAll();

// Get items for dropdown
$catalogItems = $db->query("SELECT id, code, name, unit FROM items WHERE is_active = 1 ORDER BY code")->fetchAll();

$pageTitle = "แก้ไข PR: {$pr['pr_number']} - ERP v2";
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-pencil me-2"></i>แก้ไข <?= e($pr['pr_number']) ?></h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="index.php">PR</a></li>
                    <li class="breadcrumb-item active">แก้ไข</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
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
                            <option value="<?= $j['id'] ?>" <?= $pr['job_id'] == $j['id'] ? 'selected' : '' ?>>
                                <?= e($j['job_number']) ?> - <?= e(mb_substr($j['scope_short'], 0, 30)) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">วัตถุประสงค์ <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="purpose" rows="3" required><?= e($pr['purpose']) ?></textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">วันที่ต้องการ</label>
                        <input type="date" class="form-control" name="required_date" value="<?= $pr['required_date'] ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" name="notes" rows="3"><?= e($pr['notes']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card mb-4">
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
            <i class="bi bi-check-circle me-1"></i>บันทึก
        </button>
    </div>
</form>

<script>
const catalogItems = <?= json_encode($catalogItems) ?>;
const existingItems = <?= json_encode($existingItems) ?>;
let itemIndex = 0;

function addItem(data = null) {
    const tbody = document.getElementById('itemsBody');
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <select class="form-select form-select-sm" name="items[${itemIndex}][item_id]" onchange="selectItem(this, ${itemIndex})">
                <option value="">-- เลือก --</option>
                ${catalogItems.map(i => `<option value="${i.id}" data-name="${i.name}" data-unit="${i.unit}" ${data && data.item_id == i.id ? 'selected' : ''}>${i.code} - ${i.name}</option>`).join('')}
            </select>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" name="items[${itemIndex}][description]" value="${data ? data.description : ''}" required>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm" name="items[${itemIndex}][qty]" value="${data ? data.qty : 1}" step="0.01" min="0" onchange="calcRow(${itemIndex})">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" name="items[${itemIndex}][unit]" value="${data ? data.unit : 'pcs'}">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm" name="items[${itemIndex}][unit_price]" value="${data ? data.unit_price : 0}" step="0.01" min="0" onchange="calcRow(${itemIndex})">
        </td>
        <td>
            <span id="amount_${itemIndex}">${data ? parseFloat(data.amount).toFixed(2) : '0.00'}</span>
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
    let total = 0;
    document.querySelectorAll('[id^="amount_"]').forEach(el => {
        total += parseFloat(el.textContent) || 0;
    });
    document.getElementById('grandTotal').textContent = total.toFixed(2);
}

// Load existing items
existingItems.forEach(item => addItem(item));
if (existingItems.length === 0) addItem();
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
