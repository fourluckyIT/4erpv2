<?php
/**
 * AP Invoice Service
 * 4ERP - M12: Accounts Payable
 *
 * Handles:
 * - AP Invoice creation from GR/PO
 * - Invoice submission and approval
 * - 3-Way Matching (PO/GR/Invoice)
 * - Void via debit note (no DELETE)
 *
 * agents.md compliance:
 * - §1.1-1.2: No DELETE, immutable after approved
 * - §1.5: Audit trail for all actions
 * - §3.6: Multiple invoices per PO, partial payments
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/DocumentNumber.php';

class APInvoice {

    private PDO $db;
    private AuditLog $audit;
    private DocumentNumber $docNum;

    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
        $this->docNum = new DocumentNumber();
    }

    /**
     * Create AP Invoice from GR
     */
    public function createFromGR(int $grId, array $data): array {
        try {
            $this->db->beginTransaction();

            // Get GR details
            $stmt = $this->db->prepare("
                SELECT gr.*, po.supplier_id, po.id as po_id, po.total_amount as po_amount,
                       s.payment_terms
                FROM goods_receipts gr
                LEFT JOIN purchase_orders po ON gr.po_id = po.id
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                WHERE gr.id = ?
            ");
            $stmt->execute([$grId]);
            $gr = $stmt->fetch();

            if (!$gr) {
                throw new Exception("GR not found");
            }

            // Generate invoice number
            $invoiceNo = $this->docNum->generate('API');

            // Calculate due date from payment terms
            $paymentTerms = $gr['payment_terms'] ?? 30;
            $invoiceDate = $data['invoice_date'] ?? date('Y-m-d');
            $dueDate = $data['due_date'] ?? date('Y-m-d', strtotime("+{$paymentTerms} days", strtotime($invoiceDate)));

            // Calculate amounts
            $subtotal = (float)($data['subtotal'] ?? $gr['total_amount'] ?? 0);
            $taxRate = (float)($data['tax_rate'] ?? 7.00);
            $taxAmount = $subtotal * ($taxRate / 100);
            $withholdingRate = (float)($data['withholding_rate'] ?? 0);
            $withholdingAmount = $subtotal * ($withholdingRate / 100);
            $totalAmount = $subtotal + $taxAmount - $withholdingAmount;

            // Insert AP Invoice
            $stmt = $this->db->prepare("
                INSERT INTO ap_invoices (
                    invoice_no, supplier_invoice_no, supplier_id, po_id, gr_id, job_id,
                    invoice_date, received_date, due_date,
                    subtotal, tax_rate, tax_amount, withholding_rate, withholding_amount, total_amount,
                    status, po_amount, gr_amount, matching_status,
                    notes, created_by
                ) VALUES (
                    :invoice_no, :supplier_invoice_no, :supplier_id, :po_id, :gr_id, :job_id,
                    :invoice_date, :received_date, :due_date,
                    :subtotal, :tax_rate, :tax_amount, :withholding_rate, :withholding_amount, :total_amount,
                    'Draft', :po_amount, :gr_amount, 'Pending',
                    :notes, :created_by
                )
            ");

            $stmt->execute([
                'invoice_no' => $invoiceNo,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'supplier_id' => $gr['supplier_id'],
                'po_id' => $gr['po_id'],
                'gr_id' => $grId,
                'job_id' => $data['job_id'] ?? null,
                'invoice_date' => $invoiceDate,
                'received_date' => $data['received_date'] ?? date('Y-m-d'),
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'withholding_rate' => $withholdingRate,
                'withholding_amount' => $withholdingAmount,
                'total_amount' => $totalAmount,
                'po_amount' => $gr['po_amount'] ?? null,
                'gr_amount' => $gr['total_amount'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $_SESSION['user_id'] ?? 1
            ]);

            $invoiceId = (int)$this->db->lastInsertId();

            // Copy GR items to invoice lines
            $this->copyGRItemsToInvoice($invoiceId, $grId);

            // Perform 3-way matching
            $this->performMatching($invoiceId);

            $this->audit->log('create', 'ap_invoice', $invoiceId, null, [
                'invoice_no' => $invoiceNo,
                'gr_id' => $grId,
                'total_amount' => $totalAmount
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'id' => $invoiceId,
                'invoice_no' => $invoiceNo
            ];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create AP Invoice manually (without GR)
     */
    public function create(array $data, array $lines): array {
        try {
            $this->db->beginTransaction();

            // Generate invoice number
            $invoiceNo = $this->docNum->generate('API');

            // Get supplier payment terms
            $paymentTerms = 30;
            if (!empty($data['supplier_id'])) {
                $stmt = $this->db->prepare("SELECT payment_terms FROM suppliers WHERE id = ?");
                $stmt->execute([$data['supplier_id']]);
                $supplier = $stmt->fetch();
                $paymentTerms = $supplier['payment_terms'] ?? 30;
            }

            $invoiceDate = $data['invoice_date'] ?? date('Y-m-d');
            $dueDate = $data['due_date'] ?? date('Y-m-d', strtotime("+{$paymentTerms} days", strtotime($invoiceDate)));

            // Calculate line totals
            $subtotal = 0;
            foreach ($lines as $line) {
                $subtotal += (float)($line['quantity'] ?? 1) * (float)($line['unit_price'] ?? 0);
            }

            $taxRate = (float)($data['tax_rate'] ?? 7.00);
            $taxAmount = $subtotal * ($taxRate / 100);
            $withholdingRate = (float)($data['withholding_rate'] ?? 0);
            $withholdingAmount = $subtotal * ($withholdingRate / 100);
            $totalAmount = $subtotal + $taxAmount - $withholdingAmount;

            // Insert AP Invoice
            $stmt = $this->db->prepare("
                INSERT INTO ap_invoices (
                    invoice_no, supplier_invoice_no, supplier_id, po_id, gr_id, job_id,
                    invoice_date, received_date, due_date,
                    subtotal, tax_rate, tax_amount, withholding_rate, withholding_amount, total_amount,
                    status, matching_status, notes, created_by
                ) VALUES (
                    :invoice_no, :supplier_invoice_no, :supplier_id, :po_id, :gr_id, :job_id,
                    :invoice_date, :received_date, :due_date,
                    :subtotal, :tax_rate, :tax_amount, :withholding_rate, :withholding_amount, :total_amount,
                    'Draft', 'Pending', :notes, :created_by
                )
            ");

            $stmt->execute([
                'invoice_no' => $invoiceNo,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'po_id' => $data['po_id'] ?? null,
                'gr_id' => $data['gr_id'] ?? null,
                'job_id' => $data['job_id'] ?? null,
                'invoice_date' => $invoiceDate,
                'received_date' => $data['received_date'] ?? date('Y-m-d'),
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'withholding_rate' => $withholdingRate,
                'withholding_amount' => $withholdingAmount,
                'total_amount' => $totalAmount,
                'notes' => $data['notes'] ?? null,
                'created_by' => $_SESSION['user_id'] ?? 1
            ]);

            $invoiceId = (int)$this->db->lastInsertId();

            // Insert lines
            $this->insertLines($invoiceId, $lines);

            $this->audit->log('create', 'ap_invoice', $invoiceId, null, [
                'invoice_no' => $invoiceNo,
                'total_amount' => $totalAmount
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'id' => $invoiceId,
                'invoice_no' => $invoiceNo
            ];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Submit invoice for approval
     */
    public function submit(int $id): array {
        try {
            $invoice = $this->getById($id);
            if (!$invoice) {
                return ['success' => false, 'error' => 'Invoice not found'];
            }

            if ($invoice['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'Only Draft invoices can be submitted'];
            }

            $stmt = $this->db->prepare("
                UPDATE ap_invoices 
                SET status = 'Submitted', submitted_at = NOW(), submitted_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'] ?? 1, $id]);

            $this->audit->log('submit', 'ap_invoice', $id, 
                ['status' => 'Draft'], 
                ['status' => 'Submitted']
            );

            return ['success' => true];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Approve invoice
     */
    public function approve(int $id, ?string $notes = null): array {
        try {
            $invoice = $this->getById($id);
            if (!$invoice) {
                return ['success' => false, 'error' => 'Invoice not found'];
            }

            if ($invoice['status'] !== 'Submitted') {
                return ['success' => false, 'error' => 'Only Submitted invoices can be approved'];
            }

            // Check matching status
            if ($invoice['matching_status'] === 'Variance') {
                // Allow override with notes
                if (empty($notes)) {
                    return ['success' => false, 'error' => 'Variance detected. Please provide override notes.'];
                }
                
                $stmt = $this->db->prepare("
                    UPDATE ap_invoices 
                    SET matching_status = 'Override', matching_notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$notes, $id]);
            }

            $stmt = $this->db->prepare("
                UPDATE ap_invoices 
                SET status = 'Approved', approved_at = NOW(), approved_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'] ?? 1, $id]);

            $this->audit->log('approve', 'ap_invoice', $id, 
                ['status' => 'Submitted'], 
                ['status' => 'Approved', 'notes' => $notes]
            );

            // Update job cost if linked
            if ($invoice['job_id']) {
                $this->updateJobCost($id);
            }

            return ['success' => true];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Void invoice via debit note
     */
    public function void(int $id, string $reason): array {
        try {
            $this->db->beginTransaction();

            $invoice = $this->getById($id);
            if (!$invoice) {
                throw new Exception('Invoice not found');
            }

            if (in_array($invoice['status'], ['Paid', 'Voided'])) {
                throw new Exception('Cannot void Paid or already Voided invoice');
            }

            if ($invoice['paid_amount'] > 0) {
                throw new Exception('Cannot void invoice with payments. Reverse payments first.');
            }

            // Create debit note
            $dnNo = $this->docNum->generate('ADN');
            
            $stmt = $this->db->prepare("
                INSERT INTO ap_debit_notes (debit_note_no, invoice_id, amount, reason, status, created_by, issued_at, issued_by)
                VALUES (?, ?, ?, ?, 'Issued', ?, NOW(), ?)
            ");
            $stmt->execute([
                $dnNo,
                $id,
                $invoice['total_amount'],
                $reason,
                $_SESSION['user_id'] ?? 1,
                $_SESSION['user_id'] ?? 1
            ]);
            
            $dnId = (int)$this->db->lastInsertId();

            // Update invoice
            $stmt = $this->db->prepare("
                UPDATE ap_invoices 
                SET status = 'Voided', voided_at = NOW(), voided_by = ?, 
                    void_reason = ?, voided_by_debit_note_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'] ?? 1, $reason, $dnId, $id]);

            $this->audit->log('void', 'ap_invoice', $id, 
                ['status' => $invoice['status']], 
                ['status' => 'Voided', 'reason' => $reason, 'debit_note_id' => $dnId]
            );

            $this->db->commit();

            return ['success' => true, 'debit_note_id' => $dnId, 'debit_note_no' => $dnNo];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update paid amount (called from APPayment)
     */
    public function updatePaidAmount(int $id, float $amount): array {
        try {
            $invoice = $this->getById($id);
            if (!$invoice) {
                return ['success' => false, 'error' => 'Invoice not found'];
            }

            $newPaidAmount = (float)$invoice['paid_amount'] + $amount;
            $totalAmount = (float)$invoice['total_amount'];

            // Determine new status
            $newStatus = $invoice['status'];
            if ($newPaidAmount >= $totalAmount) {
                $newStatus = 'Paid';
            } elseif ($newPaidAmount > 0 && in_array($invoice['status'], ['Approved', 'Partial'])) {
                $newStatus = 'Partial';
            }

            $stmt = $this->db->prepare("
                UPDATE ap_invoices SET paid_amount = ?, status = ? WHERE id = ?
            ");
            $stmt->execute([$newPaidAmount, $newStatus, $id]);

            return ['success' => true, 'new_status' => $newStatus];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Perform 3-way matching
     */
    public function performMatching(int $id): array {
        try {
            $invoice = $this->getById($id);
            if (!$invoice) {
                return ['success' => false, 'error' => 'Invoice not found'];
            }

            $poAmount = (float)($invoice['po_amount'] ?? 0);
            $grAmount = (float)($invoice['gr_amount'] ?? 0);
            $invoiceAmount = (float)$invoice['total_amount'];

            // If no PO/GR linked, skip matching
            if ($poAmount == 0 && $grAmount == 0) {
                $stmt = $this->db->prepare("
                    UPDATE ap_invoices SET matching_status = 'Matched' WHERE id = ?
                ");
                $stmt->execute([$id]);
                return ['success' => true, 'status' => 'Matched', 'message' => 'No PO/GR to match'];
            }

            // Calculate variance (allow 1% tolerance)
            $tolerance = 0.01;
            $matchStatus = 'Matched';
            $notes = [];

            if ($poAmount > 0) {
                $poVariance = abs($invoiceAmount - $poAmount) / $poAmount;
                if ($poVariance > $tolerance) {
                    $matchStatus = 'Variance';
                    $notes[] = sprintf("PO variance: %.2f%%", $poVariance * 100);
                }
            }

            if ($grAmount > 0) {
                $grVariance = abs($invoiceAmount - $grAmount) / $grAmount;
                if ($grVariance > $tolerance) {
                    $matchStatus = 'Variance';
                    $notes[] = sprintf("GR variance: %.2f%%", $grVariance * 100);
                }
            }

            $stmt = $this->db->prepare("
                UPDATE ap_invoices SET matching_status = ?, matching_notes = ? WHERE id = ?
            ");
            $stmt->execute([$matchStatus, implode('; ', $notes) ?: null, $id]);

            return [
                'success' => true, 
                'status' => $matchStatus,
                'notes' => $notes
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update job cost from approved invoice
     */
    private function updateJobCost(int $invoiceId): void {
        $invoice = $this->getById($invoiceId);
        if (!$invoice || !$invoice['job_id']) return;

        // Get invoice lines by cost type
        $lines = $this->getLines($invoiceId);
        
        require_once __DIR__ . '/Costing.php';
        $costing = new Costing();

        foreach ($lines as $line) {
            $costing->addCostLine(
                $invoice['job_id'],
                $line['cost_type'] ?? 'Material',
                $line['description'],
                (float)$line['quantity'],
                (float)$line['unit_price'],
                $invoice['invoice_date'],
                $line['unit'],
                'AP_INVOICE',
                $invoiceId,
                "From AP Invoice: {$invoice['invoice_no']}"
            );
        }
    }

    /**
     * Copy GR items to invoice lines
     */
    private function copyGRItemsToInvoice(int $invoiceId, int $grId): void {
        $stmt = $this->db->prepare("
            SELECT gi.*, poi.id as po_item_id
            FROM gr_items gi
            LEFT JOIN po_items poi ON gi.po_item_id = poi.id
            WHERE gi.gr_id = ?
        ");
        $stmt->execute([$grId]);
        $grItems = $stmt->fetchAll();

        $lineNo = 1;
        $insertStmt = $this->db->prepare("
            INSERT INTO ap_invoice_lines (
                invoice_id, line_no, po_item_id, gr_item_id, item_id,
                description, quantity, unit, unit_price, amount, cost_type
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($grItems as $item) {
            $qty = (float)($item['received_qty'] ?? $item['qty'] ?? 1);
            $price = (float)($item['unit_price'] ?? 0);
            $amount = $qty * $price;

            $insertStmt->execute([
                $invoiceId,
                $lineNo++,
                $item['po_item_id'] ?? null,
                $item['id'],
                $item['item_id'] ?? null,
                $item['description'] ?? $item['item_name'] ?? 'Item',
                $qty,
                $item['unit'] ?? 'pcs',
                $price,
                $amount,
                'Material'
            ]);
        }
    }

    /**
     * Insert invoice lines
     */
    private function insertLines(int $invoiceId, array $lines): void {
        $lineNo = 1;
        $stmt = $this->db->prepare("
            INSERT INTO ap_invoice_lines (
                invoice_id, line_no, item_id, description, quantity, unit, unit_price, amount, cost_type
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($lines as $line) {
            $qty = (float)($line['quantity'] ?? 1);
            $price = (float)($line['unit_price'] ?? 0);
            $amount = $qty * $price;

            $stmt->execute([
                $invoiceId,
                $lineNo++,
                $line['item_id'] ?? null,
                $line['description'],
                $qty,
                $line['unit'] ?? 'pcs',
                $price,
                $amount,
                $line['cost_type'] ?? 'Material'
            ]);
        }
    }

    // ==================== GETTERS ====================

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT api.*, s.name as supplier_name, s.code as supplier_code,
                   po.po_number, gr.gr_number,
                   j.job_number, j.scope_short as job_scope
            FROM ap_invoices api
            LEFT JOIN suppliers s ON api.supplier_id = s.id
            LEFT JOIN purchase_orders po ON api.po_id = po.id
            LEFT JOIN goods_receipts gr ON api.gr_id = gr.id
            LEFT JOIN jobs j ON api.job_id = j.id
            WHERE api.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getLines(int $invoiceId): array {
        $stmt = $this->db->prepare("
            SELECT apil.*, i.code as item_code, i.name as item_name
            FROM ap_invoice_lines apil
            LEFT JOIN items i ON apil.item_id = i.id
            WHERE apil.invoice_id = ?
            ORDER BY apil.line_no
        ");
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    public function getBalance(int $id): float {
        $invoice = $this->getById($id);
        if (!$invoice) return 0;
        return (float)$invoice['total_amount'] - (float)$invoice['paid_amount'];
    }

    public function getList(array $filters = []): array {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'api.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['supplier_id'])) {
            $where[] = 'api.supplier_id = ?';
            $params[] = $filters['supplier_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'api.invoice_date >= ?';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'api.invoice_date <= ?';
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['overdue'])) {
            $where[] = 'api.due_date < CURDATE() AND api.status NOT IN ("Paid", "Voided")';
        }

        $sql = "
            SELECT api.*, s.name as supplier_name
            FROM ap_invoices api
            LEFT JOIN suppliers s ON api.supplier_id = s.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY api.created_at DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get AP Aging report
     */
    public function getAgingReport(): array {
        $sql = "
            SELECT 
                s.id as supplier_id,
                s.name as supplier_name,
                SUM(CASE WHEN DATEDIFF(CURDATE(), api.due_date) <= 0 THEN api.total_amount - api.paid_amount ELSE 0 END) as current_amount,
                SUM(CASE WHEN DATEDIFF(CURDATE(), api.due_date) BETWEEN 1 AND 30 THEN api.total_amount - api.paid_amount ELSE 0 END) as days_1_30,
                SUM(CASE WHEN DATEDIFF(CURDATE(), api.due_date) BETWEEN 31 AND 60 THEN api.total_amount - api.paid_amount ELSE 0 END) as days_31_60,
                SUM(CASE WHEN DATEDIFF(CURDATE(), api.due_date) BETWEEN 61 AND 90 THEN api.total_amount - api.paid_amount ELSE 0 END) as days_61_90,
                SUM(CASE WHEN DATEDIFF(CURDATE(), api.due_date) > 90 THEN api.total_amount - api.paid_amount ELSE 0 END) as days_over_90,
                SUM(api.total_amount - api.paid_amount) as total_outstanding
            FROM ap_invoices api
            JOIN suppliers s ON api.supplier_id = s.id
            WHERE api.status IN ('Approved', 'Partial')
            GROUP BY s.id, s.name
            HAVING total_outstanding > 0
            ORDER BY total_outstanding DESC
        ";

        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }
}
