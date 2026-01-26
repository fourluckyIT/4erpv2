<?php
/**
 * Overtime Management
 * ERP v2 - HR Module
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();

// Get filter
$status = get('status', 'Pending');
$month = get('month', date('Y-m'));

// Get OT records
$sql = "
    SELECT 
        ot.*,
        p.full_name, p.code as people_code, p.position,
        j.job_number,
        u.full_name as created_by_name,
        a.full_name as approved_by_name
    FROM people_overtime ot
    JOIN people p ON ot.people_id = p.id
    LEFT JOIN jobs j ON ot.job_id = j.id
    LEFT JOIN users u ON ot.created_by = u.id
    LEFT JOIN users a ON ot.approved_by = a.id
    WHERE ot.status = ?
    AND DATE_FORMAT(ot.work_date, '%Y-%m') = ?
    ORDER BY ot.work_date DESC, ot.created_at DESC
";

$stmt = $db->prepare($sql);
$stmt->execute([$status, $month]);
$otRecords = $stmt->fetchAll();

// Stats for current month
$stats = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END) as paid,
        SUM(CASE WHEN status = 'Approved' OR status = 'Paid' THEN ot_amount ELSE 0 END) as total_amount
    FROM people_overtime
    WHERE DATE_FORMAT(work_date, '%Y-%m') = ?
");
$stats->execute([$month]);
$stats = $stats->fetch();

$pageTitle = 'Overtime Management - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-clock-history me-2"></i>Overtime Management</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="../people/">HR</a></li>
                    <li class="breadcrumb-item active">Overtime</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="submit.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>บันทึก OT
            </a>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-warning text-dark">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="mb-0"><?= $stats['pending'] ?? 0 ?></h4>
                        <small>รออนุมัติ</small>
                    </div>
                    <i class="bi bi-hourglass-split fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="mb-0"><?= $stats['approved'] ?? 0 ?></h4>
                        <small>อนุมัติแล้ว</small>
                    </div>
                    <i class="bi bi-check-circle fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="mb-0"><?= $stats['paid'] ?? 0 ?></h4>
                        <small>จ่ายแล้ว</small>
                    </div>
                    <i class="bi bi-cash-coin fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="mb-0"><?= formatNumber($stats['total_amount'] ?? 0) ?></h4>
                        <small>ยอดรวม OT (บาท)</small>
                    </div>
                    <i class="bi bi-currency-exchange fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-auto">
                <div class="btn-group" role="group">
                    <a href="?status=Pending&month=<?= $month ?>" class="btn btn-<?= $status === 'Pending' ? 'warning' : 'outline-warning' ?> btn-sm">รออนุมัติ</a>
                    <a href="?status=Approved&month=<?= $month ?>" class="btn btn-<?= $status === 'Approved' ? 'success' : 'outline-success' ?> btn-sm">อนุมัติแล้ว</a>
                    <a href="?status=Paid&month=<?= $month ?>" class="btn btn-<?= $status === 'Paid' ? 'primary' : 'outline-primary' ?> btn-sm">จ่ายแล้ว</a>
                    <a href="?status=Rejected&month=<?= $month ?>" class="btn btn-<?= $status === 'Rejected' ? 'danger' : 'outline-danger' ?> btn-sm">ปฏิเสธ</a>
                </div>
            </div>
            <div class="col-auto">
                <input type="month" class="form-control form-control-sm" name="month" value="<?= $month ?>" onchange="this.form.submit()">
                <input type="hidden" name="status" value="<?= $status ?>">
            </div>
        </form>
    </div>
</div>

<!-- OT List -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>วันที่</th>
                    <th>พนักงาน</th>
                    <th>ประเภท OT</th>
                    <th>เวลา</th>
                    <th class="text-end">ชั่วโมง</th>
                    <th class="text-end">อัตรา</th>
                    <th class="text-end">ค่า OT</th>
                    <th>Job</th>
                    <th>สถานะ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($otRecords)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
                <?php else: ?>
                <?php foreach ($otRecords as $ot): ?>
                <tr>
                    <td><?= formatDate($ot['work_date']) ?></td>
                    <td>
                        <strong><?= e($ot['full_name']) ?></strong>
                        <br><small class="text-muted"><?= e($ot['position']) ?></small>
                    </td>
                    <td>
                        <span class="badge bg-<?= match($ot['ot_type']) {
                            'Weekday' => 'secondary',
                            'Weekend' => 'info',
                            'Holiday' => 'danger',
                            default => 'light text-dark'
                        } ?>">
                            <?= match($ot['ot_type']) {
                                'Weekday' => 'วันธรรมดา',
                                'Weekend' => 'วันหยุด',
                                'Holiday' => 'วันหยุดนักขัตฤกษ์',
                                default => $ot['ot_type']
                            } ?>
                        </span>
                    </td>
                    <td>
                        <small><?= substr($ot['start_time'], 0, 5) ?> - <?= substr($ot['end_time'], 0, 5) ?></small>
                    </td>
                    <td class="text-end"><?= formatNumber($ot['total_hours'], 1) ?></td>
                    <td class="text-end">
                        <?= formatNumber($ot['base_rate']) ?> × <?= $ot['multiplier'] ?>
                    </td>
                    <td class="text-end"><strong><?= formatNumber($ot['ot_amount']) ?></strong></td>
                    <td><?= $ot['job_number'] ? e($ot['job_number']) : '-' ?></td>
                    <td>
                        <span class="badge bg-<?= match($ot['status']) {
                            'Pending' => 'warning text-dark',
                            'Approved' => 'success',
                            'Paid' => 'primary',
                            'Rejected' => 'danger',
                            default => 'secondary'
                        } ?>">
                            <?= match($ot['status']) {
                                'Pending' => 'รออนุมัติ',
                                'Approved' => 'อนุมัติแล้ว',
                                'Paid' => 'จ่ายแล้ว',
                                'Rejected' => 'ปฏิเสธ',
                                default => $ot['status']
                            } ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($ot['status'] === 'Pending' && ($auth->hasRole(ROLE_MANAGER) || $auth->isAdmin())): ?>
                        <form method="POST" action="approve.php" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="ot_id" value="<?= $ot['id'] ?>">
                            <button type="submit" name="action" value="approve" class="btn btn-sm btn-success" onclick="return confirm('อนุมัติ OT นี้?')">
                                <i class="bi bi-check"></i>
                            </button>
                            <button type="submit" name="action" value="reject" class="btn btn-sm btn-danger" onclick="return confirm('ปฏิเสธ OT นี้?')">
                                <i class="bi bi-x"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
