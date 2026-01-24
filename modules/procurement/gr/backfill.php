<?php

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../warehouse/WarehouseService.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: WH, ADM, MGR
if (!in_array('ADM', $_SESSION['roles'] ?? [], true) && !in_array('MGR', $_SESSION['roles'] ?? [], true) && !in_array('WH', $_SESSION['roles'] ?? [], true)) {
    setFlash('error', 'ไม่มีสิทธิ์เข้าถึงหน้านี้');
    redirect(BASE_URL . '/index.php');
}

$db = getDB();
$audit = new AuditLog();
$warehouseService = new WarehouseService();

$grId = (int) get('gr_id');
if (!$grId) {
    setFlash('error', 'ต้องระบุ GR');
    redirect('index.php');
}

$stmt = $db->prepare("
    SELECT gr.*, po.po_number, po.supplier_id, s.name as supplier_name
    FROM goods_receipts gr
    JOIN purchase_orders po ON gr.po_id = po.id
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE gr.id = ?
");
$stmt->execute([$grId]);
$gr = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gr) {
    setFlash('error', 'ไม่พบ GR');
    redirect('index.php');
}

$itemsStmt = $db->prepare("
    SELECT gri.id as gr_item_id, gri.received_qty, gri.serial_numbers, gri.condition_note,
           poi.id as po_item_id, poi.description, poi.unit, poi.item_id, poi.unit_price,
           i.code as item_code, i.name as item_name, i.item_type, i.is_serialized
    FROM gr_items gri
    JOIN po_items poi ON gri.po_item_id = poi.id
    LEFT JOIN items i ON poi.item_id = i.id
    WHERE gri.gr_id = ?
    ORDER BY gri.id
");
$itemsStmt->execute([$grId]);
$grItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$unlinkedCount = 0;
foreach ($grItems as $r) {
    if (empty($r['item_id'])) $unlinkedCount++;
}

if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('backfill.php?gr_id=' . $grId);
    }

    $action = post('action');
    if ($action !== 'backfill') {
        setFlash('error', 'Invalid action');
        redirect('backfill.php?gr_id=' . $grId);
    }

    try {
        $db->beginTransaction();

        // Ensure stock_movements table exists
        try {
            $db->query("SELECT 1 FROM stock_movements LIMIT 1");
        } catch (PDOException $e) {
            throw new Exception('ยังไม่มีตาราง stock_movements กรุณารัน sql/schema_m4_warehouse.sql');
        }

        $rows = post('rows', []);
        $processed = 0;
        $skipped = 0;

        foreach ($grItems as $row) {
            $grItemId = (int) $row['gr_item_id'];
            $poItemId = (int) $row['po_item_id'];

            // Only backfill unlinked PO items
            if (!empty($row['item_id'])) {
                $skipped++;
                continue;
            }

            $selectedType = trim((string)($rows[$grItemId]['item_type'] ?? ''));
            $typeForItem = in_array($selectedType, ['Device', 'Equipment', 'Vehicle', 'Consumable'], true) ? $selectedType : 'Consumable';

            // Generate item code
            $prefix = match ($typeForItem) {
                'Device' => 'DEV',
                'Equipment' => 'EQP',
                'Vehicle' => 'VEH',
                'Consumable' => 'CON',
                default => 'ITM'
            };

            $stmtMax = $db->prepare("SELECT MAX(CAST(SUBSTRING(code, :start) AS UNSIGNED)) FROM items WHERE code LIKE :prefix");
            $stmtMax->execute([
                ':start' => strlen($prefix) + 1,
                ':prefix' => $prefix . '%'
            ]);
            $next = (int) $stmtMax->fetchColumn();
            $next = $next + 1;
            $newCode = $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);

            $newName = trim((string)($row['description'] ?? ''));
            if ($newName === '') {
                $newName = $newCode;
            }

            $serials = [];
            $manualSerialText = trim((string)($rows[$grItemId]['serials'] ?? ''));
            if ($manualSerialText !== '') {
                $manualLines = preg_split("/\r\n|\n|\r/", $manualSerialText, -1, PREG_SPLIT_NO_EMPTY);
                $serials = array_values(array_filter(array_map('trim', $manualLines), fn($s) => $s !== ''));
            } elseif (!empty($row['serial_numbers'])) {
                $decoded = json_decode((string)$row['serial_numbers'], true);
                if (is_array($decoded)) {
                    $serials = array_values(array_filter(array_map('trim', $decoded), fn($s) => $s !== ''));
                }
            }

            $qtyFloat = (float) $row['received_qty'];
            $qtyInt = (int) round($qtyFloat);
            $requiresSerial = in_array($typeForItem, ['Device', 'Equipment', 'Vehicle'], true);
            if ($requiresSerial) {
                if (abs($qtyFloat - $qtyInt) > 0.00001) {
                    throw new Exception('ประเภท ' . $typeForItem . ' ต้องรับเป็นจำนวนเต็ม (GR Item #' . $grItemId . ')');
                }
                if (count($serials) !== $qtyInt) {
                    throw new Exception('ประเภท ' . $typeForItem . ' ต้องกรอก Serial/ทะเบียน ให้ครบเท่ากับจำนวนที่รับ (GR Item #' . $grItemId . ')');
                }
            }
            $isSerializedNew = count($serials) > 0 ? 1 : 0;

            // Create item master
            $costPrice = (float)($row['unit_price'] ?? 0);
            $stmtNew = $db->prepare("INSERT INTO items (code, name, item_type, unit, is_serialized, cost_price, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
            $stmtNew->execute([
                $newCode,
                $newName,
                $typeForItem,
                (string)($row['unit'] ?? 'pcs'),
                $isSerializedNew,
                $costPrice,
                $_SESSION['user_id']
            ]);
            $itemId = (int) $db->lastInsertId();

            $audit->log('create', 'ITEM', $itemId, null, ['code' => $newCode, 'type' => $typeForItem, 'source' => 'GR_BACKFILL', 'gr_id' => $grId]);

            // Link PO item (do not override if linked concurrently)
            $stmtLink = $db->prepare("UPDATE po_items SET item_id = ? WHERE id = ? AND item_id IS NULL");
            $stmtLink->execute([$itemId, $poItemId]);
            if ($stmtLink->rowCount() <= 0) {
                $skipped++;
                continue;
            }
            $audit->log('link_item', 'PO_ITEM', $poItemId, null, ['item_id' => $itemId, 'item_code' => $newCode, 'source' => 'GR_BACKFILL', 'gr_id' => $grId]);

            $notes = "GR {$gr['gr_number']} / PO {$gr['po_number']} / BACKFILL";

            if ($isSerializedNew) {
                foreach ($serials as $sn) {
                    $stmtCheck = $db->prepare("SELECT id FROM serials WHERE item_id = ? AND serial_number = ?");
                    $stmtCheck->execute([$itemId, $sn]);
                    if ($stmtCheck->fetchColumn()) {
                        throw new Exception('Serial ซ้ำในระบบ: ' . $sn);
                    }

                    $stmtIns = $db->prepare("INSERT INTO serials (item_id, serial_number, status, location, created_by) VALUES (?, ?, 'Available', 'WH', ?)");
                    $stmtIns->execute([$itemId, $sn, $_SESSION['user_id']]);
                    $serialId = (int) $db->lastInsertId();

                    $move = $warehouseService->recordMovement(
                        WarehouseService::MOVE_GR_PO,
                        $itemId,
                        1,
                        WarehouseService::LOC_SUPPLIER,
                        WarehouseService::LOC_WH,
                        'goods_receipts',
                        $grId,
                        $serialId,
                        null,
                        $notes
                    );
                    if (empty($move['success'])) {
                        throw new Exception($move['error'] ?? 'บันทึก stock movement ไม่สำเร็จ');
                    }
                }
            } else {
                // Prevent duplicates if somehow created already
                $stmtDup = $db->prepare("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'GR_PO' AND reference_table = 'goods_receipts' AND reference_id = ? AND item_id = ? AND serial_id IS NULL");
                $stmtDup->execute([$grId, $itemId]);
                $exists = (int) $stmtDup->fetchColumn();

                if ($exists === 0) {
                    $move = $warehouseService->recordMovement(
                        WarehouseService::MOVE_GR_PO,
                        $itemId,
                        (float)$row['received_qty'],
                        WarehouseService::LOC_SUPPLIER,
                        WarehouseService::LOC_WH,
                        'goods_receipts',
                        $grId,
                        null,
                        null,
                        $notes
                    );
                    if (empty($move['success'])) {
                        throw new Exception($move['error'] ?? 'บันทึก stock movement ไม่สำเร็จ');
                    }
                }
            }

            $processed++;
        }

        $db->commit();

        setFlash('success', "Backfill สำเร็จ: {$processed} รายการ (ข้าม {$skipped})");
        redirect('backfill.php?gr_id=' . $grId);

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect('backfill.php?gr_id=' . $grId);
    }
}

