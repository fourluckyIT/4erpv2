<?php
/**
 * Create PR
 * ERP v2 - Phase 4
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
            post('purpose'),
            post('required_date') ?: null,
            post('notes'),
            $_SESSION['user_id']
        ]);
        
        $prId = $db->lastInsertId();
        
        // Insert items
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
        
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect('create.php');
    }
}

// Get jobs for dropdown
$jobs = $db->query("SELECT id, job_number, scope_short FROM jobs WHERE status NOT IN ('Closed', 'Voided', 'Cancelled') ORDER BY job_number DESC")->fetchAll();

// Get items for dropdown
$catalogItems = $db->query("SELECT id, code, name, unit FROM items WHERE is_active = 1 ORDER BY code")->fetchAll();

$pageTitle = 'สร้าง PR - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
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
            <a href="index.php" class="btn btn-outline-secondary">
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
                        <!-- Items added by JS -->
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
            <i class="bi bi-check-circle me-1"></i>บันทึก PR
        </button>
    </div>
</form>

<script>
const catalogItems = <?= json_encode($catalogItems) ?>;
let itemIndex = 0;

function addItem() {
    const tbody = document.getElementById('itemsBody');
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <select class="form-select form-select-sm" name="items[${itemIndex}][item_id]" onchange="selectItem(this, ${itemIndex})">
                <option value="">-- เลือก --</option>
                ${catalogItems.map(i => `<option value="${i.id}" data-name="${i.name}" data-unit="${i.unit}">${i.code} - ${i.name}</option>`).join('')}
            </select>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" name="items[${itemIndex}][description]" required>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm" name="items[${itemIndex}][qty]" value="1" step="0.01" min="0" onchange="calcRow(${itemIndex})">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" name="items[${itemIndex}][unit]" value="pcs">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm" name="items[${itemIndex}][unit_price]" value="0" step="0.01" min="0" onchange="calcRow(${itemIndex})">
        </td>
        <td>
            <span id="amount_${itemIndex}">0.00</span>
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); calcTotal();">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(row);
    itemIndex++;
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

// Add first item row
addItem();
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
