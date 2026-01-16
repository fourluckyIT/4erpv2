<?php
/**
 * Create GR
 * ERP v2 - Phase 4
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$db = getDB();
$audit = new AuditLog();
$docNum = new DocumentNumber();

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

// Get PO items with remaining qty
$poItems = $db->prepare("
    SELECT poi.*, i.code as item_code, i.is_serialized,
           (poi.qty - poi.received_qty) as remaining_qty
    FROM po_items poi
    LEFT JOIN items i ON poi.item_id = i.id
    WHERE poi.po_id = ? AND (poi.qty - poi.received_qty) > 0
");
$poItems->execute([$poId]);
$poItems = $poItems->fetchAll();

if (empty($poItems)) {
    setFlash('info', 'PO นี้รับของครบแล้ว');
    redirect("../po/view.php?id=$poId");
}

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
            
            // Insert GR item
            $stmt = $db->prepare("
                INSERT INTO gr_items (gr_id, po_item_id, received_qty, serial_numbers, condition_note)
                VALUES (?, ?, ?, ?, ?)
            ");
            $serials = !empty($item['serials']) ? json_encode(array_filter(explode("\n", $item['serials']))) : null;
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
        
        setFlash('success', "สร้าง GR เรียบร้อย: $grNumber");
        redirect("view.php?id=$grId");
        
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect("create.php?po_id=$poId");
    }
}

$pageTitle = 'รับสินค้า - ERP v2';
require_once __DIR__ . '/../../../includes/header.php';
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
            <a href="../po/view.php?id=<?= $poId ?>" class="btn btn-outline-secondary">
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
                            <th class="text-center" style="width: 120px;">รับครั้งนี้</th>
                            <th>หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($poItems as $item): ?>
                        <tr>
                            <td>
                                <?php if ($item['item_code']): ?>
                                <small class="text-muted"><?= e($item['item_code']) ?></small><br>
                                <?php endif; ?>
                                <?= e($item['description']) ?>
                                <?php if ($item['is_serialized']): ?>
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
                                <input type="text" class="form-control form-control-sm" 
                                       name="items[<?= $item['id'] ?>][condition_note]" 
                                       placeholder="สภาพ...">
                            </td>
                        </tr>
                        <?php if ($item['is_serialized']): ?>
                        <tr>
                            <td colspan="6" class="bg-light">
                                <small class="text-muted">Serial Numbers (หนึ่ง serial ต่อบรรทัด):</small>
                                <textarea class="form-control form-control-sm mt-1" 
                                          name="items[<?= $item['id'] ?>][serials]" 
                                          rows="2" placeholder="SN001&#10;SN002"></textarea>
                            </td>
                        </tr>
                        <?php endif; ?>
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

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
