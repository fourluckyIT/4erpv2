<?php
/**
 * Manpower Registration from PO
 * ERP v2 - Phase 4
 * 
 * Layout: Left sidebar shows positions from PO, right side shows registration form
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();

$poId = (int) get('po_id');
$selectedPosition = get('position', '');

if (!$poId) {
    setFlash('error', 'ต้องระบุ PO');
    redirect('../po/');
}

// Get PO
$stmt = $db->prepare("
    SELECT po.*, s.name as supplier_name, s.id as supplier_id
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.id = ? AND po.po_type = 'Manpower' AND po.status = 'Approved'
");
$stmt->execute([$poId]);
$po = $stmt->fetch();

if (!$po) {
    setFlash('error', 'ไม่พบ PO แรงงานที่อนุมัติแล้ว');
    redirect('../po/');
}

// Get PO items (positions) - grouped by description, sorted by qty ascending
$poItems = $db->prepare("
    SELECT id, description, qty, unit_price, amount
    FROM po_items
    WHERE po_id = ?
    ORDER BY qty ASC, description ASC
");
$poItems->execute([$poId]);
$poItems = $poItems->fetchAll();

// Get registered count per position
$registeredCounts = [];
$registeredByPosition = $db->prepare("
    SELECT position, COUNT(*) as cnt
    FROM po_manpower
    WHERE po_id = ?
    GROUP BY position
");
$registeredByPosition->execute([$poId]);
foreach ($registeredByPosition->fetchAll() as $row) {
    $registeredCounts[$row['position']] = (int) $row['cnt'];
}

// Get existing manpower for selected position (or all if none selected)
$existingManpowerSql = "
    SELECT pm.*, p.full_name, p.code as people_code, p.id_card, p.phone
    FROM po_manpower pm
    JOIN people p ON pm.people_id = p.id
    WHERE pm.po_id = ?
";
if ($selectedPosition) {
    $existingManpowerSql .= " AND pm.position = ?";
    $stmt = $db->prepare($existingManpowerSql . " ORDER BY pm.created_at DESC");
    $stmt->execute([$poId, $selectedPosition]);
} else {
    $stmt = $db->prepare($existingManpowerSql . " ORDER BY pm.position, pm.created_at DESC");
    $stmt->execute([$poId]);
}
$existingManpower = $stmt->fetchAll();

// Get available external people from same supplier
$availablePeople = $db->prepare("
    SELECT p.* FROM people p
    WHERE p.people_type = 'External' 
    AND p.is_active = 1 
    AND (p.supplier_id = ? OR p.supplier_id IS NULL)
    AND p.id NOT IN (SELECT people_id FROM po_manpower WHERE po_id = ?)
    ORDER BY p.full_name
");
$availablePeople->execute([$po['supplier_id'], $poId]);
$availablePeople = $availablePeople->fetchAll();

// Calculate totals
$totalRequired = array_sum(array_column($poItems, 'qty'));
$totalRegistered = array_sum($registeredCounts);

// Helper function to check position quota
function getPositionQuota($db, $poId, $position) {
    // Get required qty from PO items
    $stmt = $db->prepare("SELECT qty FROM po_items WHERE po_id = ? AND description = ?");
    $stmt->execute([$poId, $position]);
    $item = $stmt->fetch();
    $required = $item ? (int) $item['qty'] : 0;
    
    // Get registered count
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM po_manpower WHERE po_id = ? AND position = ?");
    $stmt->execute([$poId, $position]);
    $registered = (int) $stmt->fetch()['cnt'];
    
    return ['required' => $required, 'registered' => $registered, 'remaining' => $required - $registered];
}

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("manpower.php?po_id=$poId");
    }
    
    $formAction = post('form_action');
    $position = post('position');
    
    // Check quota before adding (for add_existing and create_new)
    if (in_array($formAction, ['add_existing', 'create_new']) && $position) {
        $quota = getPositionQuota($db, $poId, $position);
        if ($quota['remaining'] <= 0) {
            setFlash('error', "ตำแหน่ง \"$position\" ลงทะเบียนครบแล้ว ({$quota['registered']}/{$quota['required']} คน)");
            redirect("manpower.php?po_id=$poId" . ($position ? "&position=" . urlencode($position) : ""));
        }
    }
    
    if ($formAction === 'add_existing') {
        $peopleId = (int) post('people_id');
        if ($peopleId) {
            $stmt = $db->prepare("
                INSERT INTO po_manpower (po_id, people_id, position, daily_rate, contract_start, contract_end)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $poId,
                $peopleId,
                $position,
                (float) post('daily_rate', 0),
                post('contract_start') ?: null,
                post('contract_end') ?: null
            ]);
            
            $audit->log('add_manpower', 'PO', $poId, null, ['people_id' => $peopleId]);
            
            // Check remaining after add
            $quota = getPositionQuota($db, $poId, $position);
            setFlash('success', "เพิ่มแรงงานเรียบร้อย (ลงทะเบียนแล้ว {$quota['registered']}/{$quota['required']} คน)");
        }
        
    } elseif ($formAction === 'create_new') {
        // Create new person first
        $docNum = new DocumentNumber();
        $code = 'EXT-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare("
            INSERT INTO people (code, full_name, people_type, position, phone, id_card, daily_rate, supplier_id, hire_date, created_by)
            VALUES (?, ?, 'External', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $code,
            post('full_name'),
            $position,
            post('phone'),
            post('id_card'),
            (float) post('daily_rate', 0),
            $po['supplier_id'],
            date('Y-m-d'),
            $_SESSION['user_id']
        ]);
        
        $peopleId = $db->lastInsertId();
        
        // Link to PO
        $stmt = $db->prepare("
            INSERT INTO po_manpower (po_id, people_id, position, daily_rate, contract_start, contract_end)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $poId,
            $peopleId,
            $position,
            (float) post('daily_rate', 0),
            post('contract_start') ?: null,
            post('contract_end') ?: null
        ]);
        
        $audit->log('create_manpower', 'PO', $poId, null, ['people_id' => $peopleId, 'name' => post('full_name')]);
        
        // Check remaining after add
        $quota = getPositionQuota($db, $poId, $position);
        setFlash('success', "สร้างและเพิ่มแรงงานเรียบร้อย (ลงทะเบียนแล้ว {$quota['registered']}/{$quota['required']} คน)");
        
    } elseif ($formAction === 'remove') {
        $pmId = (int) post('pm_id');
        $db->prepare("DELETE FROM po_manpower WHERE id = ? AND po_id = ?")->execute([$pmId, $poId]);
        setFlash('success', 'ลบออกเรียบร้อย');
    }
    
    // Redirect back to same position if selected
    $redirectUrl = "manpower.php?po_id=$poId";
    if ($position && $formAction !== 'remove') {
        $redirectUrl .= "&position=" . urlencode($position);
    }
    redirect($redirectUrl);
}

$pageTitle = 'ลงทะเบียนแรงงาน - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<style>
.position-card {
    cursor: pointer;
    transition: all 0.2s;
    border-left: 4px solid transparent;
}
.position-card:hover {
    background-color: #f8f9fa;
}
.position-card.active {
    background-color: #e7f1ff;
    border-left-color: #0d6efd;
}
.position-card.complete {
    border-left-color: #198754;
}
.position-card.partial {
    border-left-color: #ffc107;
}
.position-card.empty {
    border-left-color: #dc3545;
}
.progress-mini {
    height: 6px;
}
</style>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-0"><i class="bi bi-people me-2"></i>ลงทะเบียนแรงงาน</h4>
            <small class="text-muted">
                PO: <strong><?= e($po['po_number']) ?></strong> | ผู้ขาย: <strong><?= e($po['supplier_name']) ?></strong>
            </small>
        </div>
        <div>
            <a href="../po/view.php?id=<?= $poId ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>กลับ PO
            </a>
        </div>
    </div>
</div>

<!-- Progress Summary -->
<div class="alert alert-<?= $totalRegistered >= $totalRequired ? 'success' : ($totalRegistered > 0 ? 'warning' : 'info') ?> py-2">
    <div class="d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-<?= $totalRegistered >= $totalRequired ? 'check-circle' : 'hourglass-split' ?> me-2"></i>
            ลงทะเบียนแล้ว <strong><?= $totalRegistered ?></strong> / <strong><?= $totalRequired ?></strong> คน
        </span>
        <span class="badge bg-<?= $totalRegistered >= $totalRequired ? 'success' : 'secondary' ?>">
            <?= $totalRequired > 0 ? round($totalRegistered / $totalRequired * 100) : 0 ?>%
        </span>
    </div>
    <div class="progress progress-mini mt-2">
        <div class="progress-bar" style="width: <?= $totalRequired > 0 ? ($totalRegistered / $totalRequired * 100) : 0 ?>%"></div>
    </div>
</div>

<div class="row">
    <!-- Left: Position List from PO -->
    <div class="col-md-4 col-lg-3">
        <div class="card">
            <div class="card-header py-2">
                <strong><i class="bi bi-list-ul me-2"></i>ตำแหน่งจาก PO</strong>
            </div>
            <div class="list-group list-group-flush">
                <!-- All positions link -->
                <a href="?po_id=<?= $poId ?>" 
                   class="list-group-item list-group-item-action position-card <?= !$selectedPosition ? 'active' : '' ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-people me-2"></i>ทั้งหมด</span>
                        <span class="badge bg-secondary"><?= $totalRegistered ?>/<?= $totalRequired ?></span>
                    </div>
                </a>
                
                <?php foreach ($poItems as $item): 
                    $posName = $item['description'];
                    $required = (int) $item['qty'];
                    $registered = $registeredCounts[$posName] ?? 0;
                    $isComplete = $registered >= $required;
                    $isPartial = $registered > 0 && $registered < $required;
                    $statusClass = $isComplete ? 'complete' : ($isPartial ? 'partial' : 'empty');
                ?>
                <a href="?po_id=<?= $poId ?>&position=<?= urlencode($posName) ?>" 
                   class="list-group-item list-group-item-action position-card <?= $statusClass ?> <?= $selectedPosition === $posName ? 'active' : '' ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-medium"><?= e($posName) ?></div>
                            <small class="text-muted"><?= formatNumber($item['unit_price']) ?> บาท/วัน</small>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-<?= $isComplete ? 'success' : ($isPartial ? 'warning text-dark' : 'danger') ?>">
                                <?= $registered ?>/<?= $required ?>
                            </span>
                        </div>
                    </div>
                    <div class="progress progress-mini mt-2">
                        <div class="progress-bar bg-<?= $isComplete ? 'success' : ($isPartial ? 'warning' : 'danger') ?>" 
                             style="width: <?= $required > 0 ? ($registered / $required * 100) : 0 ?>%"></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Right: Registration Area -->
    <div class="col-md-8 col-lg-9">
        <?php 
        // Get selected position info
        $selectedItem = null;
        $selectedRate = 0;
        if ($selectedPosition) {
            foreach ($poItems as $item) {
                if ($item['description'] === $selectedPosition) {
                    $selectedItem = $item;
                    $selectedRate = $item['unit_price'];
                    break;
                }
            }
        }
        ?>
        
        <?php if ($selectedPosition && $selectedItem): ?>
        <!-- Selected Position Header -->
        <div class="alert alert-primary py-2 mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-person-badge me-2"></i>
                    <strong><?= e($selectedPosition) ?></strong>
                    <span class="ms-2 text-muted">ค่าแรง: <?= formatNumber($selectedRate) ?> บาท/วัน</span>
                </span>
                <span>
                    ต้องการ: <strong><?= (int) $selectedItem['qty'] ?></strong> คน |
                    ลงทะเบียนแล้ว: <strong><?= $registeredCounts[$selectedPosition] ?? 0 ?></strong> คน
                </span>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Registration Forms -->
        <div class="row mb-4">
            <!-- Create New Person -->
            <div class="col-lg-6 mb-3">
                <div class="card h-100">
                    <div class="card-header py-2 bg-success text-white">
                        <i class="bi bi-person-plus-fill me-2"></i>ลงทะเบียนใหม่
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="form_action" value="create_new">
                            
                            <div class="mb-2">
                                <label class="form-label small mb-1">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="full_name" required placeholder="ชื่อ นามสกุล">
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <div class="mb-2">
                                        <label class="form-label small mb-1">บัตรประชาชน <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-sm" name="id_card" required maxlength="13" placeholder="13 หลัก">
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="mb-2">
                                        <label class="form-label small mb-1">โทรศัพท์</label>
                                        <input type="text" class="form-control form-control-sm" name="phone" placeholder="0xx-xxx-xxxx">
                                    </div>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1">ตำแหน่ง <span class="text-danger">*</span></label>
                                <?php if ($selectedPosition): ?>
                                <input type="text" class="form-control form-control-sm" name="position" value="<?= e($selectedPosition) ?>" readonly>
                                <?php else: ?>
                                <select class="form-select form-select-sm" name="position" required>
                                    <option value="">-- เลือกตำแหน่ง --</option>
                                    <?php foreach ($poItems as $item): ?>
                                    <option value="<?= e($item['description']) ?>" data-rate="<?= $item['unit_price'] ?>">
                                        <?= e($item['description']) ?> (<?= formatNumber($item['unit_price']) ?>/วัน)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <div class="mb-2">
                                        <label class="form-label small mb-1">เริ่มสัญญา</label>
                                        <input type="date" class="form-control form-control-sm" name="contract_start" value="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="mb-2">
                                        <label class="form-label small mb-1">สิ้นสุด</label>
                                        <input type="date" class="form-control form-control-sm" name="contract_end">
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small mb-1">ค่าแรง/วัน</label>
                                <input type="number" class="form-control form-control-sm" name="daily_rate" step="0.01" 
                                       value="<?= $selectedRate ?>" id="newDailyRate">
                            </div>
                            <button type="submit" class="btn btn-success btn-sm w-100">
                                <i class="bi bi-person-plus me-1"></i>ลงทะเบียน
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Add Existing Person -->
            <div class="col-lg-6 mb-3">
                <div class="card h-100">
                    <div class="card-header py-2 bg-primary text-white">
                        <i class="bi bi-person-check me-2"></i>เพิ่มจากรายชื่อเดิม
                    </div>
                    <div class="card-body">
                        <?php if (empty($availablePeople)): ?>
                        <p class="text-muted text-center py-4">ไม่มีรายชื่อที่สามารถเพิ่มได้</p>
                        <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="form_action" value="add_existing">
                            
                            <div class="mb-2">
                                <label class="form-label small mb-1">เลือกบุคคล <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm" name="people_id" id="existingPerson" required>
                                    <option value="">-- เลือก --</option>
                                    <?php foreach ($availablePeople as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-rate="<?= $p['daily_rate'] ?>" data-position="<?= e($p['position']) ?>">
                                        <?= e($p['full_name']) ?> <?= $p['id_card'] ? '(' . substr($p['id_card'], -4) . ')' : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1">ตำแหน่ง <span class="text-danger">*</span></label>
                                <?php if ($selectedPosition): ?>
                                <input type="text" class="form-control form-control-sm" name="position" value="<?= e($selectedPosition) ?>" readonly>
                                <?php else: ?>
                                <select class="form-select form-select-sm" name="position" id="existingPosition" required>
                                    <option value="">-- เลือกตำแหน่ง --</option>
                                    <?php foreach ($poItems as $item): ?>
                                    <option value="<?= e($item['description']) ?>" data-rate="<?= $item['unit_price'] ?>">
                                        <?= e($item['description']) ?> (<?= formatNumber($item['unit_price']) ?>/วัน)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <div class="mb-2">
                                        <label class="form-label small mb-1">เริ่มสัญญา</label>
                                        <input type="date" class="form-control form-control-sm" name="contract_start" value="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="mb-2">
                                        <label class="form-label small mb-1">สิ้นสุด</label>
                                        <input type="date" class="form-control form-control-sm" name="contract_end">
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small mb-1">ค่าแรง/วัน</label>
                                <input type="number" class="form-control form-control-sm" name="daily_rate" step="0.01" 
                                       value="<?= $selectedRate ?>" id="existingDailyRate">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-plus-circle me-1"></i>เพิ่ม
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Registered List -->
        <div class="card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-people-fill me-2"></i>
                    รายชื่อที่ลงทะเบียนแล้ว
                    <?php if ($selectedPosition): ?>
                    <span class="text-muted">- <?= e($selectedPosition) ?></span>
                    <?php endif; ?>
                </span>
                <span class="badge bg-secondary"><?= count($existingManpower) ?> คน</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="50">#</th>
                            <th>ชื่อ-นามสกุล</th>
                            <th>บัตร ปชช.</th>
                            <?php if (!$selectedPosition): ?>
                            <th>ตำแหน่ง</th>
                            <?php endif; ?>
                            <th>ค่าแรง/วัน</th>
                            <th>สัญญา</th>
                            <th width="60"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($existingManpower)): ?>
                        <tr><td colspan="<?= $selectedPosition ? 6 : 7 ?>" class="text-center text-muted py-4">ยังไม่มีรายชื่อ</td></tr>
                        <?php else: ?>
                        <?php $i = 1; foreach ($existingManpower as $mp): ?>
                        <tr>
                            <td class="text-muted"><?= $i++ ?></td>
                            <td>
                                <strong><?= e($mp['full_name']) ?></strong>
                                <?php if ($mp['phone']): ?>
                                <br><small class="text-muted"><i class="bi bi-telephone"></i> <?= e($mp['phone']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><code><?= e($mp['id_card'] ?: '-') ?></code></td>
                            <?php if (!$selectedPosition): ?>
                            <td><span class="badge bg-light text-dark"><?= e($mp['position']) ?></span></td>
                            <?php endif; ?>
                            <td><?= formatNumber($mp['daily_rate']) ?></td>
                            <td>
                                <small>
                                    <?= $mp['contract_start'] ? formatDate($mp['contract_start']) : '-' ?>
                                    <?= $mp['contract_end'] ? '<br>ถึง ' . formatDate($mp['contract_end']) : '' ?>
                                </small>
                            </td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="form_action" value="remove">
                                    <input type="hidden" name="pm_id" value="<?= $mp['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('ลบ <?= e($mp['full_name']) ?> ออก?')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-fill daily rate when selecting position
document.querySelectorAll('select[name="position"]').forEach(select => {
    select.addEventListener('change', function() {
        const rate = this.options[this.selectedIndex].dataset.rate || 0;
        const form = this.closest('form');
        const rateInput = form.querySelector('input[name="daily_rate"]');
        if (rateInput) rateInput.value = rate;
    });
});

// Auto-fill rate when selecting existing person
const existingPerson = document.getElementById('existingPerson');
if (existingPerson) {
    existingPerson.addEventListener('change', function() {
        const rate = this.options[this.selectedIndex].dataset.rate || <?= $selectedRate ?>;
        document.getElementById('existingDailyRate').value = rate;
    });
}
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
