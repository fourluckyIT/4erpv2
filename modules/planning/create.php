<?php
/**
 * Create Plan
 * ERP v2 - Phase 5
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
$docNum = new DocumentNumber();

// Get approved jobs for selection
$jobs = $db->query("
    SELECT j.id, j.job_number, j.scope_short, c.name as customer_name
    FROM jobs j
    JOIN customers c ON j.customer_id = c.id
    WHERE j.status = 'Approved'
    ORDER BY j.id DESC
")->fetchAll();

// Get items for selection using AJAX/Datalist? For now simple fetch all
// In production with many items, this should be AJAX.
$items = $db->query("SELECT id, code, name, unit FROM items WHERE is_active = 1 ORDER BY code")->fetchAll();

if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('create.php');
    }
    
    $jobId = (int) post('job_id');
    $planDate = post('plan_date');
    $planItems = post('items', []);
    
    // Validate
    if (!$jobId || !$planDate) {
        setFlash('error', 'กรุณาระอก Job และวันที่');
        redirect('create.php');
    }
    
    $validItems = array_filter($planItems, fn($i) => !empty($i['item_id']) && $i['qty'] > 0);
    
    if (empty($validItems)) {
        setFlash('error', 'กรุณาระบุรายการอย่างน้อย 1 รายการ');
        redirect('create.php');
    }
    
    try {
        $db->beginTransaction();
        
        $planNumber = $docNum->generate('PLN');
        
        $stmt = $db->prepare("
            INSERT INTO plans (plan_number, job_id, plan_date, notes, status, created_by)
            VALUES (?, ?, ?, ?, 'Draft', ?)
        ");
        $stmt->execute([
            $planNumber,
            $jobId,
            $planDate,
            post('notes'),
            $_SESSION['user_id']
        ]);
        
        $planId = $db->lastInsertId();
        
        $stmtItem = $db->prepare("
            INSERT INTO plan_items (plan_id, item_id, qty, notes)
            VALUES (?, ?, ?, ?)
        ");
        
        foreach ($validItems as $item) {
            $stmtItem->execute([
                $planId,
                $item['item_id'],
                $item['qty'],
                $item['notes'] ?? null
            ]);
        }
        
        $db->commit();
        
        $audit->log('create', 'Plan', $planId, null, ['plan_number' => $planNumber]);
        
        setFlash('success', "สร้างแผนงานเรียบร้อย: $planNumber");
        redirect("view.php?id=$planId");
        
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect('create.php');
    }
}

$pageTitle = 'สร้างแผนงาน - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-plus-square me-2"></i>สร้างแผนงาน (New Plan)</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Planning</a></li>
                    <li class="breadcrumb-item active">สร้างใหม่</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary">ยกเลิก</a>
        </div>
    </div>
</div>

<form method="POST" id="createForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    
    <div class="card mb-4">
        <div class="card-header">ข้อมูลหลัก</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">เลือก Job (ที่อนุมัติแล้ว) <span class="text-danger">*</span></label>
                        <select class="form-select" name="job_id" required>
                            <option value="">-- เลือก Job --</option>
                            <?php foreach ($jobs as $job): ?>
                            <option value="<?= $job['id'] ?>">
                                <?= e($job['job_number']) ?> - <?= e($job['customer_name']) ?> (<?= e($job['scope_short']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">วันที่ปฏิบัติงาน <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="plan_date" required value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="col-12">
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ / รายละเอียดเพิ่มเติม</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>รายการสินค้า/อุปกรณ์ที่ต้องใช้ (Items)</span>
            <button type="button" class="btn btn-sm btn-primary" onclick="addItemRow()">
                <i class="bi bi-plus-circle me-1"></i>เพิ่มรายการ
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0" id="itemTable">
                    <thead>
                        <tr>
                            <th style="width: 50%">สินค้า/อุปกรณ์</th>
                            <th style="width: 15%">จำนวน</th>
                            <th>หมายเหตุ (ถ้ามี)</th>
                            <th style="width: 50px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="item-row">
                            <td>
                                <select class="form-select select2" name="items[0][item_id]" required>
                                    <option value="">-- เลือกสินค้า --</option>
                                    <?php foreach ($items as $item): ?>
                                    <option value="<?= $item['id'] ?>"><?= e($item['code']) ?> - <?= e($item['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" class="form-control" name="items[0][qty]" min="1" value="1" step="0.01" required>
                            </td>
                            <td>
                                <input type="text" class="form-control" name="items[0][notes]">
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="text-end">
        <button type="submit" class="btn btn-success btn-lg">
            <i class="bi bi-save me-1"></i>บันทึกแผนงาน
        </button>
    </div>
</form>

<script>
let rowCount = 1;
const itemOptions = `
    <option value="">-- เลือกสินค้า --</option>
    <?php foreach ($items as $item): ?>
    <option value="<?= $item['id'] ?>"><?= e($item['code']) ?> - <?= e($item['name']) ?></option>
    <?php endforeach; ?>
`;

function addItemRow() {
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = \`
        <td>
            <select class="form-select" name="items[\${rowCount}][item_id]" required>
                \${itemOptions}
            </select>
        </td>
        <td>
            <input type="number" class="form-control" name="items[\${rowCount}][qty]" min="1" value="1" step="0.01" required>
        </td>
        <td>
            <input type="text" class="form-control" name="items[\${rowCount}][notes]">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    \`;
    document.querySelector('#itemTable tbody').appendChild(tr);
    rowCount++;
}

function removeRow(btn) {
    const tbody = document.querySelector('#itemTable tbody');
    if (tbody.children.length > 1) {
        btn.closest('tr').remove();
    } else {
        alert('ต้องมีอย่างน้อย 1 รายการ');
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
