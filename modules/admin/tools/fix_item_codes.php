<?php
/**
 * Admin Tool: Fix overflow item codes from GR
 * Only for admin use.
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireRole([ROLE_ADMIN]);

$db = getDB();
$audit = new AuditLog();
$docNum = new DocumentNumber();

$items = $db->query("
    SELECT id, code, name, item_type
    FROM items
    WHERE code LIKE '%9.2233720368548E%'
    ORDER BY id ASC
")->fetchAll();

if (isPost()) {
    if (!verifyCsrf(post('csrf_token', ''))) {
        setFlash('error', 'Invalid request');
        redirect('fix_item_codes.php');
    }

    if (empty($items)) {
        setFlash('info', 'ไม่พบรายการที่ต้องแก้ไข');
        redirect('fix_item_codes.php');
    }

    $updated = 0;
    try {
        foreach ($items as $row) {
            $itemType = $row['item_type'] ?: 'Consumable';
            $docType = match ($itemType) {
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

            $db->prepare("UPDATE items SET code = ? WHERE id = ?")->execute([$newCode, (int) $row['id']]);
            $audit->log('update', 'items', (int) $row['id'], ['code' => $row['code']], ['code' => $newCode], 'Fix overflow item code');
            $updated++;
        }

        setFlash('success', "แก้ไขรหัสเรียบร้อย {$updated} รายการ");
        redirect('fix_item_codes.php');
    } catch (Exception $e) {
        setFlash('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        redirect('fix_item_codes.php');
    }
}

$pageTitle = 'Fix Item Codes - 4ERP';
require_once __DIR__ . '/../../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-0"><i class="bi bi-tools me-2"></i>Fix Item Codes</h2>
            <div class="text-muted">แก้รหัสที่ล้นจากการรับของ (GR)</div>
        </div>
        <a href="../" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>กลับ
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header">รายการที่ต้องแก้ไข</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>รหัสเดิม</th>
                        <th>ชื่อ</th>
                        <th>ประเภท</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">ไม่พบรายการที่ต้องแก้ไข</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($items as $idx => $row): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><code><?= e($row['code']) ?></code></td>
                        <td><?= e($row['name'] ?? '-') ?></td>
                        <td><?= e($row['item_type'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="text-muted">ทั้งหมด <?= count($items) ?> รายการ</span>
        <form method="POST" onsubmit="return confirm('ยืนยันแก้ไขรหัสทั้งหมด?');">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <button type="submit" class="btn btn-danger" <?= empty($items) ? 'disabled' : '' ?>>
                <i class="bi bi-shield-check me-1"></i>แก้ไขรหัส
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/modern/layout_end.php'; ?>
