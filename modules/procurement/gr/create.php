<?php
/**
 * Create GR
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
$docNum = new DocumentNumber();
$warehouseService = new WarehouseService();

function normalizeItemType(?string $type): string {
    $type = strtolower(trim((string) $type));
    return match ($type) {
        'device' => 'Device',
        'equipment' => 'Equipment',
        'vehicle' => 'Vehicle',
        'consumable' => 'Consumable',
        default => '',
    };
}

function requiresSerialByType(string $type): bool {
    return in_array($type, ['Device', 'Equipment', 'Vehicle'], true);
}

function parseSerialLines(string $text): array {
    $lines = preg_split("/\r\n|\n|\r/", $text, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter(array_map('trim', $lines), fn($s) => $s !== ''));
}

$poId = (int) get('po_id');

if (!$poId) {
    setFlash('error', 'ต้องระบุ PO');
    redirect('index.php');
}

// Get PO
$stmt = $db->prepare("
    SELECT po.*, s.name as supplier_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.id = ? AND po.status IN ('Approved', 'Partially Received')
");
$stmt->execute([$poId]);
$po = $stmt->fetch();

if (!$po) {
    setFlash('error', 'ไม่พบ PO หรือสถานะไม่ถูกต้อง');
    redirect('index.php');
}
if (($po['po_type'] ?? '') === 'Manpower') {
    setFlash('error', 'PO แรงงานต้องจัดการผ่าน HR เท่านั้น');
    redirect("../po/view.php?id=$poId");
}

// Get PO items with remaining qty
$poItems = $db->prepare("
    SELECT poi.*, i.code as item_code, i.name as item_name, i.is_serialized, i.item_type as existing_item_type,
           (poi.qty - poi.received_qty) as remaining_qty
    FROM po_items poi
    LEFT JOIN items i ON poi.item_id = i.id
    WHERE poi.po_id = ? AND (poi.qty - poi.received_qty) > 0
");
$poItems->execute([$poId]);
$poItems = $poItems->fetchAll();

$poItemMap = [];
foreach ($poItems as $row) {
    $poItemMap[(int)$row['id']] = $row;
}

if (empty($poItems)) {
    setFlash('info', 'PO นี้รับของครบแล้ว');
    redirect("../po/view.php?id=$poId");
}

$existingItems = $db->query("
    SELECT id, code, name, item_type, is_serialized
    FROM items
    WHERE is_active = 1
    ORDER BY item_type, code
")->fetchAll();

// Handle form submission
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect("create.php?po_id=$poId");
    }
    
    try {
        $db->beginTransaction();
        
        // Generate GR number
        $grNumber = $docNum->generate('GR');
        
        // Insert GR
        $stmt = $db->prepare("
            INSERT INTO goods_receipts (gr_number, po_id, received_date, received_by, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $grNumber,
            $poId,
            post('received_date'),
            $_SESSION['user_id'],
            post('notes'),
            $_SESSION['user_id']
        ]);
        
        $grId = $db->lastInsertId();
        
        // Insert GR items and update PO items
        $items = post('items', []);
        $hasItems = false;
        
        foreach ($items as $poItemId => $item) {
            $receivedQty = (float) ($item['received_qty'] ?? 0);
            if ($receivedQty <= 0) continue;
            
            $hasItems = true;

            $poItemId = (int) $poItemId;
            $poItemRow = $poItemMap[$poItemId] ?? null;
            if (!$poItemRow) {
                throw new Exception('ไม่พบรายการ PO Item: ' . $poItemId);
            }

            $serialInput = trim((string) ($item['serials'] ?? ''));
            $serialLines = parseSerialLines($serialInput);
            if (count($serialLines) !== count(array_unique($serialLines))) {
                throw new Exception('Serial ซ้ำในฟอร์ม');
            }

            $selectedType = normalizeItemType($item['item_type'] ?? '');
            if ($selectedType === '') {
                $selectedType = normalizeItemType($poItemRow['existing_item_type'] ?? '');
            }

            $itemId = (int) ($poItemRow['item_id'] ?? 0);
            $existingIsSerialized = (int) ($poItemRow['is_serialized'] ?? 0);

            $linkItemId = (int) ($item['link_item_id'] ?? 0);
            if ($itemId > 0 && $linkItemId > 0 && $linkItemId !== $itemId) {
                throw new Exception('รายการนี้เชื่อมกับสินค้าอยู่แล้ว');
            }

            if ($itemId <= 0 && $linkItemId > 0) {
                $stmtLink = $db->prepare("SELECT id, item_type, is_serialized, is_active FROM items WHERE id = ?");
                $stmtLink->execute([$linkItemId]);
                $linkedItem = $stmtLink->fetch(PDO::FETCH_ASSOC);
                if (!$linkedItem || (int) ($linkedItem['is_active'] ?? 0) !== 1) {
                    throw new Exception('สินค้าที่เลือกไม่พร้อมใช้งาน');
                }
                $linkedType = normalizeItemType($linkedItem['item_type'] ?? '');
                if ($selectedType === '') {
                    $selectedType = $linkedType;
                }
                if ($selectedType !== '' && $linkedType !== '' && $selectedType !== $linkedType) {
                    throw new Exception('ประเภทสินค้าไม่ตรงกับสินค้าที่เลือก');
                }
                $itemId = (int) $linkedItem['id'];
                $existingIsSerialized = (int) ($linkedItem['is_serialized'] ?? 0);
                $db->prepare("UPDATE po_items SET item_id = ? WHERE id = ? AND item_id IS NULL")->execute([$itemId, $poItemId]);
                $audit->log('link_item', 'PO_ITEM', $poItemId, null, ['item_id' => $itemId, 'source' => 'link_existing']);
            }

            if ($itemId > 0) {
                $stmtType = $db->prepare("SELECT item_type, is_serialized FROM items WHERE id = ?");
                $stmtType->execute([$itemId]);
                $rowType = $stmtType->fetch(PDO::FETCH_ASSOC);
                if ($rowType) {
                    $dbType = normalizeItemType($rowType['item_type'] ?? '');
                    if ($selectedType !== '' && $dbType !== '' && $selectedType !== $dbType) {
                        throw new Exception('ประเภทสินค้าไม่ตรงกับสินค้าที่เลือก');
                    }
                    if ($selectedType === '') {
                        $selectedType = $dbType;
                    }
                    $existingIsSerialized = (int) ($rowType['is_serialized'] ?? $existingIsSerialized);
                }
            }

            if ($selectedType === '') {
                throw new Exception('กรุณาเลือกประเภทสินค้า');
            }

            $requiresSerial = requiresSerialByType($selectedType) || $existingIsSerialized === 1;
            if ($selectedType === 'Consumable' && !empty($serialLines)) {
                throw new Exception('วัสดุสิ้นเปลืองไม่ต้องมี Serial');
            }
            if ($requiresSerial) {
                $expectedCount = (int) round($receivedQty);
                if (abs($receivedQty - $expectedCount) > 0.00001) {
                    throw new Exception('สินค้า Serial ต้องระบุจำนวนเป็นจำนวนเต็ม');
                }
                if (count($serialLines) !== $expectedCount) {
                    throw new Exception('สินค้า Serial ต้องกรอก Serial ให้ครบเท่ากับจำนวนที่รับ');
                }
                foreach ($serialLines as $sn) {
                    $stmtCheck = $db->prepare("SELECT item_id FROM serials WHERE serial_number = ?");
                    $stmtCheck->execute([$sn]);
                    if ($stmtCheck->fetchColumn()) {
                        throw new Exception('Serial ซ้ำในระบบ: ' . $sn);
                    }
                }
            }

            // Insert GR item
            $stmt = $db->prepare("
                INSERT INTO gr_items (gr_id, po_item_id, received_qty, serial_numbers, condition_note)
                VALUES (?, ?, ?, ?, ?)
            ");
            $serials = !empty($serialLines) ? json_encode($serialLines) : null;
            $stmt->execute([
                $grId,
                $poItemId,
                $receivedQty,
                $serials,
                $item['condition_note'] ?? null
            ]);
            
            // Update PO item received_qty
            $db->prepare("
                UPDATE po_items SET received_qty = received_qty + ? WHERE id = ?
            ")->execute([$receivedQty, $poItemId]);

            $notes = "GR {$grNumber} / PO {$po['po_number']}" . ($selectedType ? " / {$selectedType}" : '');

            if ($itemId <= 0) {
                $typeForItem = in_array($selectedType, ['Device', 'Equipment', 'Vehicle', 'Consumable'], true) ? $selectedType : 'Consumable';

                $docType = match ($typeForItem) {
                    'Device' => 'DEV',
                    'Equipment' => 'EQP',
                    'Vehicle' => 'VEH',
                    'Consumable' => 'CON',
                    default => 'CON'
                };

                $newCode = null;
                for ($i = 0; $i < 5; $i++) {
                    $candidate = $docNum->generate($docType);
                    $check = $db->prepare("SELECT 1 FROM items WHERE code = ?");
                    $check->execute([$candidate]);
                    if (!$check->fetchColumn()) {
                        $newCode = $candidate;
                        break;
                    }
                }
                if ($newCode === null) {
                    throw new Exception('ไม่สามารถสร้างรหัสได้');
                }
                $baseCode = $newCode;

                $newName = trim((string)($poItemRow['description'] ?? ''));
                if ($newName === '') {
                    $newName = $newCode;
                }

                if ($requiresSerial && empty($serialLines)) {
                    throw new Exception('สินค้า ' . $typeForItem . ' ต้องระบุ Serial');
                }
                $isSerializedNew = $requiresSerial ? 1 : 0;

                $costPrice = (float)($poItemRow['unit_price'] ?? 0);
                $stmtNew = $db->prepare("INSERT INTO items (code, name, item_type, unit, is_serialized, cost_price, is_active, source, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, 'GR', ?)");
                $stmtNew->execute([
                    $newCode,
                    $newName,
                    $typeForItem,
                    (string)($poItemRow['unit'] ?? 'pcs'),
                    $isSerializedNew,
                    $costPrice,
                    $_SESSION['user_id']
                ]);
                $itemId = (int) $db->lastInsertId();

                $db->prepare("UPDATE po_items SET item_id = ? WHERE id = ?")->execute([$itemId, $poItemId]);
                $auditData = ['item_id' => $itemId, 'item_code' => $newCode];
                if ($newCode !== $baseCode) {
                    $auditData['base_code'] = $baseCode;
                }
                $audit->log('link_item', 'PO_ITEM', $poItemId, null, $auditData);
            }

            if ($itemId > 0) {
                $isSerialized = $existingIsSerialized === 1;
                if (!$isSerialized) {
                    try {
                        $stmtSerFlag = $db->prepare("SELECT is_serialized FROM items WHERE id = ?");
                        $stmtSerFlag->execute([$itemId]);
                        $isSerialized = ((int)$stmtSerFlag->fetchColumn()) === 1;
                    } catch (Exception $e) {
                        $isSerialized = false;
                    }
                }
                if ($requiresSerial && !$isSerialized) {
                    $db->prepare("UPDATE items SET is_serialized = 1 WHERE id = ?")->execute([$itemId]);
                    $isSerialized = true;
                }

                if ($isSerialized) {
                    foreach ($serialLines as $sn) {
                        $stmtCheck = $db->prepare("SELECT item_id FROM serials WHERE serial_number = ?");
                        $stmtCheck->execute([$sn]);
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
                            (int) $grId,
                            $serialId,
                            null,
                            $notes
                        );
                        if (empty($move['success'])) {
                            throw new Exception($move['error'] ?? 'บันทึก stock movement ไม่สำเร็จ');
                        }
                    }
                } else {
                    $move = $warehouseService->recordMovement(
                        WarehouseService::MOVE_GR_PO,
                        $itemId,
                        $receivedQty,
                        WarehouseService::LOC_SUPPLIER,
                        WarehouseService::LOC_WH,
                        'goods_receipts',
                        (int) $grId,
                        null,
                        null,
                        $notes
                    );
                    if (empty($move['success'])) {
                        throw new Exception($move['error'] ?? 'บันทึก stock movement ไม่สำเร็จ');
                    }
                }
            }
        }
        
        if (!$hasItems) {
            throw new Exception('กรุณาระบุจำนวนที่รับอย่างน้อย 1 รายการ');
        }
        
        // Check if PO is fully received
        $remaining = $db->prepare("
            SELECT SUM(qty - received_qty) as remaining FROM po_items WHERE po_id = ?
        ");
        $remaining->execute([$poId]);
        $remainingQty = $remaining->fetchColumn();
        
        $newStatus = $remainingQty > 0 ? 'Partially Received' : 'Received';
        $db->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?")->execute([$newStatus, $poId]);
        
        $db->commit();
        
        $audit->log('create', 'GR', $grId, null, ['gr_number' => $grNumber, 'po_id' => $poId]);

        $recipients = $rbac->getUserIdsWithPermission('view', 'PO', $newStatus);
        $extraUsers = array_filter([
            $po['created_by'] ?? null,
            $po['submitted_by'] ?? null,
            $po['approved_by'] ?? null,
            $_SESSION['user_id'] ?? null
        ], fn($v) => !empty($v));
        $recipients = array_values(array_unique(array_merge($recipients, array_map('intval', $extraUsers))));
        if (!empty($recipients)) {
            $title = "GR {$grNumber} รับสินค้าแล้ว";
            $message = "PO {$po['po_number']} / Supplier: {$po['supplier_name']}";
            $url = "/4erpv2/modules/procurement/gr/view.php?id={$grId}";
            $notification->createBulk(
                $recipients,
                Notification::TYPE_SYSTEM,
                $title,
                $message,
                $url,
                'GR',
                (int) $grId,
                Notification::PRIORITY_NORMAL
            );
        }
        
        setFlash('success', "สร้าง GR เรียบร้อย: $grNumber");
        redirect("view.php?id=$grId");
        
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect("create.php?po_id=$poId");
    }
}

// Get item types for dropdown
try {
    $itemTypes = $db->query("SELECT code, name FROM item_types WHERE is_active = 1 ORDER BY planning_tab_order")->fetchAll();
} catch (PDOException $e) {
    // Fallback if item_types table doesn't exist yet
    $itemTypes = [];
}

if (empty($itemTypes)) {
    $itemTypes = [
        ['code' => 'Device', 'name' => 'อุปกรณ์ (Device)'],
        ['code' => 'Equipment', 'name' => 'เครื่องมือ (Equipment)'],
        ['code' => 'Vehicle', 'name' => 'ยานพาหนะ (Vehicle)'],
        ['code' => 'Consumable', 'name' => 'วัสดุสิ้นเปลือง (Consumable)'],
    ];
}

$pageTitle = 'รับสินค้า - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-box-arrow-in-down me-2"></i>รับสินค้า (GR)</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="../">Procurement</a></li>
                    <li class="breadcrumb-item"><a href="index.php">GR</a></li>
                    <li class="breadcrumb-item active">สร้าง</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="javascript:history.back()" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>กลับ PO
            </a>
        </div>
    </div>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    รับสินค้าจาก PO: <strong><?= e($po['po_number']) ?></strong> - <?= e($po['supplier_name']) ?>
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    
    <div class="card mb-4">
        <div class="card-header">ข้อมูลการรับ</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label">วันที่รับ <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="received_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <input type="text" class="form-control" name="notes">
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-header">รายการรับ</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>รายการ</th>
                            <th class="text-center">สั่ง</th>
                            <th class="text-center">รับแล้ว</th>
                            <th class="text-center">คงเหลือ</th>
                            <th class="text-center" style="width: 100px;">รับครั้งนี้</th>
                            <th style="width: 180px;">ประเภท</th>
                            <th style="width: 220px;">ผูกกับสินค้าเดิม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($poItems as $item): ?>
                        <?php
                            $currentType = normalizeItemType($item['existing_item_type'] ?? '');
                            $requiresSerialRow = requiresSerialByType($currentType) || (int) ($item['is_serialized'] ?? 0) === 1;
                            $hasLinkedItem = !empty($item['item_id']);
                        ?>
                        <tr>
                            <td>
                                <?php if ($item['item_code']): ?>
                                <small class="text-muted"><?= e($item['item_code']) ?></small><br>
                                <?php endif; ?>
                                <?= e($item['description']) ?>
                                <?php if ($requiresSerialRow): ?>
                                <span class="badge bg-info">Serial</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= formatNumber($item['qty'], 0) ?></td>
                            <td class="text-center"><?= formatNumber($item['received_qty'], 0) ?></td>
                            <td class="text-center">
                                <span class="badge bg-warning text-dark"><?= formatNumber($item['remaining_qty'], 0) ?></span>
                            </td>
                            <td>
                                <input type="number" class="form-control form-control-sm text-center" 
                                       name="items[<?= $item['id'] ?>][received_qty]" 
                                       value="<?= $item['remaining_qty'] ?>" 
                                       min="0" max="<?= $item['remaining_qty'] ?>" step="0.01">
                            </td>
                            <td>
                                <?php // Pre-select based on existing item_type or default to empty ?>
                                <select class="form-select form-select-sm js-item-type" data-po-item="<?= $item['id'] ?>" name="items[<?= $item['id'] ?>][item_type]" required <?= $hasLinkedItem ? 'disabled' : '' ?>>
                                    <option value="">-- เลือกประเภท --</option>
                                    <?php foreach ($itemTypes as $t): ?>
                                    <option value="<?= e($t['code']) ?>" <?= $currentType === $t['code'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ($hasLinkedItem): ?>
                                    <div class="small text-muted">ผูกแล้ว</div>
                                    <div class="fw-semibold"><?= e($item['item_code'] ?? '-') ?></div>
                                    <div class="text-muted small"><?= e($item['item_name'] ?? '') ?></div>
                                <?php else: ?>
                                <select class="form-select form-select-sm js-link-item" data-po-item="<?= $item['id'] ?>" name="items[<?= $item['id'] ?>][link_item_id]">
                                    <option value="">-- สร้างใหม่ --</option>
                                    <?php foreach ($existingItems as $ex): ?>
                                        <?php $exType = normalizeItemType($ex['item_type'] ?? ''); ?>
                                        <option value="<?= (int) $ex['id'] ?>" data-type="<?= e($exType) ?>" data-serialized="<?= (int) ($ex['is_serialized'] ?? 0) ?>">
                                            <?= e($ex['code']) ?> - <?= e($ex['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="<?= $requiresSerialRow ? '' : 'd-none' ?>" data-serial-row="<?= $item['id'] ?>">
                            <td colspan="7" class="bg-light">
                                <div class="row">
                                    <div class="col-md-8">
                                        <small class="text-muted">Serial Numbers (หนึ่ง serial ต่อบรรทัด):</small>
                                        <textarea class="form-control form-control-sm mt-1" 
                                                  name="items[<?= $item['id'] ?>][serials]" 
                                                  rows="2" placeholder="SN001&#10;SN002"></textarea>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted">หมายเหตุสภาพ:</small>
                                        <input type="text" class="form-control form-control-sm mt-1" 
                                               name="items[<?= $item['id'] ?>][condition_note]" 
                                               placeholder="สภาพ...">
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr class="d-none" data-serial-placeholder="<?= $item['id'] ?>">
                            <td colspan="7">
                                <input type="hidden" name="items[<?= $item['id'] ?>][condition_note]" value="">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="text-end">
        <button type="submit" class="btn btn-success btn-lg">
            <i class="bi bi-check-circle me-1"></i>บันทึกการรับ
        </button>
    </div>
</form>

<script>
(function () {
    const typeSelects = document.querySelectorAll('.js-item-type');
    const linkSelects = document.querySelectorAll('.js-link-item');

    const requiresSerial = (type) => ['device', 'equipment', 'vehicle'].includes((type || '').toLowerCase());

    function updateRow(poItemId) {
        const typeSelect = document.querySelector(`.js-item-type[data-po-item="${poItemId}"]`);
        const linkSelect = document.querySelector(`.js-link-item[data-po-item="${poItemId}"]`);
        const serialRow = document.querySelector(`tr[data-serial-row="${poItemId}"]`);

        let type = typeSelect ? typeSelect.value : '';
        let serializedByItem = false;

        if (linkSelect) {
            const selected = linkSelect.selectedOptions[0];
            const linkedType = selected ? (selected.dataset.type || '') : '';
            const linkedSerialized = selected ? (selected.dataset.serialized === '1') : false;

            if (linkSelect.value) {
                if (linkedType && typeSelect && typeSelect.value !== linkedType) {
                    typeSelect.value = linkedType;
                }
                if (typeSelect) {
                    typeSelect.disabled = true;
                }
                type = linkedType || type;
                serializedByItem = linkedSerialized;
            } else if (typeSelect) {
                typeSelect.disabled = false;
            }

            if (type) {
                linkSelect.querySelectorAll('option[data-type]').forEach((opt) => {
                    opt.disabled = opt.dataset.type && opt.dataset.type !== type;
                });
            } else {
                linkSelect.querySelectorAll('option[data-type]').forEach((opt) => {
                    opt.disabled = false;
                });
            }

            if (linkSelect.value) {
                const selectedOpt = linkSelect.selectedOptions[0];
                if (selectedOpt && selectedOpt.disabled) {
                    linkSelect.value = '';
                    serializedByItem = false;
                    if (typeSelect) {
                        typeSelect.disabled = false;
                    }
                }
            }
        }

        const showSerial = requiresSerial(type) || serializedByItem;
        if (serialRow) {
            serialRow.classList.toggle('d-none', !showSerial);
        }
    }

    typeSelects.forEach((select) => {
        select.addEventListener('change', () => updateRow(select.dataset.poItem));
    });
    linkSelects.forEach((select) => {
        select.addEventListener('change', () => updateRow(select.dataset.poItem));
    });
    typeSelects.forEach((select) => updateRow(select.dataset.poItem));
})();
</script>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
