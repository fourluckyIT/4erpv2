<?php
/**
 * View PO
 * 4ERP - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../warehouse/WarehouseService.php';

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
$colCheck->execute(['purchase_orders', 'voided_by']);
$hasPoVoidColumn = ((int) $colCheck->fetchColumn()) > 0;
$colCheck->execute(['goods_receipts', 'voided_by']);
$hasGrVoidColumn = ((int) $colCheck->fetchColumn()) > 0;

if (!$id) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

// Get PO
$voidSelect = $hasPoVoidColumn ? ", v.full_name as voided_by_name" : ", NULL as voided_by_name";
$voidJoin = $hasPoVoidColumn ? "LEFT JOIN users v ON po.voided_by = v.id" : "";
$stmt = $db->prepare("
    SELECT po.*,
           s.name as supplier_name, s.code as supplier_code,
           pr.pr_number,
           u.full_name as created_by_name,
           a.full_name as approved_by_name
           {$voidSelect}
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_requests pr ON po.pr_id = pr.id
    LEFT JOIN users u ON po.created_by = u.id
    LEFT JOIN users a ON po.approved_by = a.id
    {$voidJoin}
    WHERE po.id = ?
");
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('index.php');
}

$isManpowerPo = ($po['po_type'] ?? '') === 'Manpower';

// Manpower summary (for void options)
$manpowerSummary = [];
$manpowerActiveTotal = 0;
if ($isManpowerPo) {
    $stmt = $db->prepare("
        SELECT position, COUNT(*) as cnt
        FROM po_manpower
        WHERE po_id = ? AND status <> 'Cancelled'
        GROUP BY position
        ORDER BY position
    ");
    $stmt->execute([$id]);
    $manpowerSummary = $stmt->fetchAll();
    foreach ($manpowerSummary as $row) {
        $manpowerActiveTotal += (int) $row['cnt'];
    }
}

// Get items
$items = $db->prepare("
    SELECT poi.*, i.code as item_code
    FROM po_items poi
    LEFT JOIN items i ON poi.item_id = i.id
    WHERE poi.po_id = ?
");
$items->execute([$id]);
$items = $items->fetchAll();

if ($isManpowerPo && in_array($po['status'], ['Approved', 'Partially Received', 'Received'], true)) {
    $requiredTotal = 0;
    foreach ($items as $row) {
        $requiredTotal += (int) ($row['qty'] ?? 0);
    }

    if ($requiredTotal > 0) {
        if ($manpowerActiveTotal <= 0) {
            $newStatus = 'Approved';
        } elseif ($manpowerActiveTotal < $requiredTotal) {
            $newStatus = 'Partially Received';
        } else {
            $newStatus = 'Received';
        }

        if ($newStatus !== $po['status']) {
            $db->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            $audit->log('update', 'PO', $id, ['status' => $po['status']], ['status' => $newStatus], 'Manpower registration sync');
            $po['status'] = $newStatus;
        }
    }
}

// Get GRs
$grs = $db->prepare("
    SELECT gr.*, u.full_name as received_by_name
    FROM goods_receipts gr
    LEFT JOIN users u ON gr.received_by = u.id
    WHERE gr.po_id = ?
    ORDER BY gr.received_date DESC
");
$grs->execute([$id]);
$grs = $grs->fetchAll();

// Handle actions
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("view.php?id=$id");
    }

    $action = post('action');

    if ($action === 'submit' && $po['status'] === 'Draft') {
        $db->prepare("
            UPDATE purchase_orders
            SET status = 'Submitted', submitted_at = NOW(), submitted_by = ?
            WHERE id = ?
        ")->execute([$_SESSION['user_id'], $id]);

        $audit->log('submit', 'PO', $id);
        $approverIds = $rbac->getUserIdsWithPermission('approve', 'PO', 'Submitted');
        if (!empty($approverIds)) {
            $title = "PO {$po['po_number']} รออนุมัติ";
            $message = "Supplier: {$po['supplier_name']}";
            $url = "/4erpv2/modules/procurement/po/view.php?id={$id}";
            $notification->createBulk(
                $approverIds,
                Notification::TYPE_APPROVAL_REQUEST,
                $title,
                $message,
                $url,
                'PO',
                $id,
                Notification::PRIORITY_HIGH
            );
        }
        setFlash('success', 'ส่งอนุมัติเรียบร้อย');

    } elseif ($action === 'approve' && $po['status'] === 'Submitted') {
        if (!$rbac->can('approve', 'PO', $po['status'])) {
            setFlash('error', 'คุณไม่มีสิทธิ์อนุมัติ');
            redirect("view.php?id=$id");
        }
        
        $db->prepare("
            UPDATE purchase_orders
            SET status = 'Approved', approved_at = NOW(), approved_by = ?
            WHERE id = ?
        ")->execute([$_SESSION['user_id'], $id]);

        $audit->log('approve', 'PO', $id);
        $notification->markReadByEntity('PO', $id, Notification::TYPE_APPROVAL_REQUEST);
        $requesterId = $po['submitted_by'] ?? $po['created_by'];
        if (!empty($requesterId)) {
            $title = "PO {$po['po_number']} อนุมัติแล้ว";
            $message = "Supplier: {$po['supplier_name']}";
            $url = "/4erpv2/modules/procurement/po/view.php?id={$id}";
            $notification->create(
                (int) $requesterId,
                Notification::TYPE_APPROVAL_RESULT,
                $title,
                $message,
                $url,
                'PO',
                $id,
                Notification::PRIORITY_NORMAL
            );
        }
        if (($po['po_type'] ?? '') === 'Manpower') {
            $hrStmt = $db->prepare("
                SELECT DISTINCT u.id
                FROM users u
                JOIN user_roles ur ON u.id = ur.user_id
                JOIN roles r ON ur.role_id = r.id
                WHERE r.code = ? AND u.is_active = 1
            ");
            $hrStmt->execute([ROLE_HRM]);
            $hrIds = array_map('intval', $hrStmt->fetchAll(PDO::FETCH_COLUMN));
            if (!empty($hrIds)) {
                $title = "PO Manpower {$po['po_number']} อนุมัติแล้ว";
                $message = "Supplier: {$po['supplier_name']}\nกรุณาลงทะเบียนแรงงาน";
                $url = "/4erpv2/modules/procurement/po/manpower.php?po_id={$id}";
                $notification->createBulk(
                    $hrIds,
                    Notification::TYPE_SYSTEM,
                    $title,
                    $message,
                    $url,
                    'PO',
                    $id,
                    Notification::PRIORITY_HIGH
                );
            }
        }
        setFlash('success', 'อนุมัติเรียบร้อย');

    } elseif ($action === 'void' && in_array($po['status'], ['Approved', 'Partially Received', 'Received'], true)) {
        if (!$rbac->can('void', 'PO', $po['status'])) {
            setFlash('error', 'คุณไม่มีสิทธิ์ยกเลิกหลังอนุมัติ');
            redirect("view.php?id=$id");
        }
        if (!$hasPoVoidColumn || (!$hasGrVoidColumn && $po['po_type'] !== 'Manpower')) {
            setFlash('error', 'ยังไม่ได้อัปเดต schema สำหรับ Void (โปรดรัน schema_patch_po_void_workflow.sql)');
            redirect("view.php?id=$id");
        }

        $reason = trim((string) post('void_reason', ''));
        if ($reason === '') {
            setFlash('error', 'กรุณาระบุเหตุผล');
            redirect("view.php?id=$id");
        }

        $manpowerMode = post('manpower_void_mode', 'cancel');
        if (!in_array($manpowerMode, ['cancel', 'transfer'], true)) {
            $manpowerMode = 'cancel';
        }

        try {
            $db->beginTransaction();

            if ($po['po_type'] === 'Manpower') {
                $peopleRows = $db->prepare("
                    SELECT pm.people_id, pm.position, pm.status, p.people_type
                    FROM po_manpower pm
                    JOIN people p ON pm.people_id = p.id
                    WHERE pm.po_id = ? AND pm.status <> 'Cancelled'
                ");
                $peopleRows->execute([$id]);
                $peopleRows = $peopleRows->fetchAll();

                $peopleIds = array_values(array_unique(array_map(
                    fn($row) => (int) $row['people_id'],
                    $peopleRows ?: []
                )));

                if (!empty($peopleIds)) {
                    $placeholders = implode(',', array_fill(0, count($peopleIds), '?'));

                    if ($manpowerMode === 'transfer') {
                        $db->prepare("UPDATE people SET people_type = 'Employee', supplier_id = NULL WHERE id IN ($placeholders)")
                            ->execute($peopleIds);
                        $db->prepare("UPDATE po_manpower SET status = 'Ended' WHERE po_id = ? AND status <> 'Cancelled'")
                            ->execute([$id]);
                        $audit->log('transfer_manpower', 'PO', $id, null, [
                            'mode' => 'transfer',
                            'people_ids' => $peopleIds
                        ], $reason);
                    } else {
                        $db->prepare("UPDATE po_manpower SET status = 'Cancelled' WHERE po_id = ? AND status <> 'Cancelled'")
                            ->execute([$id]);
                        $db->prepare("UPDATE people SET is_active = 0, end_date = CURDATE() WHERE id IN ($placeholders) AND people_type = 'External'")
                            ->execute($peopleIds);
                        $audit->log('void_manpower', 'PO', $id, null, [
                            'mode' => 'cancel',
                            'people_ids' => $peopleIds
                        ], $reason);
                    }
                }
            } else {
                $warehouseService = new WarehouseService();

                $grStmt = $db->prepare("
                    SELECT id, gr_number, status
                    FROM goods_receipts
                    WHERE po_id = ? AND status <> 'Voided'
                ");
                $grStmt->execute([$id]);
                $grRows = $grStmt->fetchAll();

                foreach ($grRows as $grRow) {
                    $grId = (int) $grRow['id'];

                    $moveStmt = $db->prepare("
                        SELECT id, item_id, qty, serial_id
                        FROM stock_movements
                        WHERE reference_table = 'goods_receipts'
                          AND reference_id = ?
                          AND movement_type = 'GR_PO'
                          AND reverse_of_id IS NULL
                    ");
                    $moveStmt->execute([$grId]);
                    $moves = $moveStmt->fetchAll();

                    $itemTotals = [];
                    $serialIds = [];
                    foreach ($moves as $move) {
                        $itemId = (int) $move['item_id'];
                        $qty = (float) $move['qty'];
                        $serialId = $move['serial_id'] !== null ? (int) $move['serial_id'] : null;
                        if ($serialId) {
                            $serialIds[] = $serialId;
                        } else {
                            $itemTotals[$itemId] = ($itemTotals[$itemId] ?? 0) + $qty;
                        }
                    }

                    foreach ($itemTotals as $itemId => $qty) {
                        $stmtQty = $db->prepare("SELECT quantity FROM items WHERE id = ?");
                        $stmtQty->execute([$itemId]);
                        $currentQty = (float) $stmtQty->fetchColumn();
                        if ($currentQty + 0.00001 < $qty) {
                            throw new Exception("สต็อกไม่พอสำหรับย้อน GR (Item #$itemId)");
                        }
                    }

                    if (!empty($serialIds)) {
                        $placeholders = implode(',', array_fill(0, count($serialIds), '?'));
                        $stmtSerial = $db->prepare("
                            SELECT id, status, location
                            FROM serials
                            WHERE id IN ($placeholders)
                        ");
                        $stmtSerial->execute($serialIds);
                        $serialRows = $stmtSerial->fetchAll();
                        foreach ($serialRows as $row) {
                            if ($row['status'] !== 'Available' || ($row['location'] ?? '') !== 'WH') {
                                throw new Exception('Serial ไม่อยู่ใน WH/Available (ID: ' . $row['id'] . ')');
                            }
                        }
                    }

                    foreach ($moves as $move) {
                        $reverse = $warehouseService->reverseMovement((int) $move['id'], "VOID PO {$po['po_number']} / GR {$grRow['gr_number']}");
                        if (empty($reverse['success'])) {
                            throw new Exception($reverse['error'] ?? 'ย้อน stock movement ไม่สำเร็จ');
                        }

                        if (!empty($move['serial_id'])) {
                            $db->prepare("
                                UPDATE serials
                                SET status = 'Returned', location = ?, current_job_id = NULL
                                WHERE id = ?
                            ")->execute([WarehouseService::LOC_SUPPLIER, (int) $move['serial_id']]);
                        }
                    }

                    $grItems = $db->prepare("
                        SELECT po_item_id, received_qty
                        FROM gr_items
                        WHERE gr_id = ?
                    ");
                    $grItems->execute([$grId]);
                    foreach ($grItems->fetchAll() as $grItem) {
                        $db->prepare("
                            UPDATE po_items
                            SET received_qty = GREATEST(received_qty - ?, 0)
                            WHERE id = ?
                        ")->execute([(float) $grItem['received_qty'], (int) $grItem['po_item_id']]);
                    }

                    $db->prepare("
                        UPDATE goods_receipts
                        SET status = 'Voided', voided_at = NOW(), voided_by = ?, void_reason = ?
                        WHERE id = ?
                    ")->execute([$_SESSION['user_id'], $reason, $grId]);

                    $audit->log('void', 'GR', $grId, ['status' => $grRow['status']], ['status' => 'Voided'], $reason);
                }
            }

            $db->prepare("
                UPDATE purchase_orders
                SET status = 'Voided', voided_at = NOW(), voided_by = ?, void_reason = ?
                WHERE id = ?
            ")->execute([$_SESSION['user_id'], $reason, $id]);

            $audit->log('void', 'PO', $id, ['status' => $po['status']], ['status' => 'Voided'], $reason);
            $notification->markReadByEntity('PO', $id, Notification::TYPE_APPROVAL_REQUEST);
            $db->commit();

            setFlash('success', 'ยกเลิก PO หลังอนุมัติเรียบร้อย');
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }

    } elseif ($action === 'cancel' && in_array($po['status'], ['Draft', 'Submitted'])) {
        if (!$rbac->can('void', 'PO', $po['status'])) {
            setFlash('error', 'คุณไม่มีสิทธิ์ยกเลิก');
            redirect("view.php?id=$id");
        }
        $reason = trim((string) post('cancel_reason', ''));
        if ($reason === '') {
            setFlash('error', 'กรุณาระบุเหตุผล');
            redirect("view.php?id=$id");
        }

        $db->prepare("UPDATE purchase_orders SET status = 'Cancelled' WHERE id = ?")->execute([$id]);
        $audit->log('cancel', 'PO', $id, null, ['status' => 'Cancelled'], $reason);
        $notification->markReadByEntity('PO', $id, Notification::TYPE_APPROVAL_REQUEST);
        $requesterId = $po['submitted_by'] ?? $po['created_by'];
        if (!empty($requesterId)) {
            $title = "PO {$po['po_number']} ถูกยกเลิก";
            $message = "Supplier: {$po['supplier_name']}";
            if ($reason !== '') {
                $message .= "\nเหตุผล: {$reason}";
            }
            $url = "/4erpv2/modules/procurement/po/view.php?id={$id}";
            $notification->create(
                (int) $requesterId,
                Notification::TYPE_APPROVAL_RESULT,
                $title,
                $message,
                $url,
                'PO',
                $id,
                Notification::PRIORITY_NORMAL
            );
        }
        setFlash('success', 'ยกเลิกเรียบร้อย');
    }

    redirect("view.php?id=$id");
}

$pageTitle = "PO: {$po['po_number']} - 4ERP";
$canSubmitPo = $po['status'] === 'Draft'
    && ($rbac->can('create', 'PO') || $auth->hasRole(ROLE_PURCHASE) || $auth->isAdmin());
$canApprovePo = $po['status'] === 'Submitted' && $rbac->can('approve', 'PO', $po['status']);
$canVoidPo = in_array($po['status'], ['Approved', 'Partially Received', 'Received'], true)
    && $rbac->can('void', 'PO', $po['status']);
$canCancelDraft = $po['status'] === 'Draft' && $rbac->can('void', 'PO', $po['status']);
$canCancelSubmitted = $po['status'] === 'Submitted' && $rbac->can('void', 'PO', $po['status']);
$canReceiveGr = !$isManpowerPo && in_array($po['status'], ['Approved', 'Partially Received'], true)
    && ($auth->hasRole(ROLE_WAREHOUSE) || $auth->isAdmin());
$canManageManpower = $po['po_type'] === 'Manpower' && $po['status'] === 'Approved'
    && ($auth->hasRole(ROLE_HRM) || $auth->isAdmin());
$showActions = $canSubmitPo || $canApprovePo || $canVoidPo || $canCancelDraft || $canCancelSubmitted || $canReceiveGr || $canManageManpower;
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0">
                <i class="bi bi-receipt me-2"></i><?= e($po['po_number']) ?>
                <span class="badge bg-<?= match($po['status']) {
                    'Draft' => 'secondary',
                    'Submitted' => 'warning text-dark',
                    'Approved' => 'primary',
                    'Partially Received' => 'info',
                    'Received' => 'success',
                    'Cancelled' => 'dark',
                    'Voided' => 'danger',
                    default => 'secondary'
                } ?> ms-2"><?= match($po['status']) {
                    'Draft' => 'แบบร่าง',
                    'Submitted' => 'รออนุมัติ',
                    'Approved' => 'รอรับของ',
                    'Partially Received' => 'รับบางส่วน',
                    'Received' => 'รับครบ',
                    'Cancelled' => 'ยกเลิก',
                    'Voided' => 'ยกเลิกหลังอนุมัติ',
                    default => $po['status']
                } ?></span>
                <span class="badge bg-<?= match($po['po_type']) {
                    'Goods' => 'primary',
                    'Service' => 'info',
                    'Manpower' => 'warning text-dark',
                    default => 'secondary'
                } ?>"><?= match($po['po_type']) {
                    'Goods' => 'สินค้า',
                    'Service' => 'บริการ',
                    'Manpower' => 'แรงงาน',
                    default => $po['po_type']
                } ?></span>
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="index.php">PO</a></li>
                    <li class="breadcrumb-item active"><?= e($po['po_number']) ?></li>
                </ol>
            </nav>
        </div>
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<!-- Actions -->
<?php if ($showActions): ?>
<div class="card mb-4">
    <div class="card-body">
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <?php if ($canSubmitPo): ?>
            <button type="submit" name="action" value="submit" class="btn btn-primary" onclick="return confirm('ยืนยันส่งอนุมัติ?')">
                <i class="bi bi-send me-1"></i>ส่งอนุมัติ
            </button>
            <input type="hidden" name="cancel_reason" id="cancelReasonDraft" value="">
            <button type="submit" name="action" value="cancel" class="btn btn-outline-danger" onclick="return promptCancelReason('cancelReasonDraft')">
                <i class="bi bi-x-circle me-1"></i>ยกเลิก
            </button>
            <?php endif; ?>

            <?php if ($canApprovePo): ?>
            <button type="submit" name="action" value="approve" class="btn btn-success" onclick="return confirm('ยืนยันอนุมัติ?')">
                <i class="bi bi-check-circle me-1"></i>อนุมัติ
            </button>
            <input type="hidden" name="cancel_reason" id="cancelReasonSubmitted" value="">
            <button type="submit" name="action" value="cancel" class="btn btn-outline-danger" onclick="return promptCancelReason('cancelReasonSubmitted')">
                <i class="bi bi-x-circle me-1"></i>ยกเลิก
            </button>
            <?php endif; ?>

            <?php if ($canVoidPo): ?>
            <input type="hidden" name="void_reason" id="voidReason" value="">
            <input type="hidden" name="manpower_void_mode" id="voidManpowerMode" value="cancel">
            <button type="submit" name="action" value="void" class="btn btn-outline-danger"
                    data-manpower-total="<?= (int) $manpowerActiveTotal ?>"
                    data-manpower-summary="<?= e(implode(', ', array_map(function ($row) {
                        return trim(($row['position'] ?? '-') . ' ' . (int) ($row['cnt'] ?? 0) . ' คน');
                    }, $manpowerSummary))) ?>"
                    onclick="return promptVoidReason(this)">
                <i class="bi bi-x-octagon me-1"></i>ยกเลิกหลังอนุมัติ
            </button>
            <?php endif; ?>

            <?php if ($canReceiveGr): ?>
            <a href="../gr/create.php?po_id=<?= $id ?>" class="btn btn-info text-white">
                <i class="bi bi-box-seam me-1"></i>รับของ (GR)
            </a>
            <?php endif; ?>

            <?php if ($canManageManpower): ?>
            <a href="manpower.php?id=<?= $id ?>" class="btn btn-warning">
                <i class="bi bi-people me-1"></i>จัดการแรงงาน
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- PO Info -->
<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">ข้อมูลทั่วไป</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="120">เลขที่ PO</th>
                        <td><?= e($po['po_number']) ?></td>
                    </tr>
                    <?php if ($po['pr_number']): ?>
                    <tr>
                        <th>จาก PR</th>
                        <td>
                            <a href="../pr/view.php?id=<?= $po['pr_id'] ?>" class="badge bg-secondary">
                                <?= e($po['pr_number']) ?>
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>ผู้ขาย</th>
                        <td><strong><?= e($po['supplier_code']) ?></strong> - <?= e($po['supplier_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่สั่ง</th>
                        <td><?= formatDate($po['order_date']) ?></td>
                    </tr>
                    <tr>
                        <th>วันส่งของ</th>
                        <td><?= $po['delivery_date'] ? formatDate($po['delivery_date']) : '-' ?></td>
                    </tr>
                    <tr>
                        <th>เครดิต</th>
                        <td><?= $po['payment_terms'] ?> วัน</td>
                    </tr>
                    <tr>
                        <th>หมายเหตุ</th>
                        <td><?= $po['notes'] ? nl2br(e($po['notes'])) : '-' ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">ข้อมูลการอนุมัติ</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="120">สร้างโดย</th>
                        <td><?= e($po['created_by_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่สร้าง</th>
                        <td><?= formatDateTime($po['created_at']) ?></td>
                    </tr>
                    <?php if ($po['submitted_at']): ?>
                    <tr>
                        <th>วันที่ส่ง</th>
                        <td><?= formatDateTime($po['submitted_at']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($po['approved_at']): ?>
                    <tr>
                        <th>ผู้อนุมัติ</th>
                        <td><?= e($po['approved_by_name']) ?></td>
                    </tr>
                    <tr>
                        <th>วันที่อนุมัติ</th>
                        <td><?= formatDateTime($po['approved_at']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (($po['status'] ?? '') === 'Voided' && $hasPoVoidColumn): ?>
                    <tr>
                        <th>ยกเลิกโดย</th>
                        <td><?= e($po['voided_by_name'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>วันที่ยกเลิก</th>
                        <td><?= !empty($po['voided_at']) ? formatDateTime($po['voided_at']) : '-' ?></td>
                    </tr>
                    <tr>
                        <th>เหตุผล</th>
                        <td><?= !empty($po['void_reason']) ? nl2br(e($po['void_reason'])) : '-' ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($isManpowerPo): ?>
<div class="alert alert-info">
    <i class="bi bi-people me-2"></i>
    PO แรงงานจัดการผ่าน HR เท่านั้น
    <?php if ($auth->hasRole(ROLE_HRM) || $auth->isAdmin()): ?>
        <a href="<?= BASE_URL ?>/modules/procurement/po/manpower.php?po_id=<?= $id ?>" class="btn btn-sm btn-primary ms-2">
            ไปหน้า HR Manpower
        </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Items -->
<div class="card mb-4">
    <div class="card-header">รายการ</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>รหัสสินค้า</th>
                        <th>รายละเอียด</th>
                        <th class="text-center"><?= $isManpowerPo ? 'จำนวนคน' : 'จำนวน' ?></th>
                        <th class="text-center"><?= $isManpowerPo ? 'ระยะเวลา' : 'รับแล้ว' ?></th>
                        <th>หน่วย</th>
                        <th class="text-end">ราคา/หน่วย</th>
                        <th class="text-end">รวม</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $item): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= $item['item_code'] ? e($item['item_code']) : '-' ?></td>
                        <td><?= e($item['description']) ?></td>
                        <td class="text-center"><?= formatNumber($item['qty'], 0) ?></td>
                        <td class="text-center">
                            <?php if ($isManpowerPo): ?>
                                <?php
                                $durationLabel = $item['manpower_unit'] === 'Month' ? 'เดือน' : 'วัน';
                                $durationValue = $item['manpower_duration'] !== null ? formatNumber($item['manpower_duration'], 0) : null;
                                ?>
                                <?= $durationValue !== null ? ($durationValue . ' ' . $durationLabel) : '-' ?>
                            <?php else: ?>
                                <?php
                                $receivedPercent = $item['qty'] > 0 ? ($item['received_qty'] / $item['qty']) * 100 : 0;
                                $badgeClass = $receivedPercent >= 100 ? 'success' : ($receivedPercent > 0 ? 'warning' : 'secondary');
                                ?>
                                <span class="badge bg-<?= $badgeClass ?>"><?= formatNumber($item['received_qty'], 0) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($item['unit']) ?></td>
                        <td class="text-end"><?= formatNumber($item['unit_price']) ?></td>
                        <td class="text-end"><?= formatNumber($item['amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="7" class="text-end">รวมก่อน VAT</td>
                        <td class="text-end"><?= formatNumber($po['subtotal']) ?></td>
                    </tr>
                    <tr>
                        <td colspan="7" class="text-end">VAT <?= formatNumber($po['vat_rate'], 2) ?>%</td>
                        <td class="text-end"><?= formatNumber($po['vat_amount']) ?></td>
                    </tr>
                    <tr class="table-primary">
                        <td colspan="7" class="text-end"><strong>รวมทั้งสิ้น</strong></td>
                        <td class="text-end"><strong><?= formatNumber($po['grand_total']) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Goods Receipts -->
<?php if (!$isManpowerPo && (!empty($grs) || in_array($po['status'], ['Approved', 'Partially Received', 'Received'], true))): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-box-seam me-2"></i>การรับของ (GR)</span>
        <?php if (in_array($po['status'], ['Approved', 'Partially Received'], true)): ?>
        <a href="../gr/create.php?po_id=<?= $id ?>" class="btn btn-sm btn-outline-info">
            <i class="bi bi-plus"></i> รับของ
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>เลขที่ GR</th>
                    <th>วันที่รับ</th>
                    <th>ผู้รับ</th>
                    <th>สถานะ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grs)): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">ยังไม่มีการรับของ</td></tr>
                <?php else: ?>
                <?php foreach ($grs as $gr): ?>
                <tr>
                    <td>
                        <a href="../gr/view.php?id=<?= $gr['id'] ?>">
                            <strong><?= e($gr['gr_number']) ?></strong>
                        </a>
                    </td>
                    <td><?= formatDate($gr['received_date']) ?></td>
                    <td><?= e($gr['received_by_name']) ?></td>
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
                    <td>
                        <a href="../gr/view.php?id=<?= $gr['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>

<script>
function promptCancelReason(inputId) {
    const reason = prompt('ระบุเหตุผลยกเลิก PO');
    if (reason === null) return false;
    if (!reason.trim()) {
        alert('กรุณาระบุเหตุผล');
        return false;
    }
    const input = document.getElementById(inputId);
    if (input) input.value = reason.trim();
    return true;
}

function promptVoidReason(button) {
    const reason = prompt('ระบุเหตุผลยกเลิก PO หลังอนุมัติ');
    if (reason === null) return false;
    if (!reason.trim()) {
        alert('กรุณาระบุเหตุผล');
        return false;
    }
    const reasonInput = document.getElementById('voidReason');
    if (reasonInput) reasonInput.value = reason.trim();

    const modeInput = document.getElementById('voidManpowerMode');
    if (!modeInput) return true;

    const total = parseInt((button && button.dataset ? button.dataset.manpowerTotal : '0') || '0', 10);
    if (total > 0) {
        const summary = (button && button.dataset ? button.dataset.manpowerSummary : '') || '';
        const message = `PO นี้มีแรงงานลงทะเบียน ${total} คน${summary ? '\n' + summary : ''}\n` +
            'กด OK = ยกเลิกแรงงานทั้งหมด\nกด Cancel = โอนเป็นพนักงานภายใน';
        const choice = confirm(message);
        modeInput.value = choice ? 'cancel' : 'transfer';
    } else {
        modeInput.value = 'cancel';
    }
    return true;
}
</script>
