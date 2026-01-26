<?php
/**
 * Edit Timesheet
 * ERP v2 - M6: Timesheet Module
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
if (!$rbac->can('edit', 'TIMESHEET')) {
    setFlash('error', 'คุณไม่มีสิทธิ์แก้ไข Timesheet');
    redirect(BASE_URL . '/modules/timesheet/');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'ไม่พบ Timesheet');
    redirect(BASE_URL . '/modules/timesheet/');
}

$timesheetModel = new Timesheet();
$ts = $timesheetModel->getById($id);

if (!$ts) {
    setFlash('error', 'ไม่พบ Timesheet');
    redirect(BASE_URL . '/modules/timesheet/');
}

if ($ts['status'] !== 'Draft') {
    setFlash('warning', 'ไม่สามารถแก้ไข Timesheet ที่ไม่ใช่ Draft ได้');
    redirect(BASE_URL . '/modules/timesheet/view.php?id=' . $id);
}

$db = getDB();
$entries = $timesheetModel->getEntries($id);
$errors = [];
$success = '';

// Get available people for adding
$existingPeopleIds = array_column($entries, 'people_id');
$availablePeople = $db->query("
    SELECT id, code, full_name, position, daily_rate 
    FROM people 
    WHERE is_active = 1
    ORDER BY full_name
")->fetchAll();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Update entries
    if ($action === 'update_entries') {
        $entryData = $_POST['entry'] ?? [];
        
        foreach ($entryData as $entryId => $data) {
            $result = $timesheetModel->updateEntry((int)$entryId, [
                'is_present' => isset($data['is_present']) ? 1 : 0,
                'absence_reason' => $data['absence_reason'] ?? null,
                'check_in' => $data['check_in'] ?: null,
                'check_out' => $data['check_out'] ?: null,
                'break_minutes' => (int)($data['break_minutes'] ?? 0),
                'work_hours' => (float)($data['work_hours'] ?? 0),
                'ot_hours' => (float)($data['ot_hours'] ?? 0),
                'ot_reason' => $data['ot_reason'] ?? null,
                'is_late' => isset($data['is_late']) ? 1 : 0,
                'late_minutes' => (int)($data['late_minutes'] ?? 0),
                'exception_note' => $data['exception_note'] ?? null,
            ]);
            
            if (!$result['success']) {
                $errors[] = "Entry #{$entryId}: " . $result['error'];
            }
        }
        
        if (empty($errors)) {
            $success = 'บันทึกข้อมูลสำเร็จ';
        }
        
        // Refresh entries
        $entries = $timesheetModel->getEntries($id);
        $ts = $timesheetModel->getById($id);
    }
    
    // Add person
    if ($action === 'add_person') {
        $peopleId = (int)($_POST['people_id'] ?? 0);
        $reason = trim($_POST['add_reason'] ?? '');
        
        if (!$peopleId) {
            $errors[] = 'กรุณาเลือกบุคคล';
        } elseif (!$reason) {
            $errors[] = 'กรุณาระบุเหตุผลในการเพิ่ม';
        } else {
            $result = $timesheetModel->addEntry($id, $peopleId, $reason);
            if ($result['success']) {
                $success = 'เพิ่มบุคคลสำเร็จ';
                $entries = $timesheetModel->getEntries($id);
                $ts = $timesheetModel->getById($id);
            } else {
                $errors[] = $result['error'];
            }
        }
    }
    
    // Remove person
    if ($action === 'remove_person') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $result = $timesheetModel->removeEntry($entryId);
        if ($result['success']) {
            $success = 'ลบบุคคลสำเร็จ';
            $entries = $timesheetModel->getEntries($id);
            $ts = $timesheetModel->getById($id);
        } else {
            $errors[] = $result['error'];
        }
    }
}

$pageTitle = 'แก้ไข Timesheet ' . $ts['ts_number'] . ' - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-8">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="index.php">Timesheet</a></li>
                <li class="breadcrumb-item"><a href="view.php?id=<?= $id ?>"><?= e($ts['ts_number']) ?></a></li>
                <li class="breadcrumb-item active">แก้ไข</li>
            </ol>
        </nav>
        <h2 class="mb-0">
            <i class="bi bi-pencil me-2"></i>แก้ไข <?= e($ts['ts_number']) ?>
        </h2>
        <p class="text-muted mb-0">
            วันที่: <?= formatDate($ts['work_date']) ?> | 
            Job: <?= e($ts['job_number']) ?> | 
            ลูกค้า: <?= e($ts['customer_name']) ?>
        </p>
    </div>
    <div class="col-md-4 text-end">
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<!-- Add Person -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-person-plus me-1"></i>เพิ่มบุคคล (ไม่อยู่ใน Plan)
    </div>
    <div class="card-body">
        <form method="POST" class="row g-3">
            <input type="hidden" name="action" value="add_person">
            <div class="col-md-5">
                <select name="people_id" class="form-select" required>
                    <option value="">-- เลือกบุคคล --</option>
                    <?php foreach ($availablePeople as $p): ?>
                    <?php if (!in_array($p['id'], $existingPeopleIds)): ?>
                    <option value="<?= $p['id'] ?>">
                        <?= e($p['code']) ?> - <?= e($p['full_name']) ?> (<?= e($p['position'] ?? 'N/A') ?>)
                    </option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <input type="text" name="add_reason" class="form-control" placeholder="เหตุผลในการเพิ่ม *" required>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-plus"></i> เพิ่ม
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Entries Form -->
<form method="POST">
    <input type="hidden" name="action" value="update_entries">
    
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-people me-1"></i>รายชื่อพนักงาน (<?= count($entries) ?> คน)</span>
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-save me-1"></i>บันทึกทั้งหมด
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="3%">#</th>
                            <th width="15%">ชื่อ</th>
                            <th width="5%" class="text-center">มา?</th>
                            <th width="10%">เหตุผลขาด</th>
                            <th width="8%">เข้า</th>
                            <th width="8%">ออก</th>
                            <th width="6%">พัก(นาที)</th>
                            <th width="7%">ชม.ทำงาน</th>
                            <th width="7%">OT ชม.</th>
                            <th width="12%">เหตุผล OT</th>
                            <th width="12%">หมายเหตุ</th>
                            <th width="5%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entries)): ?>
                        <tr>
                            <td colspan="12" class="text-center py-4 text-muted">
                                ไม่มีรายการ - เพิ่มบุคคลด้านบน
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($entries as $i => $entry): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <strong><?= e($entry['full_name']) ?></strong>
                                <?php if ($entry['manually_added']): ?>
                                <span class="badge bg-warning text-dark" title="เพิ่มด้วยมือ">+</span>
                                <?php endif; ?>
                                <br><small class="text-muted"><?= e($entry['people_code']) ?></small>
                            </td>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" 
                                       name="entry[<?= $entry['id'] ?>][is_present]" value="1"
                                       <?= $entry['is_present'] ? 'checked' : '' ?>>
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm"
                                       name="entry[<?= $entry['id'] ?>][absence_reason]"
                                       value="<?= e($entry['absence_reason'] ?? '') ?>"
                                       placeholder="ถ้าขาด...">
                            </td>
                            <td>
                                <input type="time" class="form-control form-control-sm"
                                       name="entry[<?= $entry['id'] ?>][check_in]"
                                       value="<?= e($entry['check_in'] ?? '') ?>">
                            </td>
                            <td>
                                <input type="time" class="form-control form-control-sm"
                                       name="entry[<?= $entry['id'] ?>][check_out]"
                                       value="<?= e($entry['check_out'] ?? '') ?>">
                            </td>
                            <td>
                                <input type="number" class="form-control form-control-sm"
                                       name="entry[<?= $entry['id'] ?>][break_minutes]"
                                       value="<?= (int)$entry['break_minutes'] ?>" min="0" step="15">
                            </td>
                            <td>
                                <input type="number" class="form-control form-control-sm"
                                       name="entry[<?= $entry['id'] ?>][work_hours]"
                                       value="<?= number_format($entry['work_hours'], 1) ?>" 
                                       min="0" max="24" step="0.5">
                            </td>
                            <td>
                                <input type="number" class="form-control form-control-sm"
                                       name="entry[<?= $entry['id'] ?>][ot_hours]"
                                       value="<?= number_format($entry['ot_hours'], 1) ?>" 
                                       min="0" max="12" step="0.5">
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm"
                                       name="entry[<?= $entry['id'] ?>][ot_reason]"
                                       value="<?= e($entry['ot_reason'] ?? '') ?>"
                                       placeholder="ถ้ามี OT...">
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm"
                                       name="entry[<?= $entry['id'] ?>][exception_note]"
                                       value="<?= e($entry['exception_note'] ?? '') ?>"
                                       placeholder="หมายเหตุ...">
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                        onclick="removePerson(<?= $entry['id'] ?>)" title="ลบ">
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
        <div class="card-footer">
            <div class="d-flex justify-content-between">
                <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>ยกเลิก
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>บันทึกข้อมูล
                </button>
            </div>
        </div>
    </div>
</form>

<!-- Hidden form for remove -->
<form id="removeForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="remove_person">
    <input type="hidden" name="entry_id" id="removeEntryId">
</form>

<script>
function removePerson(entryId) {
    if (confirm('ยืนยันการลบบุคคลนี้ออกจาก Timesheet?')) {
        document.getElementById('removeEntryId').value = entryId;
        document.getElementById('removeForm').submit();
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
