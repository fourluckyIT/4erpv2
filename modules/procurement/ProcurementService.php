<?php
/**
 * Procurement Service
 * ERP v2 - Phase 4 (M2)
 * 
 * Handles Procurement Lifecycle:
 * PR -> Approval -> PO -> GR
 */

require_once __DIR__ . '/../../core/DocumentNumber.php';
require_once __DIR__ . '/../../core/AuditLog.php';

class ProcurementService {
    private PDO $db;
    private AuditLog $audit;
    private DocumentNumber $docNum;
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
        $this->docNum = new DocumentNumber();
    }
    
    /**
     * Create Purchase Request (PR)
     */
    public function createPR(array $data, array $items): array {
        try {
            $this->db->beginTransaction();
            
            // Generate Number
            $prNumber = $this->docNum->generate('PR');
            
            // Insert PR Header
            $stmt = $this->db->prepare("
                INSERT INTO purchase_requests 
                (pr_number, job_id, requester_id, purpose, required_date, status, created_by)
                VALUES (:pr_number, :job_id, :requester_id, :purpose, :required_date, 'Draft', :created_by)
            ");
            
            $stmt->execute([
                'pr_number' => $prNumber,
                'job_id' => $data['job_id'] ?? null,
                'requester_id' => $data['requester_id'],
                'purpose' => $data['purpose'],
                'required_date' => $data['required_date'],
                'created_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $prId = (int) $this->db->lastInsertId();
            
            // Insert PR Items
            $stmtItem = $this->db->prepare("
                INSERT INTO pr_items (pr_id, item_id, description, qty, unit, unit_price, amount)
                VALUES (:pr_id, :item_id, :description, :qty, :unit, :unit_price, :amount)
            ");
            
            $totalAmount = 0;
            
            foreach ($items as $item) {
                $qty = $item['qty'] ?? 1;
                $price = $item['unit_price'] ?? 0;
                $amount = $qty * $price;
                
                $stmtItem->execute([
                    'pr_id' => $prId,
                    'item_id' => $item['item_id'] ?? null,
                    'description' => $item['description'],
                    'qty' => $qty,
                    'unit' => $item['unit'] ?? 'pcs',
                    'unit_price' => $price,
                    'amount' => $amount
                ]);
                
                $totalAmount += $amount;
            }
            
            // Update Total
            $this->db->prepare("UPDATE purchase_requests SET total_amount = ? WHERE id = ?")
                     ->execute([$totalAmount, $prId]);
            
            $this->audit->log('create', 'purchase_request', $prId, null, $data);
            
            $this->db->commit();
            
            return ['success' => true, 'id' => $prId, 'pr_number' => $prNumber];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Approve PR
     */
    public function approvePR(int $prId, int $approverId): array {
        try {
            $stmt = $this->db->prepare("SELECT status FROM purchase_requests WHERE id = ?");
            $stmt->execute([$prId]);
            $status = $stmt->fetchColumn();
            
            if (!in_array($status, ['Draft', 'Submitted'])) { // Simplify flow for M2
                return ['success' => false, 'error' => 'Invalid PR status for approval'];
            }
            
            $stmt = $this->db->prepare("
                UPDATE purchase_requests 
                SET status = 'Approved', approved_at = NOW(), approved_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$approverId, $prId]);
            
            $this->audit->log('approve', 'purchase_request', $prId, ['status' => $status], ['status' => 'Approved']);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Create PO from PR
     */
    public function createPOFromPR(int $prId, int $supplierId, ?string $deliveryDate = null): array {
        try {
            $this->db->beginTransaction();
            
            // Validate PR
            $stmt = $this->db->prepare("SELECT * FROM purchase_requests WHERE id = ? FOR UPDATE");
            $stmt->execute([$prId]);
            $pr = $stmt->fetch();
            
            if (!$pr || $pr['status'] !== 'Approved') {
                throw new Exception("PR must be Approved to create PO");
            }
            
            // Generate PO Number
            $poNumber = $this->docNum->generate('PO');
            
            // Create PO Header
            $stmt = $this->db->prepare("
                INSERT INTO purchase_orders 
                (po_number, pr_id, supplier_id, order_date, delivery_date, status, created_by)
                VALUES (:po_number, :pr_id, :supplier_id, CURDATE(), :delivery_date, 'Draft', :created_by)
            ");
            
            $stmt->execute([
                'po_number' => $poNumber,
                'pr_id' => $prId,
                'supplier_id' => $supplierId,
                'delivery_date' => $deliveryDate,
                'created_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $poId = (int) $this->db->lastInsertId();
            
            // Copy Items from PR to PO
            // Fetch PR items
            $stmtItems = $this->db->prepare("SELECT * FROM pr_items WHERE pr_id = ?");
            $stmtItems->execute([$prId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            
            $stmtPoItem = $this->db->prepare("
                INSERT INTO po_items (po_id, pr_item_id, item_id, description, qty, unit, unit_price, amount)
                VALUES (:po_id, :pr_item_id, :item_id, :description, :qty, :unit, :unit_price, :amount)
            ");
            
            $grandTotal = 0;
            
            foreach ($items as $item) {
                $stmtPoItem->execute([
                    'po_id' => $poId,
                    'pr_item_id' => $item['id'],
                    'item_id' => $item['item_id'],
                    'description' => $item['description'],
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'amount' => $item['amount']
                ]);
                $grandTotal += $item['amount'];
            }
            
            // Update PO Totals (Simplified VAT for now)
            $vatRate = 7.00;
            $vatAmount = $grandTotal * ($vatRate / 100);
            $finalTotal = $grandTotal + $vatAmount;
            
            $this->db->prepare("
                UPDATE purchase_orders 
                SET subtotal = ?, vat_amount = ?, grand_total = ? 
                WHERE id = ?
            ")->execute([$grandTotal, $vatAmount, $finalTotal, $poId]);
            
            $this->audit->log('create', 'purchase_order', $poId, ['pr_id' => $prId]);
            
            $this->db->commit();
            return ['success' => true, 'id' => $poId, 'po_number' => $poNumber];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Record Goods Receipt (GR)
     */
    public function recordGR(int $poId, array $items): array {
        try {
            $this->db->beginTransaction();
            
            // Validate PO
            $stmt = $this->db->prepare("SELECT * FROM purchase_orders WHERE id = ?");
            $stmt->execute([$poId]);
            $po = $stmt->fetch();
            
            if (!$po) throw new Exception("PO not found");
            
            // Generate GR Number
            $grNumber = $this->docNum->generate('GR');
            
            // Create GR Header
            $stmt = $this->db->prepare("
                INSERT INTO goods_receipts (gr_number, po_id, received_date, received_by, status, created_by)
                VALUES (:gr_number, :po_id, CURDATE(), :received_by, 'Draft', :created_by)
            ");
            $userId = $_SESSION['user_id'] ?? 1;
            $stmt->execute([
                'gr_number' => $grNumber,
                'po_id' => $poId,
                'received_by' => $userId,
                'created_by' => $userId
            ]);
            
            $grId = (int) $this->db->lastInsertId();
            
            // Insert GR Items
            $stmtItem = $this->db->prepare("
                INSERT INTO gr_items (gr_id, po_item_id, received_qty, condition_note)
                VALUES (:gr_id, :po_item_id, :qty, :note)
            ");
            
            foreach ($items as $item) {
                // Here we would check PO item balance ideally
                $stmtItem->execute([
                    'gr_id' => $grId,
                    'po_item_id' => $item['po_item_id'],
                    'qty' => $item['received_qty'],
                    'note' => $item['condition_note'] ?? null
                ]);
                
                // Update PO Item received qty
                $this->db->prepare("UPDATE po_items SET received_qty = received_qty + ? WHERE id = ?")
                         ->execute([$item['received_qty'], $item['po_item_id']]);
                         
                // Update Stock (If linked to item_id) -> M2 Goal: "Stock increase"
                $this->updateStock($item['po_item_id'], $item['received_qty']);
            }
            
            // Update PO Status to Received/Partial
            $this->db->prepare("UPDATE purchase_orders SET status = 'Received' WHERE id = ?")->execute([$poId]); // Simplified
            
            $this->audit->log('create', 'goods_receipt', $grId, ['po_id' => $poId]);
            
            $this->db->commit();
            return ['success' => true, 'id' => $grId, 'gr_number' => $grNumber];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function updateStock(int $poItemId, float $qty) {
        // Get Item ID from PO Item
        $stmt = $this->db->prepare("SELECT item_id FROM po_items WHERE id = ?");
        $stmt->execute([$poItemId]);
        $itemId = $stmt->fetchColumn();
        
        if ($itemId) {
            // Update items table (Stock On Hand)
            $sql = "UPDATE items SET quantity = quantity + ? WHERE id = ?";
            $this->db->prepare($sql)->execute([$qty, $itemId]);
        }
    }
}
