<?php
/**
 * Invoice Service
 * 4ERP - Phase M5 Accounting AR
 * 
 * Handles:
 * - Invoice creation from Job
 * - Invoice issuance
 * - Void via credit note (no DELETE)
 * 
 * agents.md compliance:
 * - §1.1-1.2: No DELETE, immutable after issued
 * - §1.5: Audit trail for all actions
 * - §3.6: Multiple invoices per job, partial payments
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/AuditLog.php';
require_once __DIR__ . '/../../core/DocumentNumber.php';

class InvoiceService {
    
    private PDO $db;
    private AuditLog $audit;
    private DocumentNumber $docNum;
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
        $this->docNum = new DocumentNumber();
    }
    
    /**
     * Create invoice from Job
     * 
     * @param int $jobId
     * @param array $lines [['description' => '', 'qty' => 1, 'unit_price' => 100], ...]
     * @param array $options ['tax_rate' => 7, 'withholding_rate' => 3, 'notes' => '']
     * @return array
     */
    public function createInvoiceFromJob(int $jobId, array $lines, array $options = []): array {
        try {
            $this->db->beginTransaction();
            
            $userId = $_SESSION['user_id'] ?? 1;
            
            // Get job and customer
            $stmt = $this->db->prepare("
                SELECT j.*, c.id as customer_id, c.name as customer_name
                FROM jobs j
                JOIN customers c ON j.customer_id = c.id
                WHERE j.id = ?
            ");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$job) {
                throw new Exception("Job not found: $jobId");
            }
            
            // Validate job status (must be ready for invoicing)
            $allowedStatuses = ['Accounting Ready', 'WH Received', 'POS Checked', 'Closed'];
            if (!in_array($job['status'], $allowedStatuses)) {
                throw new Exception("Job must be in valid status for invoicing (current: {$job['status']})");
            }
            
            // Generate invoice number
            $invoiceNo = $this->docNum->generate('INV');
            
            // Calculate totals
            $subtotal = 0;
            foreach ($lines as $line) {
                $subtotal += ($line['qty'] ?? 1) * ($line['unit_price'] ?? 0);
            }
            
            $taxRate = $options['tax_rate'] ?? 7.00;
            $taxAmount = round($subtotal * $taxRate / 100, 2);
            $withholdingRate = $options['withholding_rate'] ?? 0;
            $withholdingAmount = round($subtotal * $withholdingRate / 100, 2);
            $totalAmount = $subtotal + $taxAmount - $withholdingAmount;
            
            // Insert invoice header
            $stmt = $this->db->prepare("
                INSERT INTO ar_invoices (
                    invoice_no, job_id, customer_id,
                    invoice_date, due_date,
                    subtotal, tax_rate, tax_amount,
                    withholding_rate, withholding_amount, total_amount,
                    notes, created_by
                ) VALUES (
                    :inv_no, :job_id, :customer_id,
                    CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY),
                    :subtotal, :tax_rate, :tax_amount,
                    :wht_rate, :wht_amount, :total,
                    :notes, :user_id
                )
            ");
            
            $stmt->execute([
                ':inv_no' => $invoiceNo,
                ':job_id' => $jobId,
                ':customer_id' => $job['customer_id'],
                ':subtotal' => $subtotal,
                ':tax_rate' => $taxRate,
                ':tax_amount' => $taxAmount,
                ':wht_rate' => $withholdingRate,
                ':wht_amount' => $withholdingAmount,
                ':total' => $totalAmount,
                ':notes' => $options['notes'] ?? null,
                ':user_id' => $userId
            ]);
            
            $invoiceId = (int) $this->db->lastInsertId();
            
            // Insert invoice lines
            $lineNo = 0;
            foreach ($lines as $line) {
                $lineNo++;
                $qty = $line['qty'] ?? 1;
                $unitPrice = $line['unit_price'] ?? 0;
                $amount = $qty * $unitPrice;
                
                $stmt = $this->db->prepare("
                    INSERT INTO ar_invoice_lines (
                        invoice_id, line_no, description,
                        quantity, unit, unit_price, amount, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $invoiceId, $lineNo, $line['description'] ?? 'Item',
                    $qty, $line['unit'] ?? 'pcs', $unitPrice, $amount,
                    $line['notes'] ?? null
                ]);
            }
            
            // Audit log
            $this->audit->log(
                'create',
                'ar_invoices',
                $invoiceId,
                null,
                [
                    'invoice_no' => $invoiceNo,
                    'job_id' => $jobId,
                    'total' => $totalAmount,
                    'lines' => count($lines)
                ]
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'id' => $invoiceId,
                'invoice_no' => $invoiceNo,
                'total_amount' => $totalAmount
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Issue invoice (transitions from Draft to Issued)
     */
    public function issueInvoice(int $invoiceId): array {
        try {
            $this->db->beginTransaction();
            
            $userId = $_SESSION['user_id'] ?? 1;
            
            $invoice = $this->getById($invoiceId);
            if (!$invoice) {
                throw new Exception("Invoice not found: $invoiceId");
            }
            
            if ($invoice['status'] !== 'Draft') {
                throw new Exception("Only Draft invoices can be issued (current: {$invoice['status']})");
            }
            
            $stmt = $this->db->prepare("
                UPDATE ar_invoices 
                SET status = 'Issued', issued_at = NOW(), issued_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$userId, $invoiceId]);
            
            $this->audit->log(
                'issue',
                'ar_invoices',
                $invoiceId,
                ['status' => 'Draft'],
                ['status' => 'Issued', 'issued_by' => $userId]
            );
            
            $this->db->commit();
            
            return ['success' => true, 'invoice_no' => $invoice['invoice_no']];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Void invoice via credit note (agents.md §1.3 - reversal pattern)
     * Does NOT delete the invoice, creates a credit note reference
     */
    public function voidInvoice(int $invoiceId, string $reason): array {
        try {
            $this->db->beginTransaction();
            
            $userId = $_SESSION['user_id'] ?? 1;
            
            $invoice = $this->getById($invoiceId);
            if (!$invoice) {
                throw new Exception("Invoice not found: $invoiceId");
            }
            
            if ($invoice['status'] === 'Voided') {
                throw new Exception("Invoice already voided");
            }
            
            if ((float)$invoice['paid_amount'] > 0) {
                throw new Exception("Cannot void invoice with payments. Reverse payments first.");
            }
            
            // Create credit note
            $cnNo = $this->docNum->generate('CN');
            
            $stmt = $this->db->prepare("
                INSERT INTO ar_credit_notes (
                    credit_note_no, invoice_id, amount, reason, status, created_by, issued_at, issued_by
                ) VALUES (?, ?, ?, ?, 'Issued', ?, NOW(), ?)
            ");
            $stmt->execute([$cnNo, $invoiceId, $invoice['total_amount'], $reason, $userId, $userId]);
            $cnId = (int) $this->db->lastInsertId();
            
            // Update invoice status to Voided
            $stmt = $this->db->prepare("
                UPDATE ar_invoices 
                SET status = 'Voided', voided_by_credit_note_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$cnId, $invoiceId]);
            
            $this->audit->log(
                'void',
                'ar_invoices',
                $invoiceId,
                ['status' => $invoice['status']],
                ['status' => 'Voided', 'credit_note_id' => $cnId, 'reason' => $reason]
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'credit_note_id' => $cnId,
                'credit_note_no' => $cnNo
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Update invoice paid amount and status
     */
    public function updatePaidAmount(int $invoiceId, float $paymentAmount): void {
        $invoice = $this->getById($invoiceId);
        if (!$invoice) return;
        
        $newPaidAmount = (float)$invoice['paid_amount'] + $paymentAmount;
        $totalAmount = (float)$invoice['total_amount'];
        
        $newStatus = 'Issued';
        if ($newPaidAmount >= $totalAmount) {
            $newStatus = 'Paid';
        } elseif ($newPaidAmount > 0) {
            $newStatus = 'Partial';
        }
        
        $stmt = $this->db->prepare("
            UPDATE ar_invoices SET paid_amount = ?, status = ? WHERE id = ?
        ");
        $stmt->execute([$newPaidAmount, $newStatus, $invoiceId]);
    }
    
    /**
     * Get invoice by ID
     */
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM ar_invoices WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get invoice lines
     */
    public function getLines(int $invoiceId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM ar_invoice_lines WHERE invoice_id = ? ORDER BY line_no
        ");
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Compute invoice balance
     */
    public function getBalance(int $invoiceId): float {
        $invoice = $this->getById($invoiceId);
        if (!$invoice) return 0;
        return (float)$invoice['total_amount'] - (float)$invoice['paid_amount'];
    }
}
