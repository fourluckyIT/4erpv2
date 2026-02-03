<?php

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../warehouse/WarehouseService.php';

$auth = new Auth();
$auth->requireAuth();

// RBAC: ADM, PUR, WH, MGR
$allowedRoles = ['ADM', 'PUR', 'WH', 'MGR'];
$userRoles = $_SESSION['roles'] ?? [];
$hasAccess = !empty(array_intersect($allowedRoles, $userRoles));
if (!$hasAccess) {
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
$missingSerialCount = 0;
$linkedCount = 0;
foreach ($grItems as $r) {
    if (empty($r['item_id'])) {
        $unlinkedCount++;
        continue;
    }
    $linkedCount++;
    $requiresSerial = ((int)($r['is_serialized'] ?? 0) === 1)
        || in_array(($r['item_type'] ?? ''), ['Device', 'Equipment', 'Vehicle'], true);
    if ($requiresSerial) {
        $existingSerials = [];
        if (!empty($r['serial_numbers'])) {
            $decoded = json_decode((string)$r['serial_numbers'], true);
            if (is_array($decoded)) {
                $existingSerials = array_values(array_filter(array_map('trim', $decoded), fn($s) => $s !== ''));
            }
        }
        $receivedQty = (int) round((float) $r['received_qty']);
        if (count($existingSerials) < $receivedQty) {
            $missingSerialCount++;
        }
    }
}

if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('backfill.php?gr_id=' . $grId);
    }

    $action = post('action');
    if (!in_array($action, ['backfill', 'recode'], true)) {
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
            $itemId = (int) ($row['item_id'] ?? 0);

            $existingSerials = [];
            if (!empty($row['serial_numbers'])) {
                $decoded = json_decode((string)$row['serial_numbers'], true);
                if (is_array($decoded)) {
                    $existingSerials = array_values(array_filter(array_map('trim', $decoded), fn($s) => $s !== ''));
                }
            }

            // Case 1: item already linked
            if (!empty($itemId)) {
                // Check if user wants to recode this item
                $newType = trim((string)($rows[$grItemId]['new_type'] ?? ''));
                $currentType = trim((string)($row['item_type'] ?? ''));
                
                if ($action === 'recode' && $newType !== '' && $newType !== $currentType && in_array($newType, ['Device', 'Equipment', 'Vehicle', 'Consumable'], true)) {
                    // Re-generate item code based on new type
                    $prefix = match ($newType) {
                        'Device' => 'DEV',
                        'Equipment' => 'EQP',
                        'Vehicle' => 'VEH',
                        'Consumable' => 'CON',
                        default => 'ITM'
                    };
                    
                    // Find highest existing code for this prefix (format: PREFIX-NNNN)
                    $stmtMax = $db->prepare("SELECT code FROM items WHERE code LIKE ? ORDER BY code DESC LIMIT 1");
                    $stmtMax->execute([$prefix . '-%']);
                    $lastCode = $stmtMax->fetchColumn();
                    $next = 1;
                    if ($lastCode) {
                        $parts = explode('-', $lastCode);
                        if (count($parts) >= 2) {
                            $next = (int) end($parts) + 1;
                        }
                    }
                    $baseCode = sprintf('%s-%04d', $prefix, $next);
                    $newCode = ensureUniqueItemCode($db, $baseCode);
                    
                    $oldCode = $row['item_code'];
                    
                    // Update item with new type and code
                    $stmtUpdate = $db->prepare("UPDATE items SET code = ?, item_type = ? WHERE id = ?");
                    $stmtUpdate->execute([$newCode, $newType, $itemId]);
                    
                    $auditData = [
                        'old_code' => $oldCode,
                        'new_code' => $newCode,
                        'old_type' => $currentType,
                        'new_type' => $newType,
                        'source' => 'GR_BACKFILL',
                        'gr_id' => $grId
                    ];
                    if ($newCode !== $baseCode) {
                        $auditData['base_code'] = $baseCode;
                    }
                    $audit->log('recode', 'ITEM', $itemId, null, $auditData);
                    
                    $processed++;
                    continue;
                }
                
                // Otherwise, only fill missing serials
                $requiresSerial = ((int)($row['is_serialized'] ?? 0) === 1)
                    || in_array(($row['item_type'] ?? ''), ['Device', 'Equipment', 'Vehicle'], true);
                if (!$requiresSerial) {
                    $skipped++;
                    continue;
                }

                $qtyFloat = (float) $row['received_qty'];
                $qtyInt = (int) round($qtyFloat);
                if (abs($qtyFloat - $qtyInt) > 0.00001) {
                    throw new Exception('รายการที่ต้องมี Serial ต้องรับเป็นจำนวนเต็ม (GR Item #' . $grItemId . ')');
                }

                $manualSerialText = trim((string)($rows[$grItemId]['serials'] ?? ''));
                $serials = [];
                if ($manualSerialText !== '') {
                    $manualLines = preg_split("/\r\n|\n|\r/", $manualSerialText, -1, PREG_SPLIT_NO_EMPTY);
                    $serials = array_values(array_filter(array_map('trim', $manualLines), fn($s) => $s !== ''));
                } else {
                    $serials = $existingSerials;
                }

                if (count($serials) !== $qtyInt) {
                    throw new Exception('รายการนี้ต้องกรอก Serial ให้ครบเท่ากับจำนวนที่รับ (GR Item #' . $grItemId . ')');
                }

                $notes = "GR {$gr['gr_number']} / PO {$gr['po_number']} / BACKFILL SERIAL";
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

                $stmtUpdate = $db->prepare("UPDATE gr_items SET serial_numbers = ? WHERE id = ?");
                $stmtUpdate->execute([json_encode($serials), $grItemId]);
                $audit->log('update', 'GR_ITEM', $grItemId, null, ['serial_numbers' => $serials]);

                $processed++;
                continue;
            }

            // Case 2: backfill unlinked PO items (create item + serials if provided)
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

            // Find highest existing code for this prefix (format: PREFIX-NNNN)
            $stmtMax = $db->prepare("SELECT code FROM items WHERE code LIKE ? ORDER BY code DESC LIMIT 1");
            $stmtMax->execute([$prefix . '-%']);
            $lastCode = $stmtMax->fetchColumn();
            $next = 1;
            if ($lastCode) {
                $parts = explode('-', $lastCode);
                if (count($parts) >= 2) {
                    $next = (int) end($parts) + 1;
                }
            }
            $baseCode = sprintf('%s-%04d', $prefix, $next);
            $newCode = ensureUniqueItemCode($db, $baseCode);

            $newName = trim((string)($row['description'] ?? ''));
            if ($newName === '') {
                $newName = $newCode;
            }

            $serials = [];
            $manualSerialText = trim((string)($rows[$grItemId]['serials'] ?? ''));
            if ($manualSerialText !== '') {
                $manualLines = preg_split("/\r\n|\n|\r/", $manualSerialText, -1, PREG_SPLIT_NO_EMPTY);
                $serials = array_values(array_filter(array_map('trim', $manualLines), fn($s) => $s !== ''));
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
            $stmtNew = $db->prepare("INSERT INTO items (code, name, item_type, unit, is_serialized, cost_price, is_active, source, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, 'GR', ?)");
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

            $auditData = ['code' => $newCode, 'type' => $typeForItem, 'source' => 'GR_BACKFILL', 'gr_id' => $grId];
            if ($newCode !== $baseCode) {
                $auditData['base_code'] = $baseCode;
            }
            $audit->log('create', 'ITEM', $itemId, null, $auditData);

            // Link PO item (do not override if linked concurrently)
            $stmtLink = $db->prepare("UPDATE po_items SET item_id = ? WHERE id = ? AND item_id IS NULL");
            $stmtLink->execute([$itemId, $poItemId]);
            if ($stmtLink->rowCount() <= 0) {
                $skipped++;
                continue;
            }
            $auditData = ['item_id' => $itemId, 'item_code' => $newCode, 'source' => 'GR_BACKFILL', 'gr_id' => $grId];
            if ($newCode !== $baseCode) {
                $auditData['base_code'] = $baseCode;
            }
            $audit->log('link_item', 'PO_ITEM', $poItemId, null, $auditData);

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

$pageTitle = 'GR Backfill - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-tools" style="color: var(--primary);"></i> GR Backfill
        </h1>
        <nav aria-label="breadcrumb" class="page-subtitle">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                <li class="breadcrumb-item"><a href="index.php">GR</a></li>
                <li class="breadcrumb-item"><a href="view.php?id=<?= (int)$grId ?>"><?= e($gr['gr_number']) ?></a></li>
                <li class="breadcrumb-item active">Backfill</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> กลับ GR
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <div><span class="text-muted">GR</span> <strong><?= e($gr['gr_number']) ?></strong></div>
            <div><span class="text-muted">PO</span> <strong><?= e($gr['po_number']) ?></strong></div>
            <div><span class="text-muted">Supplier</span> <strong><?= e($gr['supplier_name']) ?></strong></div>
        </div>
    </div>
</div>

<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-icon success"><i class="bi bi-link-45deg" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int)$linkedCount ?></div>
            <div class="stat-label">ผูก Item Master แล้ว</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="bi bi-unlink" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int)$unlinkedCount ?></div>
            <div class="stat-label">ยังไม่ผูก</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info"><i class="bi bi-upc-scan" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int)$missingSerialCount ?></div>
            <div class="stat-label">Serial ยังไม่ครบ</div>
        </div>
    </div>
</div>

<?php if ($unlinkedCount > 0 || $missingSerialCount > 0): ?>
<form method="POST" id="backfillForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="backfill">

    <div class="card">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-plus-circle text-primary"></i>
            <span>สร้าง Item Master ใหม่ + เติม Serial</span>
            <span class="badge bg-primary ms-auto"><?= $unlinkedCount + $missingSerialCount ?> รายการ</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
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
                        <?php 
                        $rowIdx = 0;
                        foreach ($grItems as $idx => $r): 
                            $requiresSerial = ((int)($r['is_serialized'] ?? 0) === 1)
                                || in_array(($r['item_type'] ?? ''), ['Device', 'Equipment', 'Vehicle'], true);
                            $serialsExisting = [];
                            if (!empty($r['serial_numbers'])) {
                                $decoded = json_decode((string)$r['serial_numbers'], true);
                                if (is_array($decoded)) {
                                    $serialsExisting = array_values(array_filter(array_map('trim', $decoded), fn($s) => $s !== ''));
                                }
                            }
                            $receivedQty = (int) round((float) $r['received_qty']);
                            $needsSerialInput = $requiresSerial && count($serialsExisting) < $receivedQty;
                            
                            // Skip items that don't need backfill
                            if (!empty($r['item_id']) && !$needsSerialInput) continue;
                            $rowIdx++;
                        ?>
                        <tr>
                            <td><?= $rowIdx ?></td>
                            <td>#<?= (int)$r['po_item_id'] ?></td>
                            <td><?= e($r['description']) ?></td>
                            <td class="text-center"><?= formatNumber($r['received_qty'], 0) ?></td>
                            <td><?= e($r['unit']) ?></td>
                            <td>
                                <?php if (!empty($r['item_id'])): ?>
                                    <span class="badge bg-success"><?= e($r['item_code']) ?></span>
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
                                <span class="badge bg-secondary"><?= e($r['item_type'] ?? '-') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($r['item_id']) || $needsSerialInput): ?>
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
</form>
<?php else: ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-check-circle-fill"></i>
    <span>ทุกรายการผูก Item Master และมี Serial ครบแล้ว</span>
