<?php
/**
 * HR Manpower Management - Approved PO Manpower list
 * 4ERP
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

if (!$auth->hasRole(ROLE_HRM) && !$auth->isAdmin()) {
    setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    redirect(BASE_URL . '/index.php');
}

$db = getDB();

$stmt = $db->prepare("
    SELECT
        po.id,
        po.po_number,
        po.order_date,
        po.status,
        s.name AS supplier_name,
        j.job_number,
        COALESCE(req.required_people, 0) AS required_people,
        COALESCE(reg.registered_people, 0) AS registered_people
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_requests pr ON po.pr_id = pr.id
    LEFT JOIN jobs j ON pr.job_id = j.id
    LEFT JOIN (
        SELECT po_id, SUM(qty) AS required_people
        FROM po_items
        GROUP BY po_id
    ) req ON req.po_id = po.id
    LEFT JOIN (
        SELECT po_id, COUNT(*) AS registered_people
        FROM po_manpower
        GROUP BY po_id
    ) reg ON reg.po_id = po.id
    WHERE po.po_type = 'Manpower' AND po.status = 'Approved'
    ORDER BY po.order_date DESC, po.id DESC
");
$stmt->execute();
$rows = $stmt->fetchAll();

$pageTitle = 'Manpower PO (HR) - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-people me-2"></i>Manpower PO (HR)</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">HRM</a></li>
                    <li class="breadcrumb-item active">Manpower PO</li>
                </ol>
            </nav>
        </div>
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clipboard2-check me-2"></i>PO Manpower ที่อนุมัติแล้ว</span>
        <span class="text-muted small">สำหรับ HR ลงทะเบียนแรงงาน</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>เลขที่ PO</th>
                        <th>Supplier</th>
                        <th>Job</th>
                        <th>วันที่สั่ง</th>
                        <th class="text-center">ต้องการ (คน)</th>
                        <th class="text-center">ลงทะเบียนแล้ว</th>
                        <th class="text-center">สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">ไม่มี PO Manpower ที่รอ HR จัดการ</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                    <?php
                        $required = (float) $row['required_people'];
                        $registered = (float) $row['registered_people'];
                        $progress = $required > 0 ? ($registered / $required) * 100 : 0;
                        $badgeClass = $progress >= 100 ? 'success' : ($progress > 0 ? 'warning text-dark' : 'secondary');
                    ?>
                    <tr>
                        <td>
                            <a href="<?= BASE_URL ?>/modules/procurement/po/view.php?id=<?= $row['id'] ?>">
                                <?= e($row['po_number']) ?>
                            </a>
                        </td>
                        <td><?= e($row['supplier_name']) ?></td>
                        <td><?= $row['job_number'] ? e($row['job_number']) : '-' ?></td>
                        <td><?= formatDate($row['order_date']) ?></td>
                        <td class="text-center"><?= formatNumber($required, 0) ?></td>
                        <td class="text-center"><?= formatNumber($registered, 0) ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?= $badgeClass ?>">
                                <?= $progress >= 100 ? 'ครบแล้ว' : ($progress > 0 ? 'กำลังลงทะเบียน' : 'รอเริ่ม') ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="<?= BASE_URL ?>/modules/procurement/po/manpower.php?po_id=<?= $row['id'] ?>" class="btn btn-sm btn-primary">
                                <i class="bi bi-person-plus me-1"></i>จัดการ
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
