<?php
/**
 * Fix swapped qty/unit_price in PR/PO items
 * Usage:
 *  - Dry run: /4erpv2/scripts/fix_swap_qty_price.php
 *  - Apply:   /4erpv2/scripts/fix_swap_qty_price.php?apply=1
 *  - Include received PO items: &include_received=1
 */

require_once __DIR__ . '/../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();
$auth->requireRole([ROLE_ADMIN]);

$db = getDB();
$audit = new AuditLog();

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';
$includeReceived = isset($_GET['include_received']) && $_GET['include_received'] === '1';

header('Content-Type: text/plain; charset=utf-8');

$prItems = $db->query("SELECT id, pr_id, qty, unit_price, amount FROM pr_items")->fetchAll(PDO::FETCH_ASSOC);
$poItems = $db->query("SELECT id, po_id, qty, unit_price, amount, received_qty FROM po_items")->fetchAll(PDO::FETCH_ASSOC);

$prCount = count($prItems);
$poCount = count($poItems);
$poSkipped = 0;

if (!$apply) {
    echo "DRY RUN\n";
    echo "PR items: {$prCount}\n";
    echo "PO items: {$poCount}\n";
    echo "PO items with received_qty > 0: " . count(array_filter($poItems, fn($r) => (float)$r['received_qty'] > 0)) . "\n";
    echo "\nTo apply changes: ?apply=1";
    echo "\nTo include received items: ?apply=1&include_received=1\n";
    exit;
}

try {
    $db->beginTransaction();

    // Swap PR items
    foreach ($prItems as $item) {
        $newQty = (float)$item['unit_price'];
        $newPrice = (float)$item['qty'];
        $newAmount = $newQty * $newPrice;

        $stmt = $db->prepare("UPDATE pr_items SET qty = ?, unit_price = ?, amount = ? WHERE id = ?");
        $stmt->execute([$newQty, $newPrice, $newAmount, $item['id']]);
    }

    // Swap PO items
    foreach ($poItems as $item) {
        if (!$includeReceived && (float)$item['received_qty'] > 0) {
            $poSkipped++;
            continue;
        }
        $newQty = (float)$item['unit_price'];
        $newPrice = (float)$item['qty'];
        $newAmount = $newQty * $newPrice;

        $stmt = $db->prepare("UPDATE po_items SET qty = ?, unit_price = ?, amount = ? WHERE id = ?");
        $stmt->execute([$newQty, $newPrice, $newAmount, $item['id']]);
    }

    // Recalculate PR totals
    $db->exec("UPDATE purchase_requests pr
              JOIN (SELECT pr_id, SUM(amount) total FROM pr_items GROUP BY pr_id) t
              ON pr.id = t.pr_id
              SET pr.total_amount = t.total");

    // Recalculate PO totals
    $db->exec("UPDATE purchase_orders po
              JOIN (SELECT po_id, SUM(amount) subtotal FROM po_items GROUP BY po_id) t
              ON po.id = t.po_id
              SET po.subtotal = t.subtotal,
                  po.vat_amount = ROUND(t.subtotal * (po.vat_rate / 100), 2),
                  po.grand_total = ROUND(t.subtotal + (t.subtotal * (po.vat_rate / 100)), 2)");

    $audit->log(
        'data_fix_swap_qty_price',
        'PROCUREMENT',
        null,
        null,
        [
            'pr_items_updated' => $prCount,
            'po_items_updated' => $poCount - $poSkipped,
            'po_items_skipped' => $poSkipped,
            'include_received' => $includeReceived ? 1 : 0
        ],
        'Swap qty/unit_price due to UI column order bug'
    );

    $db->commit();

    echo "DONE\n";
    echo "PR items updated: {$prCount}\n";
    echo "PO items updated: " . ($poCount - $poSkipped) . "\n";
    echo "PO items skipped (received_qty>0): {$poSkipped}\n";

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