</div>
<?php endif; ?>

<?php if ($linkedCount > 0): ?>
<form method="POST" id="recodeForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="recode">

    <div class="card">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-arrow-repeat text-warning"></i>
            <span>Re-Code รายการที่ผูก Item Master แล้ว (เปลี่ยนประเภท + รหัสใหม่)</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>รายละเอียด</th>
                            <th>รหัสปัจจุบัน</th>
                            <th>ประเภทปัจจุบัน</th>
                            <th style="width: 200px;">เปลี่ยนเป็นประเภท</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $recodeIdx = 0;
                        foreach ($grItems as $r): 
                            if (empty($r['item_id'])) continue;
                            $recodeIdx++;
                            $currentType = $r['item_type'] ?? 'Consumable';
                        ?>
                        <tr>
                            <td><?= $recodeIdx ?></td>
                            <td><?= e($r['description']) ?></td>
                            <td><span class="badge bg-info"><?= e($r['item_code']) ?></span></td>
                            <td><span class="badge bg-secondary"><?= e($currentType) ?></span></td>
                            <td>
                                <select class="form-select form-select-sm recode-select" name="rows[<?= (int)$r['gr_item_id'] ?>][new_type]" data-current="<?= e($currentType) ?>">
                                    <option value="">-- ไม่เปลี่ยน --</option>
                                    <option value="Device" <?= $currentType === 'Device' ? 'disabled' : '' ?>>Device (DEV)</option>
                                    <option value="Equipment" <?= $currentType === 'Equipment' ? 'disabled' : '' ?>>Equipment (EQP)</option>
                                    <option value="Vehicle" <?= $currentType === 'Vehicle' ? 'disabled' : '' ?>>Vehicle (VEH)</option>
                                    <option value="Consumable" <?= $currentType === 'Consumable' ? 'disabled' : '' ?>>Consumable (CON)</option>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</form>

