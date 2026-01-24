<?php
/**
 * Booking Conflict Detection UI
 * ERP v2 - Phase 7
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
if (!$rbac->can('view', 'PLAN')) {
    header('Location: /4erpv2/error.php?code=403');
    exit;
}

$plan = new Plan();
$conflicts = $plan->getAllConflicts();
$totalConflicts = count($conflicts['serials']) + count($conflicts['people']);

$pageTitle = 'Booking Conflicts - ERP v2';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>Booking Conflicts
                </h2>
                <p class="text-muted">ตรวจสอบ Serial/บุคลากร ที่ถูกจองซ้ำซ้อน</p>
            </div>
            <a href="/4erpv2/modules/planning/" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Plans
            </a>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card stat-card <?= $totalConflicts > 0 ? 'danger' : 'success' ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= $totalConflicts ?></div>
                        <div class="stat-label">Total Conflicts</div>
                    </div>
                    <i class="bi bi-<?= $totalConflicts > 0 ? 'exclamation-triangle' : 'check-circle' ?>" 
                       style="font-size: 2.5rem; opacity: 0.3;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card <?= count($conflicts['serials']) > 0 ? 'warning' : '' ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= count($conflicts['serials']) ?></div>
                        <div class="stat-label">Serial Conflicts</div>
                    </div>
                    <i class="bi bi-upc-scan" style="font-size: 2.5rem; opacity: 0.3;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card <?= count($conflicts['people']) > 0 ? 'warning' : '' ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= count($conflicts['people']) ?></div>
                        <div class="stat-label">People Conflicts</div>
                    </div>
                    <i class="bi bi-people" style="font-size: 2.5rem; opacity: 0.3;"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($totalConflicts === 0): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle me-2"></i>
    <strong>ไม่พบ Conflict!</strong> ไม่มี Serial หรือบุคลากรที่ถูกจองซ้ำซ้อนในช่วงเวลาเดียวกัน
</div>
<?php else: ?>

<!-- Serial Conflicts -->
<?php if (!empty($conflicts['serials'])): ?>
<div class="card mb-4">
    <div class="card-header bg-warning text-dark">
        <i class="bi bi-upc-scan me-2"></i>Serial Conflicts (<?= count($conflicts['serials']) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Serial</th>
                        <th>Item</th>
                        <th>Plan 1</th>
                        <th>Job 1</th>
                        <th>ช่วงเวลา 1</th>
                        <th>Plan 2</th>
                        <th>Job 2</th>
                        <th>ช่วงเวลา 2</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($conflicts['serials'] as $c): ?>
                    <tr>
                        <td><code><?= e($c['serial_number']) ?></code></td>
                        <td><?= e($c['item_name']) ?></td>
                        <td>
                            <a href="/4erpv2/modules/planning/view.php?id=<?= $c['plan1_id'] ?>">
                                <?= e($c['plan1_number']) ?>
                            </a>
                        </td>
                        <td><?= e($c['job1_number']) ?></td>
                        <td>
                            <small><?= formatDate($c['plan1_start']) ?> - <?= formatDate($c['plan1_end']) ?></small>
                        </td>
                        <td>
                            <a href="/4erpv2/modules/planning/view.php?id=<?= $c['plan2_id'] ?>">
                                <?= e($c['plan2_number']) ?>
                            </a>
                        </td>
                        <td><?= e($c['job2_number']) ?></td>
                        <td>
                            <small><?= formatDate($c['plan2_start']) ?> - <?= formatDate($c['plan2_end']) ?></small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- People Conflicts -->
<?php if (!empty($conflicts['people'])): ?>
<div class="card mb-4">
    <div class="card-header bg-warning text-dark">
        <i class="bi bi-people me-2"></i>People Conflicts (<?= count($conflicts['people']) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ชื่อ</th>
                        <th>ตำแหน่ง</th>
                        <th>Plan 1</th>
                        <th>Job 1</th>
                        <th>ช่วงเวลา 1</th>
                        <th>Plan 2</th>
                        <th>Job 2</th>
                        <th>ช่วงเวลา 2</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($conflicts['people'] as $c): ?>
                    <tr>
                        <td><strong><?= e($c['people_name']) ?></strong></td>
                        <td><?= e($c['position']) ?></td>
                        <td>
                            <a href="/4erpv2/modules/planning/view.php?id=<?= $c['plan1_id'] ?>">
                                <?= e($c['plan1_number']) ?>
                            </a>
                        </td>
                        <td><?= e($c['job1_number']) ?></td>
                        <td>
                            <small><?= formatDate($c['plan1_start']) ?> - <?= formatDate($c['plan1_end']) ?></small>
                        </td>
                        <td>
                            <a href="/4erpv2/modules/planning/view.php?id=<?= $c['plan2_id'] ?>">
                                <?= e($c['plan2_number']) ?>
                            </a>
                        </td>
                        <td><?= e($c['job2_number']) ?></td>
                        <td>
                            <small><?= formatDate($c['plan2_start']) ?> - <?= formatDate($c['plan2_end']) ?></small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    <strong>วิธีแก้ไข:</strong> แก้ไข Plan ที่ conflict โดยเปลี่ยน Serial/บุคลากร หรือปรับช่วงเวลาให้ไม่ทับกัน
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
