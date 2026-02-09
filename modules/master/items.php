<?php
/**
 * Item Management
 * 4ERP - Phase 3
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
$docNum = new DocumentNumber();
$action = get('action', 'list');
$id = (int) get('id');
$typeFilter = get('type', '');
$canDeactivate = $auth->isAdmin();
$maintenanceRoles = [ROLE_WAREHOUSE, ROLE_MANAGER, ROLE_ADMIN, ROLE_PLANNER];
$canMaintain = !empty(array_intersect($_SESSION['roles'] ?? [], $maintenanceRoles));
$canSerialManage = $auth->isAdmin() || $auth->hasRole(ROLE_WAREHOUSE);
$maintenanceEnabled = false;
$maintenanceSchemaReady = false;
try {
    $colCheck = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME = 'maintenance_required'
    ");
    $colCheck->execute();
    $hasItemMaintenance = ((int) $colCheck->fetchColumn()) > 0;

    $tableCheck = $db->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_maintenance_logs'
    ");
    $tableCheck->execute();
    $hasLogTable = ((int) $tableCheck->fetchColumn()) > 0;

    $maintenanceSchemaReady = $hasItemMaintenance;
    $maintenanceEnabled = $hasItemMaintenance && $hasLogTable;
} catch (Exception $e) {
    $maintenanceEnabled = false;
    $maintenanceSchemaReady = false;
}

function computeNextMaintenanceDate(?string $lastDate, ?int $intervalDays): ?string {
    if (!$lastDate || !$intervalDays || $intervalDays <= 0) {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $lastDate);
    if (!$dt) {
        return null;
    }
    $dt->modify('+' . $intervalDays . ' days');
    return $dt->format('Y-m-d');
}

// Handle form submissions
if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('items.php');
    }
    
    $formAction = post('form_action');

    if (in_array($formAction, ['maintenance_ack', 'maintenance_schedule', 'maintenance_complete'], true)) {
        if (!$canMaintain) {
            setFlash('error', 'คุณไม่มีสิทธิ์ดำเนินการ Maintenance');
            redirect('items.php');
        }
        if (!$maintenanceEnabled) {
            setFlash('error', 'ระบบ Maintenance ยังไม่พร้อมใช้งาน (กรุณารัน schema_patch_item_maintenance.sql)');
            redirect('items.php');
        }
        $id = (int) post('id');
        $stmt = $db->prepare("SELECT * FROM items WHERE id = ?");
        $stmt->execute([$id]);
        $itemRow = $stmt->fetch();
        if (!$itemRow) {
            setFlash('error', 'ไม่พบข้อมูล');
            redirect('items.php');
        }

        $today = date('Y-m-d');
        $intervalDays = (int) ($itemRow['maintenance_interval_days'] ?? 0);
        $lastDate = $itemRow['maintenance_last_date'] ?? null;
        $nextDate = $itemRow['maintenance_next_date'] ?? computeNextMaintenanceDate($lastDate, $intervalDays);

        if ($formAction === 'maintenance_ack') {
            $ackUntil = $itemRow['maintenance_ack_until'];
            if ($ackUntil && $ackUntil >= $today) {
                $ackUntilDate = new DateTime($ackUntil);
                $ackUntilDate->modify('+7 days');
                $newAckUntil = $ackUntilDate->format('Y-m-d');
            } else {
                $newAckUntil = date('Y-m-d', strtotime($today . ' +7 days'));
            }

            $db->prepare("
                UPDATE items
                SET maintenance_ack_at = NOW(),
                    maintenance_ack_by = ?,
                    maintenance_ack_until = ?
                WHERE id = ?
            ")->execute([$_SESSION['user_id'], $newAckUntil, $id]);

            $db->prepare("
                INSERT INTO item_maintenance_logs (item_id, action, due_date, scheduled_date, scheduled_hours, notes, created_by)
                VALUES (?, 'ACK', ?, NULL, NULL, ?, ?)
            ")->execute([$id, $nextDate, 'ACK: Snooze 7 days', $_SESSION['user_id']]);

            $audit->log('maintenance_ack', 'ITEM', $id, null, ['ack_until' => $newAckUntil]);
            setFlash('success', 'ACK เรียบร้อย (เลื่อนไปอีก 7 วัน)');
            redirect('items.php?action=edit&id=' . $id);
        }

        if ($formAction === 'maintenance_schedule') {
            $scheduledDate = post('scheduled_date');
            $scheduledHours = (float) post('scheduled_hours', 0);
            if (!$scheduledDate) {
                setFlash('error', 'กรุณาระบุวันที่ซ่อมบำรุง');
                redirect('items.php?action=edit&id=' . $id);
            }

            // Block scheduling if conflicts with existing plans
            $conflictStmt = $db->prepare("
                SELECT p.plan_number, p.plan_date, p.plan_end_date
                FROM plan_assignments pa
                JOIN plans p ON pa.plan_id = p.id
                LEFT JOIN serials s ON pa.serial_id = s.id
                WHERE p.status IN ('Draft', 'Confirmed')
                  AND (pa.item_id = ? OR s.item_id = ?)
                  AND p.plan_date <= ?
                  AND p.plan_end_date >= ?
                LIMIT 1
            ");
            $conflictStmt->execute([$id, $id, $scheduledDate, $scheduledDate]);
            $conflict = $conflictStmt->fetch();
            if ($conflict) {
                setFlash('error', 'วันซ่อมทับกับแผนงาน: ' . $conflict['plan_number']);
                redirect('items.php?action=edit&id=' . $id);
            }

            $db->prepare("
                UPDATE items
                SET maintenance_scheduled_date = ?,
                    maintenance_scheduled_hours = ?,
                    maintenance_ack_at = NOW(),
                    maintenance_ack_by = ?,
                    maintenance_ack_until = ?
                WHERE id = ?
            ")->execute([
                $scheduledDate,
                $scheduledHours > 0 ? $scheduledHours : null,
                $_SESSION['user_id'],
                $scheduledDate,
                $id
            ]);

            $db->prepare("
                INSERT INTO item_maintenance_logs (item_id, action, due_date, scheduled_date, scheduled_hours, notes, created_by)
                VALUES (?, 'SCHEDULE', ?, ?, ?, ?, ?)
            ")->execute([
                $id,
                $nextDate,
                $scheduledDate,
                $scheduledHours > 0 ? $scheduledHours : null,
                sanitize(post('notes', '')),
                $_SESSION['user_id']
            ]);

            $audit->log('maintenance_schedule', 'ITEM', $id, null, ['scheduled_date' => $scheduledDate, 'hours' => $scheduledHours]);
            setFlash('success', 'บันทึกกำหนดการซ่อมบำรุงเรียบร้อย');
            redirect('items.php?action=edit&id=' . $id);
        }

        if ($formAction === 'maintenance_complete') {
            $completedDate = post('completed_date') ?: $today;
            $completedHours = (float) post('completed_hours', 0);
            if ($intervalDays <= 0) {
                setFlash('error', 'ยังไม่ได้กำหนดรอบ Maintenance');
                redirect('items.php?action=edit&id=' . $id);
            }

            $newNext = computeNextMaintenanceDate($completedDate, $intervalDays);

            $db->prepare("
                UPDATE items
                SET maintenance_last_date = ?,
                    maintenance_next_date = ?,
                    maintenance_ack_at = NULL,
                    maintenance_ack_by = NULL,
                    maintenance_ack_until = NULL,
                    maintenance_scheduled_date = NULL,
                    maintenance_scheduled_hours = NULL,
                    maintenance_last_notified = NULL
                WHERE id = ?
            ")->execute([$completedDate, $newNext, $id]);

            $db->prepare("
                INSERT INTO item_maintenance_logs (item_id, action, due_date, scheduled_date, scheduled_hours, completed_date, notes, created_by)
                VALUES (?, 'COMPLETE', ?, NULL, ?, ?, ?, ?)
            ")->execute([
                $id,
                $nextDate,
                $completedHours > 0 ? $completedHours : null,
                $completedDate,
                sanitize(post('notes', '')),
                $_SESSION['user_id']
            ]);

            $audit->log('maintenance_complete', 'ITEM', $id, null, ['completed_date' => $completedDate]);
            setFlash('success', 'บันทึกการซ่อมบำรุงเรียบร้อย');
            redirect('items.php?action=edit&id=' . $id);
        }
    }

    if ($formAction === 'add_serials') {
        if (!$canSerialManage) {
            setFlash('error', 'คุณไม่มีสิทธิ์เพิ่ม Serial');
            redirect('items.php');
        }
        $id = (int) post('id');
        $serialText = trim((string) post('serial_numbers', ''));
        if ($serialText === '') {
            setFlash('error', 'กรุณากรอก Serial อย่างน้อย 1 รายการ');
            redirect('items.php?action=edit&id=' . $id);
        }
        $stmt = $db->prepare("SELECT item_type, is_serialized FROM items WHERE id = ?");
        $stmt->execute([$id]);
        $itemRow = $stmt->fetch();
        if (!$itemRow) {
            setFlash('error', 'ไม่พบข้อมูล');
            redirect('items.php');
        }

        $itemType = strtolower((string) ($itemRow['item_type'] ?? ''));
        $isSerialized = (int) ($itemRow['is_serialized'] ?? 0);
        if ($itemType === 'consumable') {
            setFlash('error', 'วัสดุสิ้นเปลืองไม่ต้องมี Serial');
            redirect('items.php?action=edit&id=' . $id);
        }
        if ($isSerialized !== 1) {
            $db->prepare("UPDATE items SET is_serialized = 1 WHERE id = ?")->execute([$id]);
        }

        $lines = preg_split("/\r\n|\n|\r/", $serialText, -1, PREG_SPLIT_NO_EMPTY);
        $serials = array_values(array_filter(array_map('trim', $lines), fn($s) => $s !== ''));
        if (count($serials) !== count(array_unique($serials))) {
            setFlash('error', 'Serial ซ้ำในฟอร์ม');
            redirect('items.php?action=edit&id=' . $id);
        }

        if ($itemType === 'vehicle') {
            if (count($serials) !== 1) {
                setFlash('error', 'Vehicle ต้องมี Serial/ทะเบียนเพียง 1 รายการ');
                redirect('items.php?action=edit&id=' . $id);
            }
            $check = $db->prepare("SELECT COUNT(*) FROM serials WHERE item_id = ?");
            $check->execute([$id]);
            if ((int) $check->fetchColumn() > 0) {
                setFlash('error', 'Vehicle มี Serial แล้ว');
                redirect('items.php?action=edit&id=' . $id);
            }
        }

        $created = 0;
        foreach ($serials as $sn) {
            $check = $db->prepare("SELECT item_id FROM serials WHERE serial_number = ?");
            $check->execute([$sn]);
            if ($check->fetchColumn()) {
                setFlash('error', 'Serial ซ้ำในระบบ: ' . $sn);
                redirect('items.php?action=edit&id=' . $id);
            }
            $stmt = $db->prepare("
                INSERT INTO serials (item_id, serial_number, status, location, created_by)
                VALUES (?, ?, 'Available', 'WH', ?)
            ");
            $stmt->execute([$id, $sn, $_SESSION['user_id']]);
            $serialId = (int) $db->lastInsertId();
            $audit->log('create', 'SERIAL', $serialId, null, ['serial_number' => $sn, 'item_id' => $id]);
            $created++;
        }
        if ($created === 0) {
            setFlash('info', 'ไม่พบ Serial ใหม่ที่เพิ่มได้');
        } else {
            setFlash('success', "เพิ่ม Serial สำเร็จ {$created} รายการ");
        }
        redirect('items.php?action=edit&id=' . $id);
    }

    if ($formAction === 'deactivate') {
        if (!$canDeactivate) {
            setFlash('error', 'คุณไม่มีสิทธิ์ปิดใช้งานรายการนี้');
            redirect('items.php');
        }
        $id = (int) post('id');
        $stmt = $db->prepare("SELECT id, code, is_active FROM items WHERE id = ?");
        $stmt->execute([$id]);
        $itemRow = $stmt->fetch();
        if (!$itemRow) {
            setFlash('error', 'ไม่พบข้อมูล');
            redirect('items.php');
        }
        if ((int) $itemRow['is_active'] === 0) {
            setFlash('info', 'รายการนี้ถูกปิดใช้งานแล้ว');
            redirect('items.php');
        }

        $db->prepare("UPDATE items SET is_active = 0 WHERE id = ?")->execute([$id]);
        $audit->log('deactivate', 'ITEM', $id, ['is_active' => 1], ['is_active' => 0], 'Deactivate item');
        setFlash('success', 'ปิดใช้งานเรียบร้อย: ' . $itemRow['code']);
        redirect('items.php');
    } elseif ($formAction === 'create') {
        $itemType = post('item_type');
        $docTypes = [
            'Device' => 'DEV',
            'Equipment' => 'EQP',
            'Vehicle' => 'VEH',
            'Consumable' => 'CON',
        ];
        $docType = $docTypes[$itemType] ?? null;
        if ($docType === null) {
            setFlash('error', 'ประเภทสินค้าไม่ถูกต้อง');
            redirect('items.php?action=add');
        }

        $code = null;
        for ($i = 0; $i < 5; $i++) {
            $candidate = $docNum->generate($docType);
            $check = $db->prepare("SELECT id FROM items WHERE code = ?");
            $check->execute([$candidate]);
            if (!$check->fetch()) {
                $code = $candidate;
                break;
            }
        }
        if ($code === null) {
            setFlash('error', 'ไม่สามารถสร้างรหัสสินค้าได้');
            redirect('items.php?action=add');
        }
        $vehicleSerial = trim(post('vehicle_serial_number', ''));
        $maintenanceRequired = 0;
        $maintenanceInterval = null;
        $maintenanceLastDate = null;
        $maintenanceNextDate = null;
        if ($maintenanceSchemaReady) {
            $maintenanceRequired = post('maintenance_required') ? 1 : 0;
            if ($itemType === 'Device') {
                $maintenanceRequired = 1;
            }
            $maintenanceInterval = (int) post('maintenance_interval_days', 0);
            $maintenanceLastDate = post('maintenance_last_date') ?: null;
            if ($maintenanceRequired && $maintenanceInterval <= 0) {
                setFlash('error', 'กรุณาระบุรอบ Maintenance');
                redirect('items.php?action=add');
            }
            if ($maintenanceRequired && !$maintenanceLastDate) {
                $maintenanceLastDate = date('Y-m-d');
            }
            $maintenanceNextDate = computeNextMaintenanceDate($maintenanceLastDate, $maintenanceInterval);
        }

        if ($itemType === 'Vehicle') {
            if ($vehicleSerial === '') {
                setFlash('error', 'กรุณากรอกทะเบียนรถ');
                redirect('items.php?action=add');
            }
            $checkSerial = $db->prepare("SELECT id FROM serials WHERE serial_number = ?");
            $checkSerial->execute([$vehicleSerial]);
            if ($checkSerial->fetch()) {
                setFlash('error', 'ทะเบียน/Serial ซ้ำ');
                redirect('items.php?action=add');
            }
        }
        
        try {
            $db->beginTransaction();

            $isSerialized = post('is_serialized') ? 1 : 0;
            if ($itemType === 'Vehicle') {
                $isSerialized = 1;
            }
            if ($itemType === 'Device') {
                $isSerialized = 1;
            }
            if ($itemType === 'Equipment') {
                $isSerialized = 1;
            }
            if ($itemType === 'Consumable') {
                $isSerialized = 0;
            }

            if ($maintenanceSchemaReady) {
                $stmt = $db->prepare("
                    INSERT INTO items (code, name, item_type, category, brand, model, description, unit, is_serialized,
                                       maintenance_required, maintenance_interval_days, maintenance_last_date, maintenance_next_date,
                                       min_stock, cost_price, rental_price_day, sale_price, supplier_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $code, sanitize(post('name')), $itemType,
                    sanitize(post('category')), sanitize(post('brand')), sanitize(post('model')),
                    post('description'), sanitize(post('unit', $itemType === 'Vehicle' ? 'คัน' : 'pcs')),
                    $isSerialized,
                    $maintenanceRequired, $maintenanceInterval ?: null, $maintenanceLastDate, $maintenanceNextDate,
                    (int) post('min_stock', 0),
                    (float) post('cost_price', 0), (float) post('rental_price_day', 0),
                    (float) post('sale_price', 0),
                    post('supplier_id') ?: null, $_SESSION['user_id']
                ]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO items (code, name, item_type, category, brand, model, description, unit, is_serialized, min_stock, cost_price, rental_price_day, sale_price, supplier_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $code, sanitize(post('name')), $itemType,
                    sanitize(post('category')), sanitize(post('brand')), sanitize(post('model')),
                    post('description'), sanitize(post('unit', $itemType === 'Vehicle' ? 'คัน' : 'pcs')),
                    $isSerialized, (int) post('min_stock', 0),
                    (float) post('cost_price', 0), (float) post('rental_price_day', 0),
                    (float) post('sale_price', 0),
                    post('supplier_id') ?: null, $_SESSION['user_id']
                ]);
            }
            
            $newId = $db->lastInsertId();
            $auditData = ['code' => $code, 'type' => $itemType];
            $audit->log('create', 'ITEM', $newId, null, $auditData);

            if ($itemType === 'Vehicle') {
                $stmt = $db->prepare("
                    INSERT INTO serials (item_id, serial_number, status, location, created_by)
                    VALUES (?, ?, 'Available', 'WH', ?)
                ");
                $stmt->execute([$newId, $vehicleSerial, $_SESSION['user_id']]);
                $serialId = $db->lastInsertId();
                $audit->log('create', 'SERIAL', $serialId, null, ['serial_number' => $vehicleSerial, 'item_id' => $newId]);
            }

            if ($itemType === 'Device') {
                $checkSerial = $db->prepare("SELECT id FROM serials WHERE serial_number = ?");
                $checkSerial->execute([$code]);
                if ($checkSerial->fetch()) {
                    throw new Exception('Serial ซ้ำในระบบ');
                }
                $stmt = $db->prepare("
                    INSERT INTO serials (item_id, serial_number, status, location, created_by)
                    VALUES (?, ?, 'Available', 'WH', ?)
                ");
                $stmt->execute([$newId, $code, $_SESSION['user_id']]);
                $serialId = $db->lastInsertId();
                $audit->log('create', 'SERIAL', $serialId, null, ['serial_number' => $code, 'item_id' => $newId]);
            }

            $db->commit();
            setFlash('success', 'สร้างสินค้าเรียบร้อย (รหัส: ' . $code . ')');
            redirect('items.php');
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
            redirect('items.php?action=add');
        }
        
    } elseif ($formAction === 'update') {
        $id = (int) post('id');
        $maintenanceRequired = 0;
        $maintenanceInterval = null;
        $maintenanceLastDate = null;
        $maintenanceNextDate = null;
        $itemType = '';
        $serializedFlag = 0;
        if ($maintenanceSchemaReady) {
            $stmt = $db->prepare("SELECT item_type, is_serialized, maintenance_required, maintenance_interval_days, maintenance_last_date FROM items WHERE id = ?");
            $stmt->execute([$id]);
            $current = $stmt->fetch();
            if (!$current) {
                setFlash('error', 'ไม่พบข้อมูล');
                redirect('items.php');
            }
            $itemType = $current['item_type'] ?? '';
            $maintenanceRequired = (int) ($current['maintenance_required'] ?? 0);
            $maintenanceInterval = (int) ($current['maintenance_interval_days'] ?? 0);
            $maintenanceLastDate = $current['maintenance_last_date'] ?? null;
            $maintenanceNextDate = computeNextMaintenanceDate($maintenanceLastDate, $maintenanceInterval);
            $serializedFlag = (int) ($current['is_serialized'] ?? 0);

            if (in_array($itemType, ['Device', 'Equipment', 'Vehicle'], true)) {
                $serializedFlag = 1;
            }
            if ($itemType === 'Consumable') {
                $serializedFlag = 0;
            }

            if ($canMaintain) {
                $maintenanceRequired = post('maintenance_required') ? 1 : 0;
                if ($itemType === 'Device') {
                    $maintenanceRequired = 1;
                }
                $maintenanceInterval = (int) post('maintenance_interval_days', 0);
                $maintenanceLastDate = post('maintenance_last_date') ?: null;
                if ($maintenanceRequired && $maintenanceInterval <= 0) {
                    setFlash('error', 'กรุณาระบุรอบ Maintenance');
                    redirect('items.php?action=edit&id=' . $id);
                }
                if ($maintenanceRequired && !$maintenanceLastDate) {
                    $maintenanceLastDate = date('Y-m-d');
                }
                $maintenanceNextDate = computeNextMaintenanceDate($maintenanceLastDate, $maintenanceInterval);
            }
        } else {
            $stmt = $db->prepare("SELECT item_type, is_serialized FROM items WHERE id = ?");
            $stmt->execute([$id]);
            $current = $stmt->fetch();
            if (!$current) {
                setFlash('error', 'ไม่พบข้อมูล');
                redirect('items.php');
            }
            $itemType = $current['item_type'] ?? '';
            $serializedFlag = (int) ($current['is_serialized'] ?? 0);
            if (in_array($itemType, ['Device', 'Equipment', 'Vehicle'], true)) {
                $serializedFlag = 1;
            }
            if ($itemType === 'Consumable') {
                $serializedFlag = 0;
            }
        }

        if ($maintenanceSchemaReady) {
            $stmt = $db->prepare("
                UPDATE items SET 
                    name = ?, category = ?, brand = ?, model = ?, description = ?,
                    unit = ?, is_serialized = ?, maintenance_required = ?, maintenance_interval_days = ?, maintenance_last_date = ?, maintenance_next_date = ?,
                    min_stock = ?,
                    cost_price = ?, rental_price_day = ?, sale_price = ?, supplier_id = ?
                WHERE id = ?
            ");
            $stmt->execute([
                sanitize(post('name')), sanitize(post('category')),
                sanitize(post('brand')), sanitize(post('model')), post('description'),
                sanitize(post('unit')), $serializedFlag,
                $maintenanceRequired, $maintenanceInterval ?: null, $maintenanceLastDate, $maintenanceNextDate,
                (int) post('min_stock', 0), (float) post('cost_price', 0),
                (float) post('rental_price_day', 0), (float) post('sale_price', 0),
                post('supplier_id') ?: null, $id
            ]);
        } else {
            $stmt = $db->prepare("
                UPDATE items SET 
                    name = ?, category = ?, brand = ?, model = ?, description = ?,
                    unit = ?, is_serialized = ?, min_stock = ?,
                    cost_price = ?, rental_price_day = ?, sale_price = ?, supplier_id = ?
                WHERE id = ?
            ");
            $stmt->execute([
                sanitize(post('name')), sanitize(post('category')),
                sanitize(post('brand')), sanitize(post('model')), post('description'),
                sanitize(post('unit')), $serializedFlag,
                (int) post('min_stock', 0), (float) post('cost_price', 0),
                (float) post('rental_price_day', 0), (float) post('sale_price', 0),
                post('supplier_id') ?: null, $id
            ]);
        }

        if ($canMaintain && $maintenanceSchemaReady) {
            $audit->log('update', 'ITEM', $id, null, [
                'maintenance_required' => $maintenanceRequired,
                'maintenance_interval_days' => $maintenanceInterval,
                'maintenance_last_date' => $maintenanceLastDate,
                'maintenance_next_date' => $maintenanceNextDate
            ]);
            if ($maintenanceEnabled) {
                $db->prepare("
                    INSERT INTO item_maintenance_logs (item_id, action, due_date, notes, created_by)
                    VALUES (?, 'UPDATE_INTERVAL', ?, ?, ?)
                ")->execute([$id, $maintenanceNextDate, 'Update maintenance settings', $_SESSION['user_id']]);
            }
        } else {
            $audit->log('update', 'ITEM', $id);
        }
        setFlash('success', 'บันทึกเรียบร้อย');
        redirect('items.php');
    }
}

// Get data
$serialEligible = false;
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    if (!$item) { setFlash('error', 'ไม่พบข้อมูล'); redirect('items.php'); }
    
    $serials = [];
    $itemType = strtolower((string) ($item['item_type'] ?? ''));
    $serialEligible = in_array($itemType, ['device', 'equipment', 'vehicle'], true)
        || (int) ($item['is_serialized'] ?? 0) === 1;
    if ($serialEligible) {
        $serialsStmt = $db->prepare("SELECT * FROM serials WHERE item_id = ? ORDER BY serial_number");
        $serialsStmt->execute([$id]);
        $serials = $serialsStmt->fetchAll();
    }

    if ($maintenanceEnabled) {
        $maintenanceLogs = $db->prepare("
            SELECT l.*, u.full_name as created_by_name
            FROM item_maintenance_logs l
            LEFT JOIN users u ON l.created_by = u.id
            WHERE l.item_id = ?
            ORDER BY l.id DESC
            LIMIT 20
        ");
        $maintenanceLogs->execute([$id]);
        $maintenanceLogs = $maintenanceLogs->fetchAll();
    } else {
        $maintenanceLogs = [];
    }
}
if ($action === 'edit' && !$id) {
    setFlash('error', 'ไม่พบข้อมูล');
    redirect('items.php');
}
if ($action === 'add') {
    $item = [];
    $serials = [];
    $maintenanceLogs = [];
}

// Suppliers dropdown
$suppliers = $db->query("SELECT id, code, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();

// List
$search = get('search', '');
$sortBy = get('sort', 'created_at');
$sortDir = get('dir', 'DESC');
$allowedSorts = ['code' => 'i.code', 'name' => 'i.name', 'item_type' => 'i.item_type', 'created_at' => 'i.created_at', 'source' => 'i.source'];
$orderCol = $allowedSorts[$sortBy] ?? 'i.created_at';
$orderDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

$where = 'i.is_active = 1';
$params = [];

if ($typeFilter) {
    $where .= ' AND i.item_type = ?';
    $params[] = $typeFilter;
}
if ($search) {
    $where .= ' AND (i.code LIKE ? OR i.name LIKE ? OR i.brand LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$usageSubquery = "
    SELECT item_id, COUNT(*) as use_count
    FROM stock_movements
    WHERE movement_type = 'GI_JOB'
    GROUP BY item_id
";

$reservedSubquery = "
    SELECT item_id, COALESCE(SUM(qty), 0) as reserved_qty
    FROM reservations
    WHERE status IN ('Reserved', 'Allocated')
    GROUP BY item_id
";

$items = $db->prepare("
    SELECT i.*, s.name as supplier_name,
           COALESCE(ss.serial_count, 0) as serial_count,
           COALESCE(ss.in_use_count, 0) as in_use_count,
           COALESCE(ss.allocated_count, 0) as allocated_count,
           COALESCE(um.use_count, 0) as use_count,
           rq.reserved_qty as reserved_qty
    FROM items i
    LEFT JOIN suppliers s ON i.supplier_id = s.id
    LEFT JOIN (
        SELECT item_id,
               COUNT(*) as serial_count,
               SUM(CASE WHEN status = 'InUse' THEN 1 ELSE 0 END) as in_use_count,
               SUM(CASE WHEN status = 'Allocated' THEN 1 ELSE 0 END) as allocated_count
        FROM serials
        GROUP BY item_id
    ) ss ON ss.item_id = i.id
    LEFT JOIN ($usageSubquery) um ON um.item_id = i.id
    LEFT JOIN ($reservedSubquery) rq ON rq.item_id = i.id
    WHERE $where
    ORDER BY $orderCol $orderDir, i.code
");
$items->execute($params);
$items = $items->fetchAll();

$usageRows = [];
$usageMax = 0;
if ($action === 'list') {
    $usageRowsStmt = $db->prepare("
        SELECT i.id, i.code, i.name, i.item_type, COALESCE(um.use_count, 0) as use_count
        FROM items i
        LEFT JOIN ($usageSubquery) um ON um.item_id = i.id
        WHERE $where
        ORDER BY use_count DESC, i.code
        LIMIT 8
    ");
    $usageRowsStmt->execute($params);
    $usageRows = $usageRowsStmt->fetchAll();
    foreach ($usageRows as $row) {
        $usageMax = max($usageMax, (int) ($row['use_count'] ?? 0));
    }

    if ($maintenanceEnabled) {
        // Maintenance notifications (7-day lead, until ACK)
        $today = date('Y-m-d');
        $notifyCutoff = date('Y-m-d', strtotime('+7 days'));
        $notifyItems = [];
        foreach ($items as $row) {
            if (empty($row['maintenance_required']) || empty($row['maintenance_next_date'])) {
                continue;
            }
            if (!empty($row['maintenance_scheduled_date'])) {
                continue;
            }
            if (!empty($row['maintenance_ack_until']) && $row['maintenance_ack_until'] >= $today) {
                continue;
            }
            if ($row['maintenance_next_date'] <= $notifyCutoff) {
                if (($row['maintenance_last_notified'] ?? '') !== $today) {
                    $notifyItems[] = $row;
                }
            }
        }

        if (!empty($notifyItems)) {
            $placeholders = implode(',', array_fill(0, count($maintenanceRoles), '?'));
            $stmtUsers = $db->prepare("
                SELECT DISTINCT u.id
                FROM users u
                JOIN user_roles ur ON u.id = ur.user_id
                JOIN roles r ON ur.role_id = r.id
                WHERE r.code IN ($placeholders)
                AND u.is_active = 1
            ");
            $stmtUsers->execute($maintenanceRoles);
            $userIds = array_map('intval', $stmtUsers->fetchAll(PDO::FETCH_COLUMN));

            if (!empty($userIds)) {
                $notification = new Notification();
                $notifiedIds = [];
                foreach ($notifyItems as $row) {
                    $due = $row['maintenance_next_date'];
                    $title = "Maintenance ใกล้ถึงรอบ: {$row['code']}";
                    $message = "กำหนด " . formatDate($due);
                    $priority = ($due < $today) ? Notification::PRIORITY_HIGH : Notification::PRIORITY_NORMAL;
                    $url = "/4erpv2/modules/master/items.php?action=edit&id={$row['id']}";
                    $notification->createBulk(
                        $userIds,
                        Notification::TYPE_SYSTEM,
                        $title,
                        $message,
                        $url,
                        'ITEM',
                        (int) $row['id'],
                        $priority
                    );
                    $notifiedIds[] = (int) $row['id'];
                }
                if (!empty($notifiedIds)) {
                    $inPlaceholders = implode(',', array_fill(0, count($notifiedIds), '?'));
                    $stmtUpdate = $db->prepare("UPDATE items SET maintenance_last_notified = ? WHERE id IN ($inPlaceholders)");
                    $stmtUpdate->execute(array_merge([$today], $notifiedIds));
                }
            }
        }
    }
}

$pageTitle = 'Items - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-box-seam" style="color: var(--primary);"></i> สินค้า & อุปกรณ์
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php">Master Data</a></li>
                <li class="breadcrumb-item active">Items</li>
            </ol>
        </nav>
    </div>
    <div class="page-header-actions">
        <?php if ($action === 'list'): ?>
        <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>เพิ่มสินค้า</a>
        <?php else: ?>
        <a href="javascript:history.back()" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>กลับ</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($action === 'list'): ?>
<?php
    $totalItems = count($items);
    $totalSerialUnits = array_sum(array_map(fn($i) => (int) ($i['serial_count'] ?? 0), $items));
    $totalInUseUnits = array_sum(array_map(fn($i) => (int) ($i['in_use_count'] ?? 0), $items));
    $totalReservedUnits = 0;
    foreach ($items as $i) {
        $reserved = $i['reserved_qty'];
        if ($reserved === null) {
            $reserved = (float) ($i['allocated_count'] ?? 0);
        }
        $totalReservedUnits += (float) $reserved;
    }
    $totalReservedDecimals = abs($totalReservedUnits - (int)$totalReservedUnits) > 0.00001 ? 2 : 0;
?>

<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-icon primary"><i class="bi bi-box-seam" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format($totalItems) ?></div>
            <div class="stat-label">รายการทั้งหมด</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info"><i class="bi bi-upc-scan" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format($totalSerialUnits) ?></div>
            <div class="stat-label">Serial ทั้งหมด</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="bi bi-play-circle" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format($totalInUseUnits) ?></div>
            <div class="stat-label">กำลังใช้งาน</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success"><i class="bi bi-clock" style="font-size: 1.5rem;"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= formatNumber($totalReservedUnits, $totalReservedDecimals) ?></div>
            <div class="stat-label">ถูกจอง</div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-bar-chart-line me-2"></i>อุปกรณ์ที่ใช้งานบ่อย (ครั้ง)</span>
        <span class="text-muted small">นับจาก GI_JOB</span>
    </div>
    <div class="card-body">
        <?php if (empty($usageRows) || $usageMax === 0): ?>
            <div class="text-muted">ยังไม่มีรายการใช้งาน</div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($usageRows as $row): 
                    $count = (int) ($row['use_count'] ?? 0);
                    $pct = $usageMax > 0 ? round(($count / $usageMax) * 100) : 0;
                ?>
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div class="fw-semibold">
                            <?= e($row['code']) ?> - <?= e($row['name']) ?>
                            <span class="text-muted small">(<?= e($row['item_type']) ?>)</span>
                        </div>
                        <div class="text-muted small"><?= number_format($count) ?> ครั้ง</div>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $pct ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">ค้นหา</label>
                <input type="text" class="form-control" name="search" placeholder="รหัส, ชื่อ, แบรนด์..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">ประเภท</label>
                <select class="form-select" name="type">
                    <option value="">-- ทุกประเภท --</option>
                    <option value="Device" <?= $typeFilter === 'Device' ? 'selected' : '' ?>>Device</option>
                    <option value="Equipment" <?= $typeFilter === 'Equipment' ? 'selected' : '' ?>>Equipment</option>
                    <option value="Vehicle" <?= $typeFilter === 'Vehicle' ? 'selected' : '' ?>>Vehicle</option>
                    <option value="Consumable" <?= $typeFilter === 'Consumable' ? 'selected' : '' ?>>Consumable</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary">ค้นหา</button>
                <a href="items.php" class="btn btn-outline-secondary">ล้าง</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($items)): ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
            <p class="text-muted mt-3">ไม่พบรายการสินค้า</p>
            <a href="?action=add" class="btn btn-primary">เพิ่มสินค้า</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th><a href="?sort=code&dir=<?= $sortBy === 'code' && $sortDir === 'ASC' ? 'DESC' : 'ASC' ?>&type=<?= e($typeFilter) ?>&search=<?= e($search) ?>" class="text-decoration-none">รหัส <?= $sortBy === 'code' ? ($sortDir === 'ASC' ? '↑' : '↓') : '' ?></a></th>
                        <th>ชื่อ</th>
                        <th><a href="?sort=item_type&dir=<?= $sortBy === 'item_type' && $sortDir === 'ASC' ? 'DESC' : 'ASC' ?>&type=<?= e($typeFilter) ?>&search=<?= e($search) ?>" class="text-decoration-none">ประเภท <?= $sortBy === 'item_type' ? ($sortDir === 'ASC' ? '↑' : '↓') : '' ?></a></th>
                        <th><a href="?sort=source&dir=<?= $sortBy === 'source' && $sortDir === 'ASC' ? 'DESC' : 'ASC' ?>&type=<?= e($typeFilter) ?>&search=<?= e($search) ?>" class="text-decoration-none">ที่มา <?= $sortBy === 'source' ? ($sortDir === 'ASC' ? '↑' : '↓') : '' ?></a></th>
                        <th>Serial</th>
                        <?php if ($maintenanceSchemaReady): ?>
                        <th>Maintenance</th>
                        <?php endif; ?>
                        <th>ใช้งาน/จอง</th>
                        <th><a href="?sort=created_at&dir=<?= $sortBy === 'created_at' && $sortDir === 'ASC' ? 'DESC' : 'ASC' ?>&type=<?= e($typeFilter) ?>&search=<?= e($search) ?>" class="text-decoration-none">สร้างเมื่อ <?= $sortBy === 'created_at' ? ($sortDir === 'ASC' ? '↑' : '↓') : '' ?></a></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $i): ?>
                    <?php
                        $reservedQty = $i['reserved_qty'];
                        if ($reservedQty === null) {
                            $reservedQty = (float) ($i['allocated_count'] ?? 0);
                        }
                        $reservedDecimals = abs($reservedQty - (int)$reservedQty) > 0.00001 ? 2 : 0;
                    ?>
                    <tr>
                        <td><strong><?= e($i['code']) ?></strong></td>
                        <td>
                            <?= e($i['name']) ?>
                            <?php if ($i['brand']): ?><br><small class="text-muted"><?= e($i['brand']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?php $itemTypeLabel = $i['item_type'] ?? ''; ?>
                            <?php $itemTypeKey = strtolower((string) $itemTypeLabel); ?>
                            <span class="badge bg-<?= match($itemTypeKey) { 'device' => 'primary', 'equipment' => 'info', 'vehicle' => 'warning', default => 'secondary' } ?>">
                                <?= e($itemTypeLabel) ?>
                            </span>
                        </td>
                        <td>
                            <?php $src = $i['source'] ?? 'MASTER'; ?>
                            <small class="<?= $src === 'GR' ? 'text-success' : 'text-muted' ?>">
                                <?= $src === 'GR' ? '● GR' : '○ Master' ?>
                            </small>
                        </td>
                        <td>
                            <?php if ((int) ($i['is_serialized'] ?? 0) === 1 || in_array($itemTypeKey, ['device', 'equipment', 'vehicle'], true)): ?>
                            <span class="badge bg-success"><?= number_format((int) ($i['serial_count'] ?? 0)) ?></span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($maintenanceSchemaReady): ?>
                        <td>
                            <?php
                                $mRequired = (int) ($i['maintenance_required'] ?? 0) === 1;
                                $mNext = $i['maintenance_next_date'] ?? null;
                                $mAckUntil = $i['maintenance_ack_until'] ?? null;
                                $mScheduled = $i['maintenance_scheduled_date'] ?? null;
                                $today = date('Y-m-d');
                                if (!$mRequired) {
                                    $mLabel = 'ไม่บังคับ';
                                    $mBadge = 'secondary';
                                } elseif (!$mNext) {
                                    $mLabel = 'ยังไม่ตั้งรอบ';
                                    $mBadge = 'secondary';
                                } elseif ($mScheduled) {
                                    $mLabel = 'มีนัดซ่อม';
                                    $mBadge = 'info';
                                } elseif ($mAckUntil && $mAckUntil >= $today) {
                                    $mLabel = 'ACK';
                                    $mBadge = 'warning text-dark';
                                } elseif ($mNext < $today) {
                                    $mLabel = 'เกินกำหนด';
                                    $mBadge = 'danger';
                                } elseif ($mNext <= date('Y-m-d', strtotime('+7 days'))) {
                                    $mLabel = 'ใกล้ถึงรอบ';
                                    $mBadge = 'warning text-dark';
                                } else {
                                    $mLabel = 'ปกติ';
                                    $mBadge = 'success';
                                }
                            ?>
                            <span class="badge bg-<?= $mBadge ?>"><?= $mLabel ?></span>
                            <?php if ($mNext): ?>
                                <div class="small text-muted"><?= formatDate($mNext) ?></div>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <?php 
                            $inUse = (int) ($i['in_use_count'] ?? 0);
                            $reserved = $reservedQty > 0 ? formatNumber($reservedQty, $reservedDecimals) : '0';
                            ?>
                            <?php if ($inUse > 0 || $reservedQty > 0): ?>
                                <span class="text-warning"><?= $inUse ?></span> / <span class="text-info"><?= $reserved ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted"><?= date('d/m/y H:i', strtotime($i['created_at'])) ?></small>
                        </td>
                        <td>
                            <a href="?action=edit&id=<?= $i['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if ($canDeactivate): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('ปิดใช้งาน <?= e($i['code']) ?> ?');">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="form_action" value="deactivate">
                                <input type="hidden" name="id" value="<?= $i['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-slash-circle"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="card">
    <div class="card-header"><?= $action === 'add' ? 'เพิ่มสินค้า' : 'แก้ไข: ' . e($item['name']) ?></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="form_action" value="<?= $action === 'add' ? 'create' : 'update' ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $item['id'] ?>"><?php endif; ?>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">ประเภท <span class="text-danger">*</span></label>
                        <select class="form-select" name="item_type" id="itemTypeSelect" required <?= $action === 'edit' ? 'disabled' : '' ?>>
                            <option value="">-- เลือกประเภทก่อน --</option>
                            <option value="Device" <?= ($item['item_type'] ?? '') === 'Device' ? 'selected' : '' ?>>Device (อุปกรณ์ IT)</option>
                            <option value="Equipment" <?= ($item['item_type'] ?? '') === 'Equipment' ? 'selected' : '' ?>>Equipment (เครื่องมือ)</option>
                            <option value="Vehicle" <?= ($item['item_type'] ?? '') === 'Vehicle' ? 'selected' : '' ?>>Vehicle (ยานพาหนะ)</option>
                            <option value="Consumable" <?= ($item['item_type'] ?? '') === 'Consumable' ? 'selected' : '' ?>>Consumable (วัสดุสิ้นเปลือง)</option>
                        </select>
                        <div class="form-text" id="typeHint">เลือกประเภทเพื่อ generate รหัสอัตโนมัติ</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รหัส</label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="code" id="itemCodeInput"
                                   value="<?= e($item['code'] ?? '') ?>" 
                                   readonly
                                   placeholder="เลือกประเภทก่อน">
                            <?php if ($action === 'add'): ?>
                            <button type="button" class="btn btn-outline-secondary" id="regenerateCodeBtn" disabled>
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="form-text" id="codeHelpText">รหัสจะถูกสร้างอัตโนมัติเมื่อเลือกประเภท (ปรับ prefix/ลำดับได้ที่ Admin &gt; Document Numbers)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required value="<?= e($item['name'] ?? '') ?>" placeholder="ชื่อสินค้า/อุปกรณ์">
                    </div>
                    <div class="mb-3" id="vehicleFields" style="display: none;">
                        <label class="form-label">ทะเบียนรถ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="vehicle_serial_number" id="vehicleSerialInput"
                               value="<?= e(($action === 'edit' && !empty($serials)) ? ($serials[0]['serial_number'] ?? '') : '') ?>"
                               <?= $action === 'edit' ? 'readonly' : '' ?> placeholder="เช่น กข-1234">
                        <div class="form-text">ทะเบียนรถจะถูกบันทึกเป็น Serial Number</div>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">Brand</label>
                                <input type="text" class="form-control" name="brand" value="<?= e($item['brand'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">Model</label>
                                <input type="text" class="form-control" name="model" value="<?= e($item['model'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-control" name="category" value="<?= e($item['category'] ?? '') ?>" placeholder="หมวดหมู่ย่อย (ถ้ามี)">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="row">
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">หน่วย</label>
                                <input type="text" class="form-control" name="unit" id="unitInput" value="<?= e($item['unit'] ?? 'pcs') ?>">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="mb-3">
                                <label class="form-label">Min Stock</label>
                                <input type="number" class="form-control" name="min_stock" value="<?= $item['min_stock'] ?? 0 ?>">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="is_serialized" id="isSerialized" value="1" 
                               <?= ($item['is_serialized'] ?? 0) ? 'checked' : '' ?> <?= $action === 'edit' ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="isSerialized">Track by Serial Number (บังคับสำหรับ Device/Equipment/Vehicle)</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ต้นทุน</label>
                        <input type="number" class="form-control" name="cost_price" step="0.01" value="<?= $item['cost_price'] ?? 0 ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ค่าเช่า/วัน</label>
                        <input type="number" class="form-control" name="rental_price_day" step="0.01" value="<?= $item['rental_price_day'] ?? 0 ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ราคาขาย</label>
                        <input type="number" class="form-control" name="sale_price" step="0.01" value="<?= $item['sale_price'] ?? 0 ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Supplier</label>
                        <select class="form-select" name="supplier_id">
                            <option value="">-- ไม่ระบุ --</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($item['supplier_id'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                <?= e($s['code']) ?> - <?= e($s['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($maintenanceSchemaReady): ?>
                    <div class="mb-3">
                        <label class="form-label">Maintenance</label>
                        <div class="border rounded p-3">
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" name="maintenance_required" id="maintenanceRequired" value="1"
                                       <?= ($item['maintenance_required'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="maintenanceRequired">ต้องมีการบำรุงรักษาตามรอบ</label>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label">รอบ (วัน)</label>
                                    <input type="number" class="form-control" name="maintenance_interval_days" id="maintenanceInterval"
                                           min="1" value="<?= e($item['maintenance_interval_days'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">บำรุงครั้งล่าสุด</label>
                                    <input type="date" class="form-control" name="maintenance_last_date" id="maintenanceLastDate"
                                           value="<?= e($item['maintenance_last_date'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">กำหนดถัดไป</label>
                                    <?php $maintenanceNextPreview = $item['maintenance_next_date'] ?? computeNextMaintenanceDate($item['maintenance_last_date'] ?? null, (int) ($item['maintenance_interval_days'] ?? 0)); ?>
                                    <input type="date" class="form-control" id="maintenanceNextDate" value="<?= e($maintenanceNextPreview ?? '') ?>" readonly>
                                </div>
                            </div>
                            <div class="form-text">Device ต้องมีรอบ Maintenance</div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        ระบบ Maintenance ยังไม่พร้อมใช้งาน (กรุณารัน <code>sql/schema_patch_item_maintenance.sql</code>)
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">รายละเอียด</label>
                <textarea class="form-control" name="description" rows="2" placeholder="รายละเอียดเพิ่มเติม (ถ้ามี)"><?= e($item['description'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>บันทึก</button>
        </form>
    </div>
</div>

<?php if ($action === 'edit' && $maintenanceSchemaReady): ?>
<?php
    $today = date('Y-m-d');
    $maintenanceRequired = (int) ($item['maintenance_required'] ?? 0);
    $maintenanceNext = $item['maintenance_next_date'] ?? null;
    $maintenanceAckUntil = $item['maintenance_ack_until'] ?? null;
    $maintenanceScheduledDate = $item['maintenance_scheduled_date'] ?? null;
    $maintenanceScheduledHours = $item['maintenance_scheduled_hours'] ?? null;

    if (!$maintenanceRequired) {
        $maintenanceStatus = 'ไม่บังคับ';
        $maintenanceBadge = 'secondary';
    } elseif (!$maintenanceNext) {
        $maintenanceStatus = 'ยังไม่ตั้งรอบ';
        $maintenanceBadge = 'secondary';
    } elseif ($maintenanceScheduledDate) {
        $maintenanceStatus = 'มีนัดซ่อม';
        $maintenanceBadge = 'info';
    } elseif ($maintenanceAckUntil && $maintenanceAckUntil >= $today) {
        $maintenanceStatus = 'ACK';
        $maintenanceBadge = 'warning text-dark';
    } elseif ($maintenanceNext < $today) {
        $maintenanceStatus = 'เกินกำหนด';
        $maintenanceBadge = 'danger';
    } elseif ($maintenanceNext <= date('Y-m-d', strtotime('+7 days'))) {
        $maintenanceStatus = 'ใกล้ถึงรอบ';
        $maintenanceBadge = 'warning text-dark';
    } else {
        $maintenanceStatus = 'ปกติ';
        $maintenanceBadge = 'success';
    }
?>
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-tools me-2"></i>Maintenance</span>
        <span class="badge bg-<?= $maintenanceBadge ?>"><?= $maintenanceStatus ?></span>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="text-muted small">รอบถัดไป</div>
                <div class="fw-semibold"><?= $maintenanceNext ? formatDate($maintenanceNext) : '-' ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">ACK ถึง</div>
                <div class="fw-semibold"><?= $maintenanceAckUntil ? formatDate($maintenanceAckUntil) : '-' ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">นัดซ่อม</div>
                <div class="fw-semibold"><?= $maintenanceScheduledDate ? formatDate($maintenanceScheduledDate) : '-' ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">ระยะเวลา</div>
                <div class="fw-semibold"><?= $maintenanceScheduledHours ? e($maintenanceScheduledHours) . ' ชม.' : '-' ?></div>
            </div>
        </div>

        <?php if ($canMaintain): ?>
        <div class="row g-3">
            <div class="col-md-4">
                <form method="POST" onsubmit="return confirm('ACK รอบ Maintenance (เลื่อนไป 7 วัน)?');">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="form_action" value="maintenance_ack">
                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                    <button type="submit" class="btn btn-outline-warning w-100">
                        <i class="bi bi-bell-slash me-1"></i>ACK (เลื่อน 7 วัน)
                    </button>
                </form>
            </div>
            <div class="col-md-8">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="form_action" value="maintenance_schedule">
                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <input type="date" class="form-control" name="scheduled_date" value="<?= e($maintenanceScheduledDate ?? '') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <input type="number" class="form-control" name="scheduled_hours" step="0.5" min="0" placeholder="ชม." value="<?= e($maintenanceScheduledHours ?? '') ?>">
                        </div>
                        <div class="col-md-5">
                            <input type="text" class="form-control" name="notes" placeholder="หมายเหตุ (ถ้ามี)">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-outline-primary mt-2">
                        <i class="bi bi-calendar-check me-1"></i>บันทึกกำหนดการซ่อม
                    </button>
                    <div class="form-text">วันที่ซ่อมจะถูกกันไม่ให้จองใช้งานวันเดียวกัน</div>
                </form>
            </div>
        </div>

        <hr>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="form_action" value="maintenance_complete">
            <input type="hidden" name="id" value="<?= $item['id'] ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">วันที่ซ่อมเสร็จ</label>
                    <input type="date" class="form-control" name="completed_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">ใช้เวลา (ชม.)</label>
                    <input type="number" class="form-control" name="completed_hours" step="0.5" min="0">
                </div>
                <div class="col-md-4">
                    <label class="form-label">หมายเหตุ</label>
                    <input type="text" class="form-control" name="notes">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-check2-circle me-1"></i>ปิดงานซ่อม
                    </button>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <?php if (!empty($maintenanceLogs)): ?>
        <div class="table-responsive mt-3">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>เวลา</th>
                        <th>Action</th>
                        <th>Due</th>
                        <th>Schedule</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($maintenanceLogs as $log): ?>
                    <tr>
                        <td><?= formatDateTime($log['created_at']) ?></td>
                        <td><?= e($log['action']) ?></td>
                        <td><?= $log['due_date'] ? formatDate($log['due_date']) : '-' ?></td>
                        <td><?= $log['scheduled_date'] ? formatDate($log['scheduled_date']) : '-' ?></td>
                        <td><?= e($log['created_by_name'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($action === 'edit' && $serialEligible): ?>
<div class="card mt-4">
    <div class="card-header">
        <i class="bi bi-upc-scan me-2"></i>Serial Numbers (<?= count($serials) ?> units)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr><th>Serial</th><th>Status</th><th>Location</th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($serials)): ?>
                        <?php foreach ($serials as $sr): ?>
                        <tr>
                            <td><?= e($sr['serial_number']) ?></td>
                            <td>
                                <span class="badge bg-<?= $sr['status'] === 'Available' ? 'success' : ($sr['status'] === 'Damaged' || $sr['status'] === 'Lost' ? 'danger' : 'warning') ?>">
                                    <?= e($sr['status']) ?>
                                </span>
                            </td>
                            <td><?= e($sr['location']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="text-center text-muted">ยังไม่มี Serial</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canSerialManage): ?>
        <form method="POST" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="form_action" value="add_serials">
            <input type="hidden" name="id" value="<?= $item['id'] ?>">
            <label class="form-label">เพิ่ม Serial (ใส่ทีละบรรทัด)</label>
            <textarea class="form-control" name="serial_numbers" rows="3" placeholder="เช่น SN-001&#10;SN-002"></textarea>
            <div class="d-flex justify-content-between align-items-center mt-2">
                <small class="text-muted">Device/Equipment เพิ่มได้หลายรายการ, Vehicle ได้ 1 รายการ</small>
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i>เพิ่ม Serial
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const typeSelect = document.getElementById('itemTypeSelect');
    const vehicleFields = document.getElementById('vehicleFields');
    const vehicleSerialInput = document.getElementById('vehicleSerialInput');
    const serialCheckbox = document.getElementById('isSerialized');
    const unitInput = document.getElementById('unitInput');
    const codeInput = document.getElementById('itemCodeInput');
    const regenerateBtn = document.getElementById('regenerateCodeBtn');
    const codeHelpText = document.getElementById('codeHelpText');
    const typeHint = document.getElementById('typeHint');
    const maintenanceRequired = document.getElementById('maintenanceRequired');
    const maintenanceInterval = document.getElementById('maintenanceInterval');
    const maintenanceLastDate = document.getElementById('maintenanceLastDate');
    const maintenanceNextDate = document.getElementById('maintenanceNextDate');
    const isAddMode = <?= $action === 'add' ? 'true' : 'false' ?>;

    async function generateCode(itemType) {
        if (!itemType || !isAddMode) return;
        
        try {
            codeInput.placeholder = 'กำลังสร้างรหัส...';
            const response = await fetch(`<?= BASE_URL ?>/modules/master/api/item_code_generate.php?item_type=${encodeURIComponent(itemType)}`);
            const data = await response.json();
            
            if (data.success) {
                codeInput.value = data.code;
                const seqText = data.sequence ? `ลำดับที่ ${data.sequence}` : 'ลำดับใหม่';
                codeHelpText.innerHTML = `<span class="text-success"><i class="bi bi-check-circle me-1"></i>รหัส ${data.code} (${seqText})</span>`;
                if (regenerateBtn) regenerateBtn.disabled = false;
            } else {
                codeHelpText.innerHTML = `<span class="text-danger">เกิดข้อผิดพลาด: ${data.error}</span>`;
            }
        } catch (error) {
            console.error('Error generating code:', error);
            codeHelpText.innerHTML = `<span class="text-danger">ไม่สามารถสร้างรหัสได้</span>`;
        }
    }

    function syncVehicleFields() {
        const typeValue = typeSelect ? typeSelect.value : '';
        const isVehicle = typeValue === 'Vehicle';
        const isDevice = typeValue === 'Device';
        const isEquipment = typeValue === 'Equipment';
        const isConsumable = typeValue === 'Consumable';
        if (vehicleFields) {
            vehicleFields.style.display = isVehicle ? 'block' : 'none';
        }
        if (vehicleSerialInput) {
            vehicleSerialInput.required = isVehicle && !vehicleSerialInput.readOnly;
        }
        if (serialCheckbox && isAddMode) {
            if (isVehicle || isDevice || isEquipment) {
                serialCheckbox.checked = true;
                serialCheckbox.disabled = true;
            } else if (isConsumable) {
                serialCheckbox.checked = false;
                serialCheckbox.disabled = true;
            } else {
                serialCheckbox.disabled = false;
            }
        }
        if (unitInput && isVehicle) {
            unitInput.value = 'คัน';
        }
        if (maintenanceRequired) {
            if (isDevice) {
                maintenanceRequired.checked = true;
                maintenanceRequired.disabled = true;
            } else {
                maintenanceRequired.disabled = false;
            }
        }
        if (maintenanceInterval) {
            maintenanceInterval.required = maintenanceRequired && maintenanceRequired.checked;
        }
        if (typeHint) {
            if (isDevice) {
                typeHint.textContent = 'Device ต้องมี Serial และตั้งรอบ Maintenance';
            } else if (isEquipment) {
                typeHint.textContent = 'Equipment ต้องมี Serial';
            } else if (isVehicle) {
                typeHint.textContent = 'Vehicle ใช้ทะเบียนเป็น Serial และมีได้ 1 รายการ';
            } else if (isConsumable) {
                typeHint.textContent = 'Consumable ไม่ต้องมี Serial';
            } else {
                typeHint.textContent = 'เลือกประเภทเพื่อ generate รหัสอัตโนมัติ';
            }
        }
    }

    function updateMaintenanceNextDate() {
        if (!maintenanceInterval || !maintenanceLastDate || !maintenanceNextDate) return;
        const interval = parseInt(maintenanceInterval.value || '0', 10);
        if (!interval || !maintenanceLastDate.value) {
            maintenanceNextDate.value = '';
            return;
        }
        const dt = new Date(maintenanceLastDate.value);
        if (Number.isNaN(dt.getTime())) {
            maintenanceNextDate.value = '';
            return;
        }
        dt.setDate(dt.getDate() + interval);
        maintenanceNextDate.value = dt.toISOString().slice(0, 10);
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', function() {
            syncVehicleFields();
            if (isAddMode && this.value) {
                generateCode(this.value);
            }
        });
        syncVehicleFields();
    }
    if (maintenanceInterval) {
        maintenanceInterval.addEventListener('input', updateMaintenanceNextDate);
    }
    if (maintenanceLastDate) {
        maintenanceLastDate.addEventListener('change', updateMaintenanceNextDate);
    }
    updateMaintenanceNextDate();

    if (regenerateBtn) {
        regenerateBtn.addEventListener('click', function() {
            if (typeSelect && typeSelect.value) {
                generateCode(typeSelect.value);
            }
        });
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