<?php endif; ?>

<div class="d-flex flex-wrap gap-2 justify-content-end mt-4">
    <?php if ($unlinkedCount > 0 || $missingSerialCount > 0): ?>
    <button type="submit" form="backfillForm" class="btn btn-primary" onclick="return confirm('ยืนยัน Backfill? จะสร้าง Item Master และบันทึก Stock (append-only)')">
        <i class="bi bi-plus-circle me-1"></i>สร้าง Item + บันทึก Stock
    </button>
    <?php endif; ?>
    <?php if ($linkedCount > 0): ?>
    <button type="submit" form="recodeForm" class="btn btn-warning" id="recodeBtn" disabled onclick="return confirm('ยืนยัน Re-Code? รหัสสินค้าจะถูกเปลี่ยนตามประเภทใหม่ (เก็บ audit log)')">
        <i class="bi bi-arrow-repeat me-1"></i>Re-Code รหัสสินค้า
    </button>
    <?php endif; ?>
</div>

<?php if ($linkedCount > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const selects = document.querySelectorAll('.recode-select');
    const btn = document.getElementById('recodeBtn');

    function checkSelections() {
        let hasChange = false;
        selects.forEach(sel => {
            if (sel.value !== '' && sel.value !== sel.dataset.current) {
                hasChange = true;
            }
        });
        btn.disabled = !hasChange;
    }

    selects.forEach(sel => {
        sel.addEventListener('change', checkSelections);
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
