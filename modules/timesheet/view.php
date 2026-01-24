<?php
/**
 * View Timesheet
 * ERP v2 - M6: Timesheet Module
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
if (!$rbac->can('view', 'TIMESHEET')) {
    redirect('/4erpv2/', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้', 'danger');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    redirect('/4erpv2/modules/timesheet/', 'ไม่พบ Timesheet', 'danger');
}

$timesheetModel = new Timesheet();
$ts = $timesheetModel->getById($id);

if (!$ts) {
    redirect('/4erpv2/modules/timesheet/', 'ไม่พบ Timesheet', 'danger');
}

$entries = $timesheetModel->getEntries($id);
$statusHistory = $timesheetModel->getStatusHistory($id);
$corrections = $timesheetModel->getCorrections($id);
$anomalies = $timesheetModel->detectAnomalies($id);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'confirm' && $rbac->can('approve', 'TIMESHEET')) {
        $result = $timesheetModel->confirm($id);
        if ($result['success']) {
            $msg = 'Confirm Timesheet สำเร็จ - ส่งไป HR แล้ว';
            if (!empty($result['anomalies'])) {
                $msg .= ' (พบความผิดปกติ ' . count($result['anomalies']) . ' รายการ)';
            }
            redirect('/4erpv2/modules/timesheet/view.php?id=' . $id, $msg, 'success');
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'payroll_ready' && $rbac->can('approve', 'TIMESHEET')) {
        $result = $timesheetModel->markPayrollReady($id);
        if ($result['success']) {
            redirect('/4erpv2/modules/timesheet/view.php?id=' . $id, 'ทำเครื่องหมาย Payroll Ready สำเร็จ', 'success');
        } else {
            $error = $result['error'];
        }
    }
    
    if ($action === 'return' && $rbac->can('approve', 'TIMESHEET')) {
        $reason = trim($_POST['return_reason'] ?? '');
        if (empty($reason)) {
            $error = 'กรุณาระบุเหตุผลในการคืน';
        } else {
            $result = $timesheetModel->returnForCorrection($id, $reason);
            if ($result['success']) {
                redirect('/4erpv2/modules/timesheet/view.php?id=' . $id, 'ส่งคืนเพื่อแก้ไขสำเร็จ', 'warning');
            } else {
                $error = $result['error'];
            }
        }
    }
    
    // Refresh data after action
    $ts = $timesheetModel->getById($id);
    $entries = $timesheetModel->getEntries($id);
    $statusHistory = $timesheetModel->getStatusHistory($id);
}

$pageTitle = 'Timesheet ' . $ts['ts_number'] . ' - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-8">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/4erpv2/">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="index.php">Timesheet</a></li>
                <li class="breadcrumb-item active"><?= e($ts['ts_number']) ?></li>
            </ol>
        </nav>
        <h2 class="mb-0">
            <i class="bi bi-clock-history me-2"></i><?= e($ts['ts_number']) ?>
            <?php
            $statusClass = match($ts['status']) {
                'Draft' => 'secondary',
                'Confirmed' => 'info',
                'Submitted' => 'primary',
                'PayrollReady' => 'success',
                'Returned' => 'warning',
                'Voided' => 'danger',
                default => 'secondary'
            };
            ?>
            <span class="badge bg-<?= $statusClass ?>"><?= e($ts['status']) ?></span>
        </h2>
    </div>
    <div class="col-md-4 text-end">
        <?php if ($ts['status'] === 'Draft'): ?>
            <?php if ($rbac->can('edit', 'TIMESHEET')): ?>
            <a href="edit.php?id=<?= $id ?>" class="btn btn-outline-secondary">
                <i class="bi bi-pencil me-1"></i>แก้ไข
            </a>
            <?php endif; ?>
            <?php if ($rbac->can('approve', 'TIMESHEET')): ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยัน Timesheet? หลังจากนี้จะไม่สามารถแก้ไขได้โดยตรง')">
                <input type="hidden" name="action" value="confirm">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle me-1"></i>Confirm & ส่ง HR
                </button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($ts['status'] === 'Submitted' && ($auth->hasRole('HR') || $auth->isAdmin())): ?>
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="payroll_ready">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check2-all me-1"></i>Payroll Ready
                </button>
            </form>
            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#returnModal">
                <i class="bi bi-arrow-return-left me-1"></i>คืนเพื่อแก้ไข
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if (!empty($anomalies) && $ts['status'] === 'Draft'): ?>
<div class="alert alert-warning">
    <strong><i class="bi bi-exclamation-triangle me-1"></i>พบความผิดปกติ:</strong>
    <ul class="mb-0 mt-2">
        <?php foreach ($anomalies as $a): ?>
        <li><?= e($a) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($ts['status'] === 'Returned'): ?>
<div class="alert alert-warning">
    <strong><i class="bi bi-arrow-return-left me-1"></i>ถูกส่งคืนเพื่อแก้ไข:</strong>
    <?= e($ts['return_reason']) ?>
    <br><small class="text-muted">โดย: <?= formatDateTime($ts['returned_at']) ?></small>
</div>
<?php endif; ?>

<!-- Header Info -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-info-circle me-1"></i>ข้อมูล Timesheet
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <th width="35%">วันที่ทำงาน:</th>
                        <td><strong><?= formatDate($ts['work_date']) ?></strong></td>
                    </tr>
                    <tr>
                        <th>Job:</th>
                        <td>
                            <a href="/4erpv2/modules/jobs/view.php?id=<?= $ts['job_id'] ?>">
                                <?= e($ts['job_number']) ?>
                            </a>
                            <br><small class="text-muted"><?= e($ts['job_scope']) ?></small>
                        </td>
                    </tr>
                    <tr>
                        <th>ลูกค้า:</th>
                        <td><?= e($ts['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <th>Site:</th>
                        <td><?= e($ts['site_name'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>หมายเหตุ:</th>
                        <td><?= e($ts['notes'] ?? '-') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-graph-up me-1"></i>สรุป
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-4">
                        <div class="display-6"><?= $ts['total_workers'] ?></div>
                        <div class="text-muted">จำนวนคน</div>
                    </div>
                    <div class="col-4">
                        <div class="display-6"><?= number_format($ts['total_hours'], 1) ?></div>
                        <div class="text-muted">ชั่วโมงทำงาน</div>
                    </div>
                    <div class="col-4">
                        <div class="display-6 <?= $ts['total_ot_hours'] > 0 ? 'text-warning' : '' ?>">
                            <?= number_format($ts['total_ot_hours'], 1) ?>
                        </div>
                        <div class="text-muted">ชั่วโมง OT</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Entries Table -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people me-1"></i>รายชื่อพนักงาน</span>
        <span class="badge bg-secondary"><?= count($entries) ?> คน</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>รหัส</th>
                        <th>ชื่อ</th>
                        <th>ตำแหน่ง</th>
                        <th class="text-center">สถานะ</th>
                        <th class="text-center">เข้า</th>
                        <th class="text-center">ออก</th>
                        <th class="text-center">ชั่วโมง</th>
                        <th class="text-center">OT</th>
                        <th>หมายเหตุ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4 text-muted">
                            ไม่มีรายการ
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($entries as $i => $entry): ?>
                    <tr class="<?= !$entry['is_present'] ? 'table-secondary' : '' ?>">
                        <td><?= $i + 1 ?></td>
                        <td><?= e($entry['people_code']) ?></td>
                        <td>
                            <?= e($entry['full_name']) ?>
                            <?php if ($entry['manually_added']): ?>
                            <span class="badge bg-warning text-dark" title="เพิ่มด้วยมือ: <?= e($entry['manual_add_reason']) ?>">
                                <i class="bi bi-plus-circle"></i>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($entry['position'] ?? '-') ?></td>
                        <td class="text-center">
                            <?php if ($entry['is_present']): ?>
                            <span class="badge bg-success">มา</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">ขาด</span>
                            <?php if ($entry['absence_reason']): ?>
                            <br><small><?= e($entry['absence_reason']) ?></small>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?= $entry['check_in'] ? substr($entry['check_in'], 0, 5) : '-' ?>
                            <?php if ($entry['is_late']): ?>
                            <span class="badge bg-warning text-dark" title="สาย <?= $entry['late_minutes'] ?> นาที">
                                <i class="bi bi-clock"></i>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?= $entry['check_out'] ? substr($entry['check_out'], 0, 5) : '-' ?>
                            <?php if ($entry['missing_checkout']): ?>
                            <span class="badge bg-danger" title="ไม่มี check-out">!</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= number_format($entry['work_hours'], 1) ?></td>
                        <td class="text-center">
                            <?php if ($entry['ot_hours'] > 0): ?>
                            <span class="text-warning fw-bold"><?= number_format($entry['ot_hours'], 1) ?></span>
                            <?php if ($entry['ot_reason']): ?>
                            <br><small class="text-muted"><?= e($entry['ot_reason']) ?></small>
                            <?php endif; ?>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($entry['exception_note'] ?? '') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Status History -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-clock-history me-1"></i>ประวัติสถานะ
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>วันเวลา</th>
                        <th>จาก</th>
                        <th>เป็น</th>
                        <th>โดย</th>
                        <th>หมายเหตุ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statusHistory as $h): ?>
                    <tr>
                        <td><?= formatDateTime($h['created_at']) ?></td>
                        <td><?= e($h['old_status'] ?? '-') ?></td>
                        <td><strong><?= e($h['new_status']) ?></strong></td>
                        <td><?= e($h['changed_by_name']) ?></td>
                        <td><?= e($h['reason'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Return Modal -->
<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="return">
                <div class="modal-header">
                    <h5 class="modal-title">คืน Timesheet เพื่อแก้ไข</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">เหตุผล <span class="text-danger">*</span></label>
                        <textarea name="return_reason" class="form-control" rows="3" required 
                                  placeholder="ระบุเหตุผลที่ต้องส่งคืน..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-warning">ส่งคืน</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
