<?php
/**
 * Create PR for Manpower
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
        redirect('manpower.php');
    }
    
    // Validation
    $purpose = trim(post('purpose', ''));
    if (empty($purpose)) {
        setFlash('error', 'กรุณาระบุวัตถุประสงค์');
        redirect('manpower.php');
    }
    
    $positions = post('positions', []);
    $validPositions = array_filter($positions, fn($p) => !empty($p['position_name']) && !empty($p['qty']));
    if (empty($validPositions)) {
        setFlash('error', 'กรุณาเพิ่มอย่างน้อย 1 ตำแหน่ง');
        redirect('manpower.php');
    }
    
    try {
        $db->beginTransaction();
        
        // Generate PR number
        $prNumber = $docNum->generate('PR');
        
        // Insert PR
        $stmt = $db->prepare("
            INSERT INTO purchase_requests (pr_number, job_id, requester_id, purpose, required_date, notes, pr_type, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 'Manpower', ?)
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
        
        // Insert manpower items
        $totalAmount = 0;
        
        foreach ($validPositions as $pos) {
            $qty = (int) ($pos['qty'] ?? 1);
            $dailyRate = (float) ($pos['daily_rate'] ?? 0);
            $amount = $qty * $dailyRate;
            $totalAmount += $amount;
            
            $description = $pos['position_name'];
            if (!empty($pos['skill_requirements'])) {
                $description .= ' - ' . $pos['skill_requirements'];
            }
            
            $stmt = $db->prepare("
                INSERT INTO pr_items (pr_id, item_id, description, qty, unit, unit_price, amount, notes)
                VALUES (?, NULL, ?, ?, 'คน', ?, ?, ?)
            ");
            $stmt->execute([
                $prId,
                $description,
                $qty,
                $dailyRate,
                $amount,
                "ค่าแรงวันละ " . number_format($dailyRate, 2) . " บาท"
            ]);
        }
        
        // Update total
        $db->prepare("UPDATE purchase_requests SET total_amount = ? WHERE id = ?")->execute([$totalAmount, $prId]);
        
        $db->commit();
        
        $audit->log('create', 'PR', $prId, null, ['pr_number' => $prNumber, 'type' => 'Manpower']);
        
        setFlash('success', "สร้าง PR Manpower เรียบร้อย: $prNumber");
        redirect("view.php?id=$prId");
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $audit->log('create_failed', 'PR', null, null, ['error' => $e->getMessage()]);
        
        $errorMsg = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล';
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            $errorMsg = 'เลขที่เอกสารซ้ำ กรุณาลองใหม่';
        }
        setFlash('error', $errorMsg);
        redirect('manpower.php');
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $audit->log('create_failed', 'PR', null, null, ['error' => $e->getMessage()]);
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect('manpower.php');
    }
}

// Get jobs for dropdown
$jobs = $db->query("SELECT id, job_number, scope_short FROM jobs WHERE status NOT IN ('Closed', 'Voided', 'Cancelled') ORDER BY job_number DESC")->fetchAll();

$pageTitle = 'สร้าง PR Manpower - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-people me-2"></i>สร้าง PR Manpower</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="index.php">PR</a></li>
                    <li class="breadcrumb-item active">Manpower</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="create.php" class="btn btn-outline-primary me-2">
                <i class="bi bi-box me-1"></i>PR สินค้า
            </a>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
        </div>
    </div>
</div>

<form method="POST" id="prManpowerForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    
    <div class="card mb-4">
        <div class="card-header">ข้อมูลทั่วไป</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Job <small class="text-muted">(ถ้ามี)</small></label>
                        <select class="form-select" name="job_id">
                            <option value="">-- ไม่ระบุ Job (สำหรับบุคลากรทั่วไป) --</option>
                            <?php foreach ($jobs as $j): ?>
                            <option value="<?= $j['id'] ?>"><?= e($j['job_number']) ?> - <?= e(mb_substr($j['scope_short'], 0, 30)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">สามารถเลือก Job หรือเว้นว่างสำหรับบุคลากรทั่วไปของบริษัท</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">วัตถุประสงค์ <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="purpose" rows="3" required placeholder="ระบุวัตถุประสงค์ในการขอ Manpower..."></textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">วันที่ต้องการเริ่มงาน</label>
                        <input type="date" class="form-control" name="required_date">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" name="notes" rows="3" placeholder="เช่น ต้องมีใบรับรอง, ประสบการณ์ขั้นต่ำ..."></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-person-badge me-2"></i>ตำแหน่งที่ต้องการ</span>
            <button type="button" class="btn btn-sm btn-success" onclick="addPosition()">
                <i class="bi bi-plus-circle me-1"></i>เพิ่มตำแหน่ง
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0" id="positionsTable">
                    <thead>
                        <tr>
                            <th style="width: 200px;">ตำแหน่ง</th>
                            <th>ทักษะ/คุณสมบัติ</th>
                            <th style="width: 100px;">จำนวน (คน)</th>
                            <th style="width: 150px;">ค่าแรง/วัน (บาท)</th>
                            <th style="width: 150px;">รวม</th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody id="positionsBody">
                        <!-- Positions added by JS -->
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-end"><strong>รวมทั้งสิ้น</strong></td>
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
            <i class="bi bi-check-circle me-1"></i>บันทึก PR Manpower
        </button>
    </div>
</form>

<script>
let posIndex = 0;

const commonPositions = [
    'ช่างไฟฟ้า',
    'ช่างเชื่อม',
    'ช่างประปา',
    'ช่างเครื่องกล',
    'ช่างทั่วไป',
    'คนงานทั่วไป',
    'หัวหน้างาน',
    'วิศวกร',
    'Safety Officer',
    'Rigger'
];

function addPosition() {
    const tbody = document.getElementById('positionsBody');
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <input type="text" class="form-control form-control-sm" 
                   name="positions[${posIndex}][position_name]" 
                   list="positionList" 
                   placeholder="เลือกหรือพิมพ์..." required>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" 
                   name="positions[${posIndex}][skill_requirements]" 
                   placeholder="เช่น มีใบรับรอง, ประสบการณ์ 2 ปี...">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm" 
                   name="positions[${posIndex}][qty]" value="1" min="1" 
                   onchange="calcPosRow(${posIndex})">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm" 
                   name="positions[${posIndex}][daily_rate]" value="500" step="0.01" min="0" 
                   onchange="calcPosRow(${posIndex})">
        </td>
        <td>
            <span id="posAmount_${posIndex}">500.00</span>
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); calcPosTotal();">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(row);
    posIndex++;
    calcPosTotal();
}

function calcPosRow(idx) {
    const qty = parseInt(document.querySelector(`[name="positions[${idx}][qty]"]`).value) || 0;
    const rate = parseFloat(document.querySelector(`[name="positions[${idx}][daily_rate]"]`).value) || 0;
    const amount = qty * rate;
    document.getElementById(`posAmount_${idx}`).textContent = amount.toFixed(2);
    calcPosTotal();
}

function calcPosTotal() {
    let total = 0;
    document.querySelectorAll('[id^="posAmount_"]').forEach(el => {
        total += parseFloat(el.textContent) || 0;
    });
    document.getElementById('grandTotal').textContent = total.toFixed(2);
}

// Add first position row
addPosition();
</script>

<!-- Position datalist -->
<datalist id="positionList">
    <?php foreach (['ช่างไฟฟ้า', 'ช่างเชื่อม', 'ช่างประปา', 'ช่างเครื่องกล', 'ช่างทั่วไป', 'คนงานทั่วไป', 'หัวหน้างาน', 'วิศวกร', 'Safety Officer', 'Rigger'] as $pos): ?>
    <option value="<?= $pos ?>">
    <?php endforeach; ?>
</datalist>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
