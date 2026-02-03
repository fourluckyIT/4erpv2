<?php
/**
 * Salary History
 * 4ERP - HR Module
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$peopleId = (int) get('people_id');

if (!$peopleId) {
    setFlash('error', 'ต้องระบุบุคลากร');
    redirect('index.php');
}

// Get person
$stmt = $db->prepare("SELECT * FROM people WHERE id = ?");
$stmt->execute([$peopleId]);
$person = $stmt->fetch();

if (!$person) {
    setFlash('error', 'ไม่พบบุคลากร');
    redirect('index.php');
}

// Get salary history
$stmt = $db->prepare("
    SELECT sh.*, u.full_name as created_by_name
    FROM people_salary_history sh
    LEFT JOIN users u ON sh.created_by = u.id
    WHERE sh.people_id = ?
    ORDER BY sh.effective_date DESC, sh.created_at DESC
");
$stmt->execute([$peopleId]);
$history = $stmt->fetchAll();

$pageTitle = 'ประวัติเงินเดือน - ' . e($person['full_name']);
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-clock-history me-2"></i>ประวัติเงินเดือน</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../people/">People</a></li>
                    <li class="breadcrumb-item"><a href="../people/view.php?id=<?= $peopleId ?>"><?= e($person['full_name']) ?></a></li>
                    <li class="breadcrumb-item active">Salary History</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="edit.php?people_id=<?= $peopleId ?>" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>เพิ่มเงินเดือน
            </a>
            <a href="javascript:history.back()" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ
            </a>
        </div>
    </div>
</div>

<div class="alert alert-info">
    <i class="bi bi-person me-2"></i>
    <strong><?= e($person['code']) ?> - <?= e($person['full_name']) ?></strong>
    (<?= $person['people_type'] === 'Employee' ? 'พนักงานประจำ' : 'แรงงานภายนอก' ?>)
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>มีผลตั้งแต่</th>
                    <th>ประเภท</th>
                    <th class="text-end">เงินเดือน/ค่าแรง</th>
                    <th class="text-end">เบี้ยเลี้ยงรวม</th>
                    <th>OT Rate</th>
                    <th>เหตุผล</th>
                    <th>สถานะ</th>
                    <th>บันทึกโดย</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">ไม่มีประวัติ</td></tr>
                <?php else: ?>
                <?php foreach ($history as $h): 
                    $totalAllowance = $h['position_allowance'] + $h['transport_allowance'] + 
                                      $h['meal_allowance'] + $h['housing_allowance'] + $h['other_allowance'];
                ?>
                <tr class="<?= $h['is_active'] ? 'table-success' : '' ?>">
                    <td><?= formatDate($h['effective_date']) ?></td>
                    <td>
                        <span class="badge bg-<?= match($h['salary_type']) {
                            'Monthly' => 'primary',
                            'Daily' => 'info',
                            'Hourly' => 'secondary',
                            default => 'light text-dark'
                        } ?>">
                            <?= match($h['salary_type']) {
                                'Monthly' => 'รายเดือน',
                                'Daily' => 'รายวัน',
                                'Hourly' => 'รายชั่วโมง',
                                default => $h['salary_type']
                            } ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <?php if ($h['salary_type'] === 'Monthly'): ?>
                        <strong><?= formatNumber($h['base_salary']) ?></strong> /เดือน
                        <?php elseif ($h['salary_type'] === 'Daily'): ?>
                        <strong><?= formatNumber($h['daily_rate']) ?></strong> /วัน
                        <?php else: ?>
                        <strong><?= formatNumber($h['hourly_rate']) ?></strong> /ชม.
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= formatNumber($totalAllowance) ?></td>
                    <td><?= $h['ot_rate_multiplier'] ?>x / <?= $h['holiday_rate_multiplier'] ?>x</td>
                    <td><?= e($h['change_reason'] ?: '-') ?></td>
                    <td>
                        <?php if ($h['is_active']): ?>
                        <span class="badge bg-success">ใช้งานอยู่</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">ไม่ใช้งาน</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <small><?= e($h['created_by_name'] ?: '-') ?></small>
                        <br><small class="text-muted"><?= formatDateTime($h['created_at']) ?></small>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