$pageTitle = 'GR Backfill - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-tools me-2"></i>GR Backfill</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="index.php">GR</a></li>
                    <li class="breadcrumb-item"><a href="view.php?id=<?= (int)$grId ?>"><?= e($gr['gr_number']) ?></a></li>
                    <li class="breadcrumb-item active">Backfill</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="view.php?id=<?= (int)$grId ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ GR
            </a>
        </div>
    </div>
</div>

<div class="alert alert-info">
    <div><strong>GR:</strong> <?= e($gr['gr_number']) ?> | <strong>PO:</strong> <?= e($gr['po_number']) ?> | <strong>Supplier:</strong> <?= e($gr['supplier_name']) ?></div>
    <div>รายการที่ยังไม่ผูก Item Master: <strong><?= (int)$unlinkedCount ?></strong></div>
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="backfill">

    <div class="card">
        <div class="card-header">รายการรับ (สำหรับสร้าง Item Master + Stock Movements)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>PO Item</th>
                            <th>รายละเอียด</th>
                            <th class="text-center">รับ</th>
                            <th>หน่วย</th>
                            <th>Item Master</th>
                            <th style="width: 220px;">Type (สร้างใหม่)</th>
                            <th style="width: 260px;">Serial/ทะเบียน (หนึ่งบรรทัดต่อ 1 unit)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grItems as $idx => $r): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td>#<?= (int)$r['po_item_id'] ?></td>
                            <td><?= e($r['description']) ?></td>
                            <td class="text-center"><?= formatNumber($r['received_qty'], 0) ?></td>
                            <td><?= e($r['unit']) ?></td>
                            <td>
                                <?php if (!empty($r['item_id'])): ?>
                                    <span class="badge bg-success"><?= e($r['item_code']) ?></span>
                                    <?= e($r['item_name']) ?>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">ยังไม่ผูก</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($r['item_id'])): ?>
                                    <?php
                                    // Auto-detect type from description
                                    $desc = strtolower($r['description']);
                                    $autoType = 'Consumable';
                                    if (strpos($desc, 'device') !== false) {
                                        $autoType = 'Device';
                                    } elseif (strpos($desc, 'equipment') !== false || strpos($desc, 'equip') !== false) {
                                        $autoType = 'Equipment';
                                    } elseif (strpos($desc, 'vehicle') !== false || strpos($desc, 'car') !== false || strpos($desc, 'truck') !== false) {
                                        $autoType = 'Vehicle';
                                    }
                                    ?>
                                <select class="form-select form-select-sm" name="rows[<?= (int)$r['gr_item_id'] ?>][item_type]" required>
                                    <option value="Device" <?= $autoType === 'Device' ? 'selected' : '' ?>>Device</option>
                                    <option value="Equipment" <?= $autoType === 'Equipment' ? 'selected' : '' ?>>Equipment</option>
                                    <option value="Vehicle" <?= $autoType === 'Vehicle' ? 'selected' : '' ?>>Vehicle</option>
                                    <option value="Consumable" <?= $autoType === 'Consumable' ? 'selected' : '' ?>>Consumable</option>
                                </select>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($r['item_id'])): ?>
                                    <?php
                                    $prefill = '';
                                    if (!empty($r['serial_numbers'])) {
                                        $decoded = json_decode((string)$r['serial_numbers'], true);
                                        if (is_array($decoded)) {
                                            $prefill = implode("\n", array_values(array_filter(array_map('trim', $decoded), fn($s) => $s !== '')));
                                        }
                                    }
                                    ?>
                                    <textarea class="form-control form-control-sm" name="rows[<?= (int)$r['gr_item_id'] ?>][serials]" rows="2" placeholder="SN001&#10;SN002"><?= e($prefill) ?></textarea>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="text-end mt-3">
        <button type="submit" class="btn btn-primary" <?= $unlinkedCount <= 0 ? 'disabled' : '' ?> onclick="return confirm('ยืนยัน Backfill? จะสร้าง Item Master + Stock Movements แบบ append-only')">
            <i class="bi bi-check-circle me-1"></i>Backfill
        </button>
    </div>
</form>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
