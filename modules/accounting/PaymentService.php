<?php
/**
 * Payment Service
 * ERP v2 - Phase M5 Accounting AR
 * 
 * Handles:
 * - Payment recording
 * - Payment reversal (no DELETE)
 * - Balance computation
 * 
 * agents.md compliance:
 * - §1.1: No DELETE on payments
 * - §1.3: Reversal pattern for corrections
 * - §1.5: Audit trail for all actions
 * - §3.6: Partial payments supported
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../core/AuditLog.php';
require_once __DIR__ . '/../../core/DocumentNumber.php';
require_once __DIR__ . '/InvoiceService.php';

class PaymentService {
    
    private PDO $db;
    private AuditLog $audit;
    private DocumentNumber $docNum;
    private InvoiceService $invoiceService;
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
        $this->docNum = new DocumentNumber();
        $this->invoiceService = new InvoiceService();
    }
    
    /**
     * Record a payment against an invoice
     * 
     * @param int $invoiceId
     * @param float $amount
     * @param string $method Cash, Bank Transfer, Cheque, Credit Card, Other
     * @param string|null $paymentDate Y-m-d format
     * @param string|null $referenceNo
     * @return array
     */
    public function recordPayment(
        int $invoiceId,
        float $amount,
        string $method = 'Bank Transfer',
        ?string $paymentDate = null,
        ?string $referenceNo = null,
        ?string $notes = null
    ): array {
        try {
            $this->db->beginTransaction();
            
            $userId = $_SESSION['user_id'] ?? 1;
            
            // Validate invoice
            $invoice = $this->invoiceService->getById($invoiceId);
            if (!$invoice) {
                throw new Exception("Invoice not found: $invoiceId");
            }
            
            if ($invoice['status'] === 'Voided') {
                throw new Exception("Cannot pay a voided invoice");
            }
            
            if ($invoice['status'] === 'Draft') {
                throw new Exception("Invoice must be issued before payments can be recorded");
            }
            
            // Validate amount
            $balance = $this->invoiceService->getBalance($invoiceId);
            if ($amount > $balance + 0.01) {
                throw new Exception("Payment amount ($amount) exceeds invoice balance ($balance)");
            }
            
            if ($amount <= 0) {
                throw new Exception("Payment amount must be positive");
            }
            
            // Generate payment number
            $paymentNo = $this->docNum->generate('PAY');
            
            // Insert payment
            $stmt = $this->db->prepare("
                INSERT INTO payments (
                    payment_no, invoice_id, amount, payment_method,
                    payment_date, reference_no, notes, created_by
                ) VALUES (
                    :pay_no, :inv_id, :amount, :method,
                    :pay_date, :ref_no, :notes, :user_id
                )
            ");
            
            $stmt->execute([
                ':pay_no' => $paymentNo,
                ':inv_id' => $invoiceId,
                ':amount' => $amount,
                ':method' => $method,
                ':pay_date' => $paymentDate ?? date('Y-m-d'),
                ':ref_no' => $referenceNo,
                ':notes' => $notes,
                ':user_id' => $userId
            ]);
            
            $paymentId = (int) $this->db->lastInsertId();
            
            // Update invoice paid amount
            $this->invoiceService->updatePaidAmount($invoiceId, $amount);
            
            // Audit log
            $this->audit->log(
                'payment',
                'payments',
                $paymentId,
                null,
                [
                    'payment_no' => $paymentNo,
                    'invoice_id' => $invoiceId,
                    'amount' => $amount,
                    'method' => $method
                ]
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'id' => $paymentId,
                'payment_no' => $paymentNo,
                'new_balance' => $this->invoiceService->getBalance($invoiceId)
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Reverse a payment (agents.md §1.3 - reversal pattern)
     * Creates a new negative entry, does NOT delete original
     */
    public function reversePayment(int $paymentId, string $reason): array {
        try {
            $this->db->beginTransaction();
            
            $userId = $_SESSION['user_id'] ?? 1;
            
            // Get original payment
            $original = $this->getById($paymentId);
            if (!$original) {
                throw new Exception("Payment not found: $paymentId");
            }
            
            if ($original['status'] === 'Reversed') {
                throw new Exception("Payment already reversed");
            }
            
            if ($original['reverse_of_id']) {
                throw new Exception("Cannot reverse a reversal entry");
            }
            
            // Generate reversal payment number
            $reversalNo = $this->docNum->generate('PAY');
            
            // Create reversal entry (negative amount)
            $stmt = $this->db->prepare("
                INSERT INTO payments (
                    payment_no, invoice_id, amount, payment_method,
                    payment_date, status, reverse_of_id, reversal_reason,
                    notes, created_by
                ) VALUES (
                    :pay_no, :inv_id, :amount, :method,
                    CURDATE(), 'Posted', :reverse_of, :reason,
                    :notes, :user_id
                )
            ");
            
            $stmt->execute([
                ':pay_no' => $reversalNo,
                ':inv_id' => $original['invoice_id'],
                ':amount' => -1 * (float)$original['amount'], // Negative
                ':method' => $original['payment_method'],
                ':reverse_of' => $paymentId,
                ':reason' => $reason,
                ':notes' => "REVERSAL of {$original['payment_no']}: $reason",
                ':user_id' => $userId
            ]);
            
            $reversalId = (int) $this->db->lastInsertId();
            
            // Mark original as reversed
            $stmt = $this->db->prepare("
                UPDATE payments 
                SET status = 'Reversed', reversed_by_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$reversalId, $paymentId]);
            
            // Update invoice paid amount (subtract the reversed amount)
            $this->invoiceService->updatePaidAmount(
                (int)$original['invoice_id'],
                -1 * (float)$original['amount']
            );
            
            // Audit log
            $this->audit->log(
                'reverse_payment',
                'payments',
                $reversalId,
                ['original_payment_id' => $paymentId],
                [
                    'reversal_no' => $reversalNo,
                    'original_no' => $original['payment_no'],
                    'amount' => -1 * (float)$original['amount'],
                    'reason' => $reason
                ]
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'reversal_id' => $reversalId,
                'reversal_no' => $reversalNo,
                'new_balance' => $this->invoiceService->getBalance((int)$original['invoice_id'])
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get payment by ID
     */
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get payments for an invoice
     */
    public function getPaymentsForInvoice(int $invoiceId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM payments 
            WHERE invoice_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Compute invoice balance
     */
    public function computeInvoiceBalance(int $invoiceId): float {
        return $this->invoiceService->getBalance($invoiceId);
    }
}
