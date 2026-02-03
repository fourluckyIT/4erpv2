<?php
/**
 * View Person Details
 * 4ERP - HR Module
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$id = (int) get('id');

if (!$id) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get person
$stmt = $db->prepare("
    SELECT p.*, s.name as supplier_name, s.code as supplier_code
    FROM people p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$person = $stmt->fetch();

if (!$person) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get salary history
$salaryHistory = $db->prepare("
    SELECT sh.*, u.full_name as created_by_name
    FROM people_salary_history sh
    LEFT JOIN users u ON sh.created_by = u.id
    WHERE sh.people_id = ?
    ORDER BY sh.effective_date DESC, sh.created_at DESC
");
$salaryHistory->execute([$id]);
$salaryHistory = $salaryHistory->fetchAll();

// Get PO assignments
$poAssignments = $db->prepare("
    SELECT pm.*, po.po_number, po.status as po_status, s.name as supplier_name
    FROM po_manpower pm
    JOIN purchase_orders po ON pm.po_id = po.id
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE pm.people_id = ?
    ORDER BY pm.created_at DESC
");
$poAssignments->execute([$id]);
$poAssignments = $poAssignments->fetchAll();

// Get certificates (if table exists)
$certificates = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM people_certificates
        WHERE people_id = ?
        ORDER BY expiry_date ASC
    ");
    $stmt->execute([$id]);
    $certificates = $stmt->fetchAll();
} catch (PDOException $e) {
    // Table may not exist yet
}

$pageTitle = e($person['full_name']) . ' - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-person me-2"></i><?= e($person['full_name']) ?>
                <span class="badge bg-<?= $person['people_type'] === 'Employee' ? 'success' : 'warning text-dark' ?> ms-2">
                    <?= $person['people_type'] === 'Employee' ? 'พนักงานประจำ' : 'แรงงานภายนอก' ?>
                </span>
                <span class="badge bg-<?= $person['is_active'] ? 'success' : 'secondary' ?>">
                    <?= $person['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="index.php">People</a></li>
                    <li class="breadcrumb-item active"><?= e($person['code']) ?></li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="edit.php?id=<?= $id ?>" class="btn btn-primary">
                <i class="bi bi-pencil me-1"></i>แก้ไข
            </a>
            <a href="javascript:history.back()" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
        </div>
    </div>
</div>

<div class="row">
    <!-- Basic Info -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-person-badge me-2"></i>ข้อมูลส่วนตัว
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="140">รหัส</th>
                        <td><code><?= e($person['code']) ?></code></td>
                    </tr>
                    <tr>
                        <th>ชื่อ-นามสกุล</th>
                        <td><strong><?= e($person['full_name']) ?></strong></td>
                    </tr>
                    <tr>
                        <th>บัตรประชาชน</th>
                        <td><?= e($person['id_card'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <th>ตำแหน่ง</th>
                        <td><?= e($person['position'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <th>โทรศัพท์</th>
                        <td><?= e($person['phone'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <th>อีเมล</th>
                        <td><?= e($person['email'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <th>วันที่เริ่มงาน</th>
                        <td><?= $person['hire_date'] ? formatDate($person['hire_date']) : '-' ?></td>
                    </tr>
                    <?php if ($person['people_type'] === 'External'): ?>
                    <tr>
                        <th>ผู้ขาย/บริษัท</th>
                        <td>
                            <?php if (!empty($person['supplier_name'])): ?>
                            <?= e($person['supplier_code']) ?> - <?= e($person['supplier_name']) ?>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Current Salary -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cash-stack me-2"></i>เงินเดือน/ค่าแรงปัจจุบัน</span>
                <a href="../salary/edit.php?people_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-plus"></i> เพิ่ม
                </a>
            </div>
            <div class="card-body">
                <?php 
                $currentSalary = null;
                foreach ($salaryHistory as $sh) {
                    if ($sh['is_active']) {
                        $currentSalary = $sh;
                        break;
                    }
                }
                ?>
                <?php if ($currentSalary): ?>
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="140">ประเภท</th>
                        <td>
                            <span class="badge bg-<?= match($currentSalary['salary_type']) {
                                'Monthly' => 'primary',
                                'Daily' => 'info',
                                'Hourly' => 'secondary',
                                default => 'light text-dark'
                            } ?>">
                                <?= match($currentSalary['salary_type']) {
                                    'Monthly' => 'รายเดือน',
                                    'Daily' => 'รายวัน',
                                    'Hourly' => 'รายชั่วโมง',
                                    default => $currentSalary['salary_type']
                                } ?>
                            </span>
                        </td>
                    </tr>
                    <?php if ($currentSalary['salary_type'] === 'Monthly'): ?>
                    <tr>
                        <th>เงินเดือน</th>
                        <td><strong><?= formatNumber($currentSalary['base_salary']) ?></strong> บาท/เดือน</td>
                    </tr>
                    <?php elseif ($currentSalary['salary_type'] === 'Daily'): ?>
                    <tr>
                        <th>ค่าแรง/วัน</th>
                        <td><strong><?= formatNumber($currentSalary['daily_rate']) ?></strong> บาท/วัน</td>
                    </tr>
                    <?php elseif ($currentSalary['salary_type'] === 'Hourly'): ?>
                    <tr>
                        <th>ค่าแรง/ชม.</th>
                        <td><strong><?= formatNumber($currentSalary['hourly_rate']) ?></strong> บาท/ชม.</td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>OT Multiplier</th>
                        <td><?= $currentSalary['ot_rate_multiplier'] ?>x / <?= $currentSalary['holiday_rate_multiplier'] ?>x (วันหยุด)</td>
                    </tr>
                    <tr>
                        <th>มีผลตั้งแต่</th>
                        <td><?= formatDate($currentSalary['effective_date']) ?></td>
                    </tr>
                </table>
                
                <?php 
                $totalAllowance = ($currentSalary['position_allowance'] ?? 0) + ($currentSalary['transport_allowance'] ?? 0) + 
                                  ($currentSalary['meal_allowance'] ?? 0) + ($currentSalary['housing_allowance'] ?? 0) + 
                                  ($currentSalary['other_allowance'] ?? 0);
                if ($totalAllowance > 0): 
                ?>
                <hr class="my-2">
                <h6 class="text-muted mb-2"><i class="bi bi-gift me-1"></i>เบี้ยเลี้ยง</h6>
                <table class="table table-sm table-borderless mb-0">
                    <?php if ($currentSalary['position_allowance'] > 0): ?>
                    <tr><td>ค่าตำแหน่ง</td><td class="text-end"><?= formatNumber($currentSalary['position_allowance']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($currentSalary['transport_allowance'] > 0): ?>
                    <tr><td>ค่าเดินทาง</td><td class="text-end"><?= formatNumber($currentSalary['transport_allowance']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($currentSalary['meal_allowance'] > 0): ?>
                    <tr><td>ค่าอาหาร</td><td class="text-end"><?= formatNumber($currentSalary['meal_allowance']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($currentSalary['housing_allowance'] > 0): ?>
                    <tr><td>ค่าที่พัก</td><td class="text-end"><?= formatNumber($currentSalary['housing_allowance']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($currentSalary['other_allowance'] > 0): ?>
                    <tr><td>อื่นๆ <?= $currentSalary['allowance_notes'] ? '('.e($currentSalary['allowance_notes']).')' : '' ?></td><td class="text-end"><?= formatNumber($currentSalary['other_allowance']) ?></td></tr>
                    <?php endif; ?>
                    <tr class="border-top"><td><strong>รวมเบี้ยเลี้ยง</strong></td><td class="text-end"><strong><?= formatNumber($totalAllowance) ?></strong></td></tr>
                </table>
                <?php endif; ?>
                
                <?php if (!empty($currentSalary['bank_name']) || !empty($currentSalary['bank_account'])): ?>
                <hr class="my-2">
                <h6 class="text-muted mb-2"><i class="bi bi-bank me-1"></i>บัญชีธนาคาร</h6>
                <table class="table table-sm table-borderless mb-0">
                    <?php if ($currentSalary['bank_name']): ?>
                    <tr><td>ธนาคาร</td><td><?= e($currentSalary['bank_name']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($currentSalary['bank_account']): ?>
                    <tr><td>เลขบัญชี</td><td><code><?= e($currentSalary['bank_account']) ?></code></td></tr>
                    <?php endif; ?>
                    <?php if ($currentSalary['bank_branch']): ?>
                    <tr><td>สาขา</td><td><?= e($currentSalary['bank_branch']) ?></td></tr>
                    <?php endif; ?>
                </table>
                <?php endif; ?>
                
                <?php else: ?>
                <p class="text-muted text-center py-4">ยังไม่มีข้อมูลเงินเดือน</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- PO Assignments -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-briefcase me-2"></i>ประวัติการจ้างงาน (PO)
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>PO</th>
                    <th>ผู้ขาย</th>
                    <th>ตำแหน่ง</th>
                    <th class="text-end">ค่าแรง/วัน</th>
                    <th>สัญญา</th>
                    <th>สถานะ PO</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($poAssignments)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">ไม่มีประวัติ</td></tr>
                <?php else: ?>
                <?php foreach ($poAssignments as $po): ?>
                <tr>
                    <td>
                        <a href="<?= BASE_URL ?>/modules/procurement/po/view.php?id=<?= $po['po_id'] ?>">
                            <?= e($po['po_number']) ?>
                        </a>
                    </td>
                    <td><?= e($po['supplier_name']) ?></td>
                    <td><?= e($po['position']) ?></td>
                    <td class="text-end"><?= formatNumber($po['daily_rate']) ?></td>
                    <td>
                        <?= $po['contract_start'] ? formatDate($po['contract_start']) : '-' ?>
                        <?= $po['contract_end'] ? ' - ' . formatDate($po['contract_end']) : '' ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= match($po['po_status']) {
                            'Approved' => 'success',
                            'Received' => 'primary',
                            'Cancelled' => 'danger',
                            default => 'secondary'
                        } ?>"><?= e($po['po_status']) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Salary History -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-clock-history me-2"></i>ประวัติเงินเดือน
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>มีผลตั้งแต่</th>
                    <th>ประเภท</th>
                    <th class="text-end">เงินเดือน/ค่าแรง</th>
                    <th>เหตุผล</th>
                    <th>สถานะ</th>
                    <th>บันทึกโดย</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($salaryHistory)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">ไม่มีประวัติ</td></tr>
                <?php else: ?>
                <?php foreach ($salaryHistory as $sh): ?>
                <tr class="<?= $sh['is_active'] ? 'table-success' : '' ?>">
                    <td><?= formatDate($sh['effective_date']) ?></td>
                    <td>
                        <?= match($sh['salary_type']) {
                            'Monthly' => 'รายเดือน',
                            'Daily' => 'รายวัน',
                            'Hourly' => 'รายชั่วโมง',
                            default => $sh['salary_type']
                        } ?>
                    </td>
                    <td class="text-end">
                        <?php if ($sh['salary_type'] === 'Monthly'): ?>
                        <?= formatNumber($sh['base_salary']) ?> /เดือน
                        <?php elseif ($sh['salary_type'] === 'Daily'): ?>
                        <?= formatNumber($sh['daily_rate']) ?> /วัน
                        <?php else: ?>
                        <?= formatNumber($sh['hourly_rate']) ?> /ชม.
                        <?php endif; ?>
                    </td>
                    <td><?= e($sh['change_reason'] ?: '-') ?></td>
                    <td>
                        <?php if (!empty($sh['is_active'])): ?>
                        <span class="badge bg-success">ใช้งานอยู่</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">ไม่ใช้งาน</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <small><?= e($sh['created_by_name'] ?: '-') ?></small>
                        <br><small class="text-muted"><?= formatDateTime($sh['created_at']) ?></small>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Certificates -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-award me-2"></i>ใบรับรอง/ใบอนุญาต</span>
        <a href="cert_add.php?people_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-plus"></i> เพิ่ม
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>ใบรับรอง</th>
                    <th>เลขที่</th>
                    <th>วันที่ออก</th>
                    <th>วันหมดอายุ</th>
                    <th>สถานะ</th>
                    <th width="80"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($certificates)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">ยังไม่มีใบรับรอง</td></tr>
                <?php else: ?>
                <?php foreach ($certificates as $cert): 
                    $isExpired = $cert['expiry_date'] && strtotime($cert['expiry_date']) < time();
                    $isExpiringSoon = $cert['expiry_date'] && strtotime($cert['expiry_date']) < strtotime('+30 days');
                ?>
                <tr class="<?= $isExpired ? 'table-danger' : ($isExpiringSoon ? 'table-warning' : '') ?>">
                    <td><?= e($cert['certificate_type']) ?></td>
                    <td><code><?= e($cert['certificate_number'] ?: '-') ?></code></td>
                    <td><?= $cert['issue_date'] ? formatDate($cert['issue_date']) : '-' ?></td>
                    <td><?= $cert['expiry_date'] ? formatDate($cert['expiry_date']) : '-' ?></td>
                    <td>
                        <?php if ($isExpired): ?>
                        <span class="badge bg-danger">หมดอายุ</span>
                        <?php elseif ($isExpiringSoon): ?>
                        <span class="badge bg-warning text-dark">ใกล้หมดอายุ</span>
                        <?php elseif ($cert['expiry_date']): ?>
                        <span class="badge bg-success">ใช้งานได้</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">ไม่มีวันหมดอายุ</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="javascript:history.back()" class="btn btn-sm btn-outline-secondary" title="แก้ไข">
                            <i class="bi bi-pencil"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
