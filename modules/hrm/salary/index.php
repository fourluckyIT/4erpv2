<?php
/**
 * Salary Management
 * ERP v2 - HR Module
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();

// Get people with salary info
$people = $db->query("
    SELECT 
        p.id, p.code, p.full_name, p.position, p.people_type, p.is_active,
        sh.salary_type, sh.base_salary, sh.daily_rate, sh.hourly_rate,
        sh.effective_date,
        (sh.position_allowance + sh.transport_allowance + sh.meal_allowance + 
         sh.housing_allowance + sh.other_allowance) as total_allowances
    FROM people p
    LEFT JOIN people_salary_history sh ON p.id = sh.people_id AND sh.is_active = 1
    WHERE p.is_active = 1
    ORDER BY p.full_name
")->fetchAll();

$pageTitle = 'Salary Management - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Salary Management</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="../people/">HR</a></li>
                    <li class="breadcrumb-item active">Salary</li>
                </ol>
            </nav>
        </div>
    </div>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    คลิกที่ชื่อบุคลากรเพื่อดูประวัติเงินเดือนและเพิ่มรายการใหม่
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>รหัส</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>ประเภท</th>
                    <th>ตำแหน่ง</th>
                    <th>ประเภทเงินเดือน</th>
                    <th class="text-end">เงินเดือน/ค่าแรง</th>
                    <th class="text-end">เบี้ยเลี้ยง</th>
                    <th>มีผลตั้งแต่</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($people)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
                <?php else: ?>
                <?php foreach ($people as $p): ?>
                <tr>
                    <td><code><?= e($p['code']) ?></code></td>
                    <td>
                        <a href="history.php?people_id=<?= $p['id'] ?>" class="text-decoration-none">
                            <strong><?= e($p['full_name']) ?></strong>
                        </a>
                    </td>
                    <td>
                        <span class="badge bg-<?= $p['people_type'] === 'Employee' ? 'success' : 'warning text-dark' ?>">
                            <?= $p['people_type'] === 'Employee' ? 'ประจำ' : 'ภายนอก' ?>
                        </span>
                    </td>
                    <td><?= e($p['position'] ?: '-') ?></td>
                    <td>
                        <?php if ($p['salary_type']): ?>
                        <span class="badge bg-<?= match($p['salary_type']) {
                            'Monthly' => 'primary',
                            'Daily' => 'info',
                            'Hourly' => 'secondary',
                            default => 'light text-dark'
                        } ?>">
                            <?= match($p['salary_type']) {
                                'Monthly' => 'รายเดือน',
                                'Daily' => 'รายวัน',
                                'Hourly' => 'รายชั่วโมง',
                                default => $p['salary_type']
                            } ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($p['salary_type'] === 'Monthly'): ?>
                        <?= formatNumber($p['base_salary']) ?> <small class="text-muted">/เดือน</small>
                        <?php elseif ($p['salary_type'] === 'Daily'): ?>
                        <?= formatNumber($p['daily_rate']) ?> <small class="text-muted">/วัน</small>
                        <?php elseif ($p['salary_type'] === 'Hourly'): ?>
                        <?= formatNumber($p['hourly_rate']) ?> <small class="text-muted">/ชม.</small>
                        <?php else: ?>
                        <span class="text-muted">ยังไม่กำหนด</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?= $p['total_allowances'] ? formatNumber($p['total_allowances']) : '-' ?>
                    </td>
                    <td>
                        <?= $p['effective_date'] ? formatDate($p['effective_date']) : '-' ?>
                    </td>
                    <td>
                        <a href="edit.php?people_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" title="เพิ่ม/แก้ไขเงินเดือน">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="history.php?people_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="ประวัติ">
                            <i class="bi bi-clock-history"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
