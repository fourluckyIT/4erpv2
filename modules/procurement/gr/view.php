<?php
/**
 * View GR
 * 4ERP - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$rbac = new RBAC();
$db = getDB();
$audit = new AuditLog();
$notification = new Notification();
$id = (int) get('id');

$colCheck = $db->prepare("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
");
$colCheck->execute(['goods_receipts', 'voided_by']);
$hasGrVoidColumn = ((int) $colCheck->fetchColumn()) > 0;

if (!$id) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get GR
$voidSelect = $hasGrVoidColumn ? ", v.full_name as voided_by_name" : ", NULL as voided_by_name";
$voidJoin = $hasGrVoidColumn ? "LEFT JOIN users v ON gr.voided_by = v.id" : "";
$stmt = $db->prepare("
    SELECT gr.*, 
           po.po_number, po.supplier_id, po.status as po_status,
           po.created_by as po_created_by, po.submitted_by as po_submitted_by, po.approved_by as po_approved_by,
           s.name as supplier_name,
           u.full_name as receiver_name,
           c.full_name as confirmer_name
           {$voidSelect}
    FROM goods_receipts gr
    JOIN purchase_orders po ON gr.po_id = po.id
    JOIN suppliers s ON po.supplier_id = s.id
    JOIN users u ON gr.received_by = u.id
    LEFT JOIN users c ON gr.confirmed_by = c.id
    {$voidJoin}
    WHERE gr.id = ?
");
$stmt->execute([$id]);
$gr = $stmt->fetch();

if (!$gr) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get items
$items = $db->prepare("
    SELECT gri.*, poi.description, poi.qty as po_qty, poi.unit, 
           i.code as item_code, i.item_type, i.is_serialized
    FROM gr_items gri
    JOIN po_items poi ON gri.po_item_id = poi.id
    LEFT JOIN items i ON poi.item_id = i.id
    WHERE gri.gr_id = ?
");
$items->execute([$id]);
$items = $items->fetchAll();

$needsBackfill = false;
$needsSerialBackfill = false;
$hasLinkedItems = false;
$missingSerialRows = [];
foreach ($items as $it) {
    if (empty($it['item_code'])) {
        $needsBackfill = true;
    } else {
        $hasLinkedItems = true;
    }
    $itemType = strtolower((string) ($it['item_type'] ?? ''));
    $requiresSerial = ((int)($it['is_serialized'] ?? 0) === 1)
        || in_array($itemType, ['device', 'equipment', 'vehicle'], true);
    if ($requiresSerial) {
        $serials = [];
        if (!empty($it['serial_numbers'])) {
            $decoded = json_decode((string)$it['serial_numbers'], true);
            if (is_array($decoded)) {
                $serials = $decoded;
            }
        }
        $receivedQty = (int) $it['received_qty'];
        if (count($serials) < $receivedQty) {
            $needsSerialBackfill = true;
            $missingSerialRows[] = $it['description'] ?? ($it['item_code'] ?? 'Unknown');
        }
    }
}

// Permissions / UI flags
$backfillRoles = ['ADM', 'PUR', 'WH', 'MGR'];
$canBackfill = !empty(array_intersect($backfillRoles, $_SESSION['roles'] ?? []));
$canConfirm = $gr['status'] === 'Draft';
$showBackfill = ($needsBackfill || $needsSerialBackfill || $hasLinkedItems) && $canBackfill;
$isVoided = $hasGrVoidColumn && $gr['status'] === 'Voided';
if ($isVoided) {
    $canConfirm = false;
    $showBackfill = false;
}
$showActions = $canConfirm || $showBackfill;

// Handle actions
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("view.php?id=$id");
    }
    
    $action = post('action');
    
    if ($action === 'confirm' && $gr['status'] === 'Draft') {
        $db->prepare("
            UPDATE goods_receipts 
            SET status = 'Confirmed', confirmed_at = NOW(), confirmed_by = ?
            WHERE id = ?
        ")->execute([$_SESSION['user_id'], $id]);
        
        $audit->log('confirm', 'GR', $id);
        
        $recipients = $rbac->getUserIdsWithPermission('view', 'PO', $gr['po_status'] ?? null);
        $extraUsers = array_filter([
            $gr['po_created_by'] ?? null,
            $gr['po_submitted_by'] ?? null,
            $gr['po_approved_by'] ?? null,
            $gr['received_by'] ?? null
        ], fn($v) => !empty($v));
        $recipients = array_values(array_unique(array_merge($recipients, array_map('intval', $extraUsers))));
        
        if (!empty($recipients)) {
            $title = "GR {$gr['gr_number']} ยืนยันรับสินค้าแล้ว";
            $message = "PO {$gr['po_number']} / Supplier: {$gr['supplier_name']}";
            $url = "/4erpv2/modules/procurement/gr/view.php?id={$id}";
            $notification->createBulk(
                $recipients,
                Notification::TYPE_SYSTEM,
                $title,
                $message,
                $url,
                'GR',
                $id,
                Notification::PRIORITY_NORMAL
            );
        }
        setFlash('success', 'ยืนยันการรับเรียบร้อย');
    }
    
    redirect("view.php?id=$id");
}

$pageTitle = "GR: {$gr['gr_number']} - 4ERP";
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-box-seam" style="color: var(--primary);"></i> <?= e($gr['gr_number']) ?>
            <span class="badge bg-<?= match($gr['status']) {
                'Confirmed' => 'success',
                'Voided' => 'danger',
                default => 'secondary'
            } ?> ms-2">
                <?= match($gr['status']) {
                    'Confirmed' => 'ยืนยันแล้ว',
                    'Voided' => 'ยกเลิก',
                    default => 'แบบร่าง'
                } ?>
            </span>
        </h1>
        <nav aria-label="breadcrumb" class="page-subtitle">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                <li class="breadcrumb-item"><a href="index.php">GR</a></li>
                <li class="breadcrumb-item active"><?= e($gr['gr_number']) ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> กลับ
        </a>
    </div>
</div>

<!-- Actions -->
<?php if ($showActions): ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
            <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <?php if ($canConfirm): ?>
                <button type="submit" name="action" value="confirm" class="btn btn-success" onclick="return confirm('ยืนยันการรับสินค้า?')">
                    <i class="bi bi-check-circle me-1"></i>ยืนยันการรับ
                </button>
                <?php endif; ?>
            </form>
            <?php if ($showBackfill): ?>
            <a href="backfill.php?gr_id=<?= (int)$id ?>" class="btn btn-outline-primary">
                <i class="bi bi-tools me-1"></i>Backfill
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($needsSerialBackfill): ?>
<div class="alert alert-warning">
    <strong>Serial ยังไม่ครบ:</strong> รายการที่ต้องใส่ Serial คือ
    <?= e(implode(', ', array_unique(array_filter($missingSerialRows)))) ?>.
    กดปุ่ม <strong>Backfill</strong> เพื่อกรอก Serial ให้ครบตามจำนวนที่รับ
</div>
<?php endif; ?>

<?php if ($isVoided): ?>
<div class="alert alert-danger">
    <strong>GR ถูกยกเลิก:</strong>
    <?= $gr['void_reason'] ? e($gr['void_reason']) : 'ไม่มีเหตุผลระบุ' ?>
</div>
<?php endif; ?>

<!-- GR Info -->
<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">ข้อมูลการรับ</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="120">เลขที่ GR</th>
                        <td><?= e($gr['gr_number']) ?></td>
                    </tr>
                    <tr>
                        <th>PO</th>
                        <td><a href="../po/view.php?id=<?= $gr['po_id'] ?>"><?= e($gr['po_number']) ?></a></td>
                    </tr>
                    <tr>
                        <th>ผู้ขาย</th>
                        <td><?= e($gr['supplier_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่รับ</th>
                        <td><?= formatDate($gr['received_date']) ?></td>
                    </tr>
                    <tr>
                        <th>ผู้รับ</th>
                        <td><?= e($gr['receiver_name']) ?></td>
                    </tr>
                    <?php if (!empty($gr['notes'])): ?>
                    <tr>
                        <th>หมายเหตุ</th>
                        <td><?= e($gr['notes']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">สถานะ</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="120">สถานะ</th>
                        <td>
                            <span class="badge bg-<?= match($gr['status']) {
                                'Confirmed' => 'success',
                                'Voided' => 'danger',
                                default => 'secondary'
                            } ?>">
                                <?= match($gr['status']) {
                                    'Confirmed' => 'ยืนยันแล้ว',
                                    'Voided' => 'ยกเลิก',
                                    default => 'แบบร่าง'
                                } ?>
                            </span>
                        </td>
                    </tr>
                    <?php if (!empty($gr['confirmed_at'])): ?>
                    <tr>
                        <th>ยืนยันโดย</th>
                        <td><?= e($gr['confirmer_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่ยืนยัน</th>
                        <td><?= formatDateTime($gr['confirmed_at']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($isVoided): ?>
                    <tr>
                        <th>ยกเลิกโดย</th>
                        <td><?= e($gr['voided_by_name'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>วันที่ยกเลิก</th>
                        <td><?= $gr['voided_at'] ? formatDateTime($gr['voided_at']) : '-' ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Items -->
<div class="card mb-4">
    <div class="card-header">รายการที่รับ</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>รหัส</th>
                        <th>รายละเอียด</th>
                        <th class="text-center">รับ</th>
                        <th>หน่วย</th>
                        <th>สภาพ</th>
                        <th>Serial</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $item): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= $item['item_code'] ?: '-' ?></td>
                        <td><?= e($item['description']) ?></td>
                        <td class="text-center">
                            <span class="badge bg-success"><?= formatNumber($item['received_qty'], 0) ?></span>
                        </td>
                        <td><?= e($item['unit']) ?></td>
                        <td><?= e($item['condition_note']) ?: '-' ?></td>
                        <td>
                            <?php if ($item['serial_numbers']): 
                                $serials = json_decode($item['serial_numbers'], true);
                            ?>
                            <?php foreach ($serials as $sn): ?>
                            <span class="badge bg-secondary"><?= e($sn) ?></span>
                            <?php endforeach; ?>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
