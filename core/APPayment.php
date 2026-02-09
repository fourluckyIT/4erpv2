<?php
/**
 * AP Payment Service
 * 4ERP - M12: Accounts Payable
 *
 * Handles:
 * - Payment request with approval workflow
 * - Payment posting
 * - Payment reversal (no DELETE)
 * - Partial payments
 *
 * agents.md compliance:
 * - §1.1: No DELETE on payments
 * - §1.3: Reversal pattern for corrections
 * - §1.5: Audit trail for all actions
 * - §3.6: Partial payments supported
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/DocumentNumber.php';
require_once __DIR__ . '/APInvoice.php';

class APPayment {

    private PDO $db;
    private AuditLog $audit;
    private DocumentNumber $docNum;
    private APInvoice $invoiceService;

    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
        $this->docNum = new DocumentNumber();
        $this->invoiceService = new APInvoice();
    }

    /**
     * Request payment (creates pending payment)
     */
    public function requestPayment(int $invoiceId, array $data): array {
        try {
            $this->db->beginTransaction();

            $invoice = $this->invoiceService->getById($invoiceId);
            if (!$invoice) {
                throw new Exception('Invoice not found');
            }

            if (!in_array($invoice['status'], ['Approved', 'Partial'])) {
                throw new Exception('Invoice must be Approved or Partial to make payment');
            }

            // Check balance
            $balance = $this->invoiceService->getBalance($invoiceId);
            $amount = (float)$data['amount'];

            if ($amount <= 0) {
                throw new Exception('Payment amount must be greater than 0');
            }

            if ($amount > $balance) {
                throw new Exception("Payment amount ({$amount}) exceeds balance ({$balance})");
            }

            // Generate payment number
            $paymentNo = $this->docNum->generate('APP');

            // Insert payment with Pending status
            $stmt = $this->db->prepare("
                INSERT INTO ap_payments (
                    payment_no, invoice_id, amount, payment_method, payment_date,
                    reference_no, bank_account, status,
                    requested_by, notes, created_by
                ) VALUES (
                    :payment_no, :invoice_id, :amount, :payment_method, :payment_date,
                    :reference_no, :bank_account, 'Pending',
                    :requested_by, :notes, :created_by
                )
            ");

            $userId = $_SESSION['user_id'] ?? 1;

            $stmt->execute([
                'payment_no' => $paymentNo,
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'payment_method' => $data['payment_method'] ?? 'Bank Transfer',
                'payment_date' => $data['payment_date'] ?? date('Y-m-d'),
                'reference_no' => $data['reference_no'] ?? null,
                'bank_account' => $data['bank_account'] ?? null,
                'requested_by' => $userId,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId
            ]);

            $paymentId = (int)$this->db->lastInsertId();

            $this->audit->log('request', 'ap_payment', $paymentId, null, [
                'payment_no' => $paymentNo,
                'invoice_id' => $invoiceId,
                'amount' => $amount
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'id' => $paymentId,
                'payment_no' => $paymentNo,
                'status' => 'Pending'
            ];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Approve payment request
     */
    public function approve(int $paymentId, ?string $notes = null): array {
        try {
            $payment = $this->getById($paymentId);
            if (!$payment) {
                return ['success' => false, 'error' => 'Payment not found'];
            }

            if ($payment['status'] !== 'Pending') {
                return ['success' => false, 'error' => 'Only Pending payments can be approved'];
            }

            $stmt = $this->db->prepare("
                UPDATE ap_payments 
                SET status = 'Approved', approved_by = ?, approved_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'] ?? 1, $paymentId]);

            $this->audit->log('approve', 'ap_payment', $paymentId, 
                ['status' => 'Pending'], 
                ['status' => 'Approved', 'notes' => $notes]
            );

            return ['success' => true, 'status' => 'Approved'];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Reject payment request
     */
    public function reject(int $paymentId, string $reason): array {
        try {
            $payment = $this->getById($paymentId);
            if (!$payment) {
                return ['success' => false, 'error' => 'Payment not found'];
            }

            if ($payment['status'] !== 'Pending') {
                return ['success' => false, 'error' => 'Only Pending payments can be rejected'];
            }

            // Instead of deleting, we mark as Reversed with reason
            $stmt = $this->db->prepare("
                UPDATE ap_payments 
                SET status = 'Reversed', reversal_reason = ?, reversed_at = NOW(), reversed_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$reason, $_SESSION['user_id'] ?? 1, $paymentId]);

            $this->audit->log('reject', 'ap_payment', $paymentId, 
                ['status' => 'Pending'], 
                ['status' => 'Reversed', 'reason' => $reason]
            );

            return ['success' => true];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Post approved payment (actually apply to invoice)
     */
    public function post(int $paymentId): array {
        try {
            $this->db->beginTransaction();

            $payment = $this->getById($paymentId);
            if (!$payment) {
                throw new Exception('Payment not found');
            }

            if ($payment['status'] !== 'Approved') {
                throw new Exception('Only Approved payments can be posted');
            }

            // Update payment status
            $stmt = $this->db->prepare("
                UPDATE ap_payments 
                SET status = 'Posted', posted_by = ?, posted_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'] ?? 1, $paymentId]);

            // Update invoice paid amount
            $result = $this->invoiceService->updatePaidAmount(
                $payment['invoice_id'], 
                (float)$payment['amount']
            );

            if (!$result['success']) {
                throw new Exception($result['error']);
            }

            $this->audit->log('post', 'ap_payment', $paymentId, 
                ['status' => 'Approved'], 
                ['status' => 'Posted', 'invoice_status' => $result['new_status']]
            );

            $this->db->commit();

            return [
                'success' => true, 
                'status' => 'Posted',
                'invoice_status' => $result['new_status']
            ];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Reverse a posted payment
     */
    public function reverse(int $paymentId, string $reason): array {
        try {
            $this->db->beginTransaction();

            $payment = $this->getById($paymentId);
            if (!$payment) {
                throw new Exception('Payment not found');
            }

            if ($payment['status'] !== 'Posted') {
                throw new Exception('Only Posted payments can be reversed');
            }

            // Create reversal entry
            $reversalNo = $this->docNum->generate('APP');

            $stmt = $this->db->prepare("
                INSERT INTO ap_payments (
                    payment_no, invoice_id, amount, payment_method, payment_date,
                    reference_no, bank_account, status,
                    reverse_of_id, reversal_reason,
                    requested_by, created_by
                ) VALUES (
                    :payment_no, :invoice_id, :amount, :payment_method, CURDATE(),
                    :reference_no, :bank_account, 'Posted',
                    :reverse_of_id, :reversal_reason,
                    :requested_by, :created_by
                )
            ");

            $userId = $_SESSION['user_id'] ?? 1;

            $stmt->execute([
                'payment_no' => $reversalNo,
                'invoice_id' => $payment['invoice_id'],
                'amount' => -$payment['amount'], // Negative amount for reversal
                'payment_method' => $payment['payment_method'],
                'reference_no' => "REV:{$payment['payment_no']}",
                'bank_account' => $payment['bank_account'],
                'reverse_of_id' => $paymentId,
                'reversal_reason' => $reason,
                'requested_by' => $userId,
                'created_by' => $userId
            ]);

            $reversalId = (int)$this->db->lastInsertId();

            // Mark original as reversed
            $stmt = $this->db->prepare("
                UPDATE ap_payments 
                SET status = 'Reversed', reversed_by_id = ?, reversal_reason = ?,
                    reversed_at = NOW(), reversed_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$reversalId, $reason, $userId, $paymentId]);

            // Update invoice paid amount (subtract)
            $result = $this->invoiceService->updatePaidAmount(
                $payment['invoice_id'], 
                -(float)$payment['amount']
            );

            $this->audit->log('reverse', 'ap_payment', $paymentId, 
                ['status' => 'Posted'], 
                ['status' => 'Reversed', 'reason' => $reason, 'reversal_id' => $reversalId]
            );

            $this->db->commit();

            return [
                'success' => true,
                'reversal_id' => $reversalId,
                'reversal_no' => $reversalNo
            ];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ==================== GETTERS ====================

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT app.*, 
                   api.invoice_no, api.supplier_invoice_no, api.total_amount as invoice_total,
                   s.name as supplier_name,
                   req.username as requested_by_name,
                   appr.username as approved_by_name,
                   post.username as posted_by_name
            FROM ap_payments app
            JOIN ap_invoices api ON app.invoice_id = api.id
            LEFT JOIN suppliers s ON api.supplier_id = s.id
            LEFT JOIN users req ON app.requested_by = req.id
            LEFT JOIN users appr ON app.approved_by = appr.id
            LEFT JOIN users post ON app.posted_by = post.id
            WHERE app.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getPaymentsForInvoice(int $invoiceId): array {
        $stmt = $this->db->prepare("
            SELECT app.*, 
                   req.username as requested_by_name,
                   appr.username as approved_by_name
            FROM ap_payments app
            LEFT JOIN users req ON app.requested_by = req.id
            LEFT JOIN users appr ON app.approved_by = appr.id
            WHERE app.invoice_id = ? AND app.amount > 0
            ORDER BY app.created_at DESC
        ");
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    public function getList(array $filters = []): array {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'app.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['invoice_id'])) {
            $where[] = 'app.invoice_id = ?';
            $params[] = $filters['invoice_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'app.payment_date >= ?';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'app.payment_date <= ?';
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['pending_approval'])) {
            $where[] = 'app.status = "Pending"';
        }

        $sql = "
            SELECT app.*, 
                   api.invoice_no, api.supplier_invoice_no,
                   s.name as supplier_name
            FROM ap_payments app
            JOIN ap_invoices api ON app.invoice_id = api.id
            LEFT JOIN suppliers s ON api.supplier_id = s.id
            WHERE " . implode(' AND ', $where) . " AND app.amount > 0
            ORDER BY app.created_at DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get pending approvals count
     */
    public function getPendingCount(): int {
        $stmt = $this->db->query("SELECT COUNT(*) FROM ap_payments WHERE status = 'Pending'");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get payment summary by status
     */
    public function getSummaryByStatus(): array {
        $sql = "
            SELECT 
                status,
                COUNT(*) as count,
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_amount
            FROM ap_payments
            GROUP BY status
        ";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Get upcoming payments due
     */
    public function getUpcomingPayments(int $days = 7): array {
        $sql = "
            SELECT api.*, s.name as supplier_name,
                   DATEDIFF(api.due_date, CURDATE()) as days_until_due
            FROM ap_invoices api
            JOIN suppliers s ON api.supplier_id = s.id
            WHERE api.status IN ('Approved', 'Partial')
              AND api.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY api.due_date ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }
}
