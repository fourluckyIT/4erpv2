<?php
/**
 * Manpower Registration from PO
 * 4ERP - Phase 4
 * 
 * Layout: Single table for registered and remaining manpower
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();

$poId = (int) get('po_id');
if (!$poId) {
    setFlash('error', 'ต้องระบุ PO');
    redirect('../po/');
}

// Get PO
$stmt = $db->prepare("
    SELECT po.*, s.name as supplier_name, s.id as supplier_id
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.id = ? AND po.po_type = 'Manpower' AND po.status IN ('Approved', 'Partially Received', 'Received')
");
$stmt->execute([$poId]);
$po = $stmt->fetch();

if (!$po) {
    setFlash('error', 'ไม่พบ PO แรงงานที่อนุมัติแล้ว');
    redirect('../po/');
}

// Get PO items (positions) in PO order
$poItems = $db->prepare("
    SELECT id, description, qty, unit, unit_price, amount, manpower_duration, manpower_unit
    FROM po_items
    WHERE po_id = ?
    ORDER BY id ASC
");
$poItems->execute([$poId]);
$poItems = $poItems->fetchAll();

$normalizeUnit = function (array $item): string {
    if (!empty($item['manpower_unit'])) {
        return $item['manpower_unit'];
    }
    $unit = mb_strtolower((string) ($item['unit'] ?? ''));
    if ($unit !== '') {
        if (str_contains($unit, 'เดือน') || str_contains($unit, 'month') || str_contains($unit, 'คน-เดือน')) {
            return 'Month';
        }
        if (str_contains($unit, 'วัน') || str_contains($unit, 'day') || str_contains($unit, 'คน-วัน')) {
            return 'Day';
        }
    }
    return 'Day';
};

$poItems = array_map(function ($item) use ($normalizeUnit) {
    $item['resolved_unit'] = $normalizeUnit($item);
    return $item;
}, $poItems);

$defaultContractStart = !empty($po['order_date']) ? $po['order_date'] : date('Y-m-d');
$docNumPreview = 'AUTO';

// Get registered count per position
$registeredCounts = [];
$registeredByPosition = $db->prepare("
    SELECT position, COUNT(*) as cnt
    FROM po_manpower
    WHERE po_id = ? AND status <> 'Cancelled'
    GROUP BY position
");
$registeredByPosition->execute([$poId]);
foreach ($registeredByPosition->fetchAll() as $row) {
    $registeredCounts[$row['position']] = (int) $row['cnt'];
}

$existingManpowerSql = "
    SELECT pm.*, p.full_name, p.code as people_code, p.id_card, p.phone
    FROM po_manpower pm
    JOIN people p ON pm.people_id = p.id
    WHERE pm.po_id = ? AND pm.status <> 'Cancelled'
";
$stmt = $db->prepare($existingManpowerSql . " ORDER BY pm.position, FIELD(pm.status, 'Draft', 'Active', 'Ended'), pm.created_at DESC");
$stmt->execute([$poId]);
$existingManpower = $stmt->fetchAll();

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
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM po_manpower WHERE po_id = ? AND position = ? AND status <> 'Cancelled'");
    $stmt->execute([$poId, $position]);
    $registered = (int) $stmt->fetch()['cnt'];
    
    return ['required' => $required, 'registered' => $registered, 'remaining' => $required - $registered];
}

function updateManpowerPoStatus(PDO $db, AuditLog $audit, int $poId): void {
    $stmt = $db->prepare("SELECT status FROM purchase_orders WHERE id = ?");
    $stmt->execute([$poId]);
    $current = (string) ($stmt->fetchColumn() ?: '');
    if (!in_array($current, ['Approved', 'Partially Received', 'Received'], true)) {
        return;
    }

    $stmt = $db->prepare("SELECT COALESCE(SUM(qty), 0) FROM po_items WHERE po_id = ?");
    $stmt->execute([$poId]);
    $required = (int) $stmt->fetchColumn();
    if ($required <= 0) {
        return;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM po_manpower WHERE po_id = ? AND status <> 'Cancelled'");
    $stmt->execute([$poId]);
    $confirmed = (int) $stmt->fetchColumn();

    if ($confirmed <= 0) {
        $newStatus = 'Approved';
    } elseif ($confirmed < $required) {
        $newStatus = 'Partially Received';
    } else {
        $newStatus = 'Received';
    }

    if ($newStatus !== $current) {
        $db->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?")->execute([$newStatus, $poId]);
        $audit->log('update', 'PO', $poId, ['status' => $current], ['status' => $newStatus], 'Manpower registration');
    }
}

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("manpower.php?po_id=$poId");
    }
    
    $formAction = post('form_action');
    
    if ($formAction === 'create_new') {
        $rows = post('rows', []);
        if (!is_array($rows)) {
            $rows = [];
        }
        $created = 0;
        try {
            $db->beginTransaction();
            $docNum = new DocumentNumber();
            foreach ($rows as $idx => $row) {
                $fullName = trim((string) ($row['full_name'] ?? ''));
                $idCard = trim((string) ($row['id_card'] ?? ''));
                $pos = trim((string) ($row['position'] ?? ''));
                $dailyRate = (float) ($row['daily_rate'] ?? 0);
                $contractStart = trim((string) ($row['contract_start'] ?? ''));
                if ($contractStart === '') {
                    $contractStart = $defaultContractStart;
                }
                $contractEnd = trim((string) ($row['contract_end'] ?? ''));
                if ($contractEnd === '') {
                    $contractEnd = null;
                }
                $workDays = $row['work_days_per_month'] ?? '';
                $workDays = $workDays === '' ? null : (int) $workDays;

                $hasAny = $fullName !== '' || $idCard !== '' || $pos !== '' || $dailyRate > 0 || $workDays !== null;
                if (!$hasAny) {
                    continue;
                }

                if ($fullName === '' || $idCard === '' || $pos === '' || $workDays === null) {
                    throw new Exception('กรุณากรอกให้ครบทุกช่องในแถวที่ ' . ((int) $idx + 1));
                }

                $quota = getPositionQuota($db, $poId, $pos);
                if ($quota['remaining'] <= 0) {
                    throw new Exception("ตำแหน่ง \"$pos\" ลงทะเบียนครบแล้ว");
                }

                $code = $docNum->generate('EXT');
                $stmt = $db->prepare("
                    INSERT INTO people (code, full_name, people_type, position, id_card, daily_rate, supplier_id, hire_date, created_by)
                    VALUES (?, ?, 'External', ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $code,
                    $fullName,
                    $pos,
                    $idCard,
                    $dailyRate,
                    $po['supplier_id'],
                    $contractStart ?: date('Y-m-d'),
                    $_SESSION['user_id']
                ]);

                $peopleId = $db->lastInsertId();

                $stmt = $db->prepare("
                    INSERT INTO po_manpower (po_id, people_id, position, daily_rate, contract_start, contract_end, work_days_per_month, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Draft')
                ");
                $stmt->execute([
                    $poId,
                    $peopleId,
                    $pos,
                    $dailyRate,
                    $contractStart ?: null,
                    $contractEnd ?: null,
                    $workDays
                ]);

                $audit->log('create_manpower', 'PO', $poId, null, ['people_id' => $peopleId, 'name' => $fullName]);
                $created++;
            }

            if ($created === 0) {
                throw new Exception('กรุณากรอกอย่างน้อย 1 รายการ');
            }

            $db->commit();
            setFlash('success', "บันทึกร่างสำเร็จ {$created} รายการ");
            updateManpowerPoStatus($db, $audit, $poId);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
        
    } elseif ($formAction === 'confirm') {
        $pmId = (int) post('pm_id');
        $stmt = $db->prepare("SELECT people_id, contract_start, status FROM po_manpower WHERE id = ? AND po_id = ?");
        $stmt->execute([$pmId, $poId]);
        $row = $stmt->fetch();
        if ($row && $row['status'] === 'Draft') {
            $db->prepare("UPDATE po_manpower SET status = 'Active' WHERE id = ?")->execute([$pmId]);
            $peopleId = (int) $row['people_id'];
            $minStmt = $db->prepare("
                SELECT MIN(contract_start)
                FROM po_manpower
                WHERE people_id = ? AND status IN ('Active','Ended') AND contract_start IS NOT NULL
            ");
            $minStmt->execute([$peopleId]);
            $minDate = $minStmt->fetchColumn();
            if ($minDate) {
                $db->prepare("UPDATE people SET hire_date = ? WHERE id = ? AND (hire_date IS NULL OR hire_date > ?)")
                    ->execute([$minDate, $peopleId, $minDate]);
            }
            $audit->log('confirm_manpower', 'PO', $poId, null, ['pm_id' => $pmId, 'people_id' => $peopleId]);
            updateManpowerPoStatus($db, $audit, $poId);
            setFlash('success', 'ยืนยันเรียบร้อย');
        }
    } elseif ($formAction === 'remove') {
        $pmId = (int) post('pm_id');
        $db->prepare("UPDATE po_manpower SET status = 'Cancelled' WHERE id = ? AND po_id = ?")->execute([$pmId, $poId]);
        $audit->log('cancel_manpower', 'PO', $poId, null, ['pm_id' => $pmId]);
        updateManpowerPoStatus($db, $audit, $poId);
        setFlash('success', 'ยกเลิกเรียบร้อย');
    }
    
    redirect("manpower.php?po_id=$poId");
}

$pageTitle = 'ลงทะเบียนแรงงาน - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<style>
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
            <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">
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
    <div class="col-12">
        <!-- Registration Area -->
        <?php 
        $remainingTotal = max(0, $totalRequired - $totalRegistered);
        $existingByPosition = [];
        foreach ($existingManpower as $mp) {
            $pos = $mp['position'] ?? '';
            if ($pos === '') {
                continue;
            }
            if (!isset($existingByPosition[$pos])) {
                $existingByPosition[$pos] = [];
            }
            $existingByPosition[$pos][] = $mp;
        }
        ?>
        <!-- Registration Table -->
        <div class="card mb-4">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-people me-2"></i>รายชื่อแรงงาน (รวม)</span>
                <small class="text-muted">บันทึก = Draft, กดยืนยัน = เพิ่มพนักงาน</small>
            </div>
            <form id="createNewForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="form_action" value="create_new">
            </form>
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-nowrap">Preferred ID</th>
                            <th>ชื่อ-นามสกุล</th>
                            <th>บัตร ปชช.</th>
                            <th>ตำแหน่ง</th>
                            <th class="text-nowrap">เริ่มสัญญา</th>
                            <th class="text-nowrap">ค่าแรง/หน่วย</th>
                            <th class="text-nowrap">ทำงาน/เดือน (วัน)</th>
                            <th>สถานะ</th>
                            <th width="120"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rowIndex = 0; ?>
                        <?php foreach ($poItems as $item): ?>
                            <?php
                                $posName = $item['description'];
                                $required = (int) $item['qty'];
                                $registered = $registeredCounts[$posName] ?? 0;
                                $remaining = max(0, $required - $registered);
                                $unitLabel = ($item['resolved_unit'] ?? 'Day') === 'Month' ? 'เดือน' : 'วัน';
                                $existingRows = $existingByPosition[$posName] ?? [];
                            ?>
                            <?php foreach ($existingRows as $mp): ?>
                            <tr>
                                <td class="text-nowrap"><span class="badge bg-light text-dark border"><?= e($mp['people_code'] ?? '-') ?></span></td>
                                <td><?= e($mp['full_name']) ?></td>
                                <td><?= e($mp['id_card'] ?: '-') ?></td>
                                <td><?= e($posName) ?></td>
                                <td><?= $mp['contract_start'] ? formatDate($mp['contract_start']) : '-' ?></td>
                                <td><?= formatNumber($mp['daily_rate']) ?> / <?= $unitLabel ?></td>
                                <td class="text-center"><?= isset($mp['work_days_per_month']) ? (int) $mp['work_days_per_month'] : '-' ?></td>
                                <td>
                                    <?php
                                        $status = $mp['status'] ?? 'Active';
                                        $statusBadge = match ($status) {
                                            'Draft' => 'warning text-dark',
                                            'Active' => 'success',
                                            'Ended' => 'secondary',
                                            'Cancelled' => 'dark',
                                            default => 'secondary'
                                        };
                                        $statusLabel = match ($status) {
                                            'Draft' => 'Draft',
                                            'Active' => 'Active',
                                            'Ended' => 'Ended',
                                            'Cancelled' => 'Cancelled',
                                            default => $status
                                        };
                                    ?>
                                    <span class="badge bg-<?= $statusBadge ?>"><?= $statusLabel ?></span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <?php if (($mp['status'] ?? '') === 'Draft'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="form_action" value="confirm">
                                            <input type="hidden" name="pm_id" value="<?= $mp['id'] ?>">
                                            <button type="submit" class="btn btn-outline-success" onclick="return confirm('ยืนยันเพิ่มพนักงาน?')">
                                                <i class="bi bi-check2-circle"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="form_action" value="remove">
                                            <input type="hidden" name="pm_id" value="<?= $mp['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger" onclick="return confirm('ยกเลิก <?= e($mp['full_name']) ?> ?')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <?php for ($i = 0; $i < $remaining; $i++): ?>
                            <tr>
                                <td class="text-nowrap">
                                    <span class="badge bg-light text-dark border"><?= e($docNumPreview) ?></span>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm" form="createNewForm" name="rows[<?= $rowIndex ?>][full_name]" placeholder="ชื่อ นามสกุล">
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm" form="createNewForm" name="rows[<?= $rowIndex ?>][id_card]" maxlength="13" placeholder="13 หลัก">
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark"><?= e($posName) ?></span>
                                    <input type="hidden" form="createNewForm" name="rows[<?= $rowIndex ?>][position]" value="<?= e($posName) ?>">
                                </td>
                                <td>
                                    <input type="date" class="form-control form-control-sm" form="createNewForm" name="rows[<?= $rowIndex ?>][contract_start]" value="<?= e($defaultContractStart) ?>">
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control" form="createNewForm" name="rows[<?= $rowIndex ?>][daily_rate]" step="0.01" value="<?= e($item['unit_price']) ?>">
                                        <span class="input-group-text"><?= $unitLabel ?></span>
                                    </div>
                                </td>
                                <td>
                                    <input type="number" class="form-control form-control-sm" form="createNewForm" name="rows[<?= $rowIndex ?>][work_days_per_month]" min="0" max="31" value="22">
                                </td>
                                <td><span class="badge bg-secondary">New</span></td>
                                <td class="text-end">
                                    <?php if ($rowIndex === 0): ?>
                                    <button type="submit" class="btn btn-success btn-sm" form="createNewForm">
                                        <i class="bi bi-save me-1"></i>บันทึกร่าง
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php $rowIndex++; ?>
                            <?php endfor; ?>
                        <?php endforeach; ?>

                        <?php if (empty($existingManpower) && $remainingTotal <= 0): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-3">ยังไม่มีรายการ</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- External add removed per requirement -->
        
    </div>
</div>

<script>
// No dynamic position selection needed (position fixed by PO order)
</script>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
