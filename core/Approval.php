<?php
/**
 * Approval Model Class
 * 4ERP - M8: Approval System
 * 
 * Handles:
 * - Purchase threshold approvals
 * - Stock adjustment approvals
 * - Compliance/shortage override approvals
 * - Timesheet exception approvals
 * - Append-only approval logs
 * 
 * Following agents.md §3
 */

class Approval {
    private PDO $db;
    private AuditLog $audit;
    
    const TYPE_PURCHASE_THRESHOLD = 'purchase_threshold';
    const TYPE_STOCK_ADJUST = 'stock_adjust';
    const TYPE_COMPLIANCE_OVERRIDE = 'compliance_override';
    const TYPE_SHORTAGE_OVERRIDE = 'shortage_override';
    const TYPE_TIMESHEET_EXCEPTION = 'timesheet_exception';
    const TYPE_JOB_EXTENSION = 'job_extension';
    const TYPE_JOB_VOID = 'job_void';
    const TYPE_OTHER = 'other';
    
    const STATUS_PENDING = 'Pending';
    const STATUS_APPROVED = 'Approved';
    const STATUS_REJECTED = 'Rejected';
    const STATUS_CANCELLED = 'Cancelled';
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
    }
    
    /**
     * Create an approval request
     */
    public function createRequest(
        string $requestType,
        string $entityType,
        int $entityId,
        string $title,
        string $description,
        ?int $jobId = null,
        ?float $amount = null,
        ?string $impactSummary = null
    ): array {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO approval_requests (
                    request_type, entity_type, entity_id, job_id,
                    title, description, amount, impact_summary,
                    status, requested_by
                ) VALUES (
                    :request_type, :entity_type, :entity_id, :job_id,
                    :title, :description, :amount, :impact_summary,
                    'Pending', :user_id
                )
            ");
            
            $stmt->execute([
                'request_type' => $requestType,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'job_id' => $jobId,
                'title' => $title,
                'description' => $description,
                'amount' => $amount,
                'impact_summary' => $impactSummary,
                'user_id' => $_SESSION['user_id']
            ]);
            
            $requestId = (int) $this->db->lastInsertId();
            
            // Log to approval_logs (append-only)
            $this->logAction($requestId, $requestType, $entityType, $entityId, $jobId, 
                'request', $title, $description, $amount, $impactSummary);
            
            // Create notifications for approvers
            $this->notifyApprovers($requestId, $requestType, $title);
            
            $this->audit->log(
                'approval_request',
                $entityType,
                $entityId,
                null,
                ['request_id' => $requestId, 'type' => $requestType, 'title' => $title]
            );
            
            return ['success' => true, 'id' => $requestId];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Approve a request
     */
    public function approve(int $requestId, ?string $notes = null): array {
        try {
            $request = $this->getById($requestId);
            if (!$request) {
                return ['success' => false, 'error' => 'Approval request not found'];
            }
            if ($request['status'] !== self::STATUS_PENDING) {
                return ['success' => false, 'error' => 'Request ไม่อยู่ในสถานะ Pending'];
            }
            
            // Check approver has permission
            if (!$this->canApprove($request)) {
                return ['success' => false, 'error' => 'คุณไม่มีสิทธิ์อนุมัติ Request นี้'];
            }
            
            $stmt = $this->db->prepare("
                UPDATE approval_requests 
                SET status = 'Approved', 
                    approved_by = ?, 
                    approved_at = NOW(),
                    approval_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $notes, $requestId]);
            
            // Log to approval_logs
            $this->logAction($requestId, $request['request_type'], $request['entity_type'], 
                $request['entity_id'], $request['job_id'], 'approve', 
                $request['title'], $notes, $request['amount']);
            
            // Notify requester
            $this->notifyRequester($request, 'approved', $notes);
            
            $this->audit->log(
                'approval_approve',
                $request['entity_type'],
                $request['entity_id'],
                ['status' => self::STATUS_PENDING],
                ['status' => self::STATUS_APPROVED, 'notes' => $notes]
            );
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Reject a request
     */
    public function reject(int $requestId, string $reason): array {
        try {
            $request = $this->getById($requestId);
            if (!$request) {
                return ['success' => false, 'error' => 'Approval request not found'];
            }
            if ($request['status'] !== self::STATUS_PENDING) {
                return ['success' => false, 'error' => 'Request ไม่อยู่ในสถานะ Pending'];
            }
            
            if (!$this->canApprove($request)) {
                return ['success' => false, 'error' => 'คุณไม่มีสิทธิ์ปฏิเสธ Request นี้'];
            }
            
            $stmt = $this->db->prepare("
                UPDATE approval_requests 
                SET status = 'Rejected', 
                    rejected_by = ?, 
                    rejected_at = NOW(),
                    rejection_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $reason, $requestId]);
            
            // Log to approval_logs
            $this->logAction($requestId, $request['request_type'], $request['entity_type'], 
                $request['entity_id'], $request['job_id'], 'reject', 
                $request['title'], $reason, $request['amount']);
            
            // Notify requester
            $this->notifyRequester($request, 'rejected', $reason);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Cancel a pending request (by requester)
     */
    public function cancel(int $requestId, string $reason): array {
        try {
            $request = $this->getById($requestId);
            if (!$request) {
                return ['success' => false, 'error' => 'Approval request not found'];
            }
            if ($request['status'] !== self::STATUS_PENDING) {
                return ['success' => false, 'error' => 'Request ไม่อยู่ในสถานะ Pending'];
            }
            if ($request['requested_by'] != $_SESSION['user_id']) {
                return ['success' => false, 'error' => 'เฉพาะผู้ขอเท่านั้นที่สามารถยกเลิกได้'];
            }
            
            $stmt = $this->db->prepare("
                UPDATE approval_requests SET status = 'Cancelled' WHERE id = ?
            ");
            $stmt->execute([$requestId]);
            
            $this->logAction($requestId, $request['request_type'], $request['entity_type'], 
                $request['entity_id'], $request['job_id'], 'cancel', 
                $request['title'], $reason, $request['amount']);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Check if current user can approve this request type
     */
    private function canApprove(array $request): bool {
        $auth = new Auth();
        
        // Admin and Manager can approve all
        if ($auth->isAdmin() || $auth->hasRole('MGR')) {
            return true;
        }
        
        // Type-specific approvers
        switch ($request['request_type']) {
            case self::TYPE_PURCHASE_THRESHOLD:
                return $auth->hasRole('PUR') || $auth->hasRole('MGR');
            case self::TYPE_STOCK_ADJUST:
                return $auth->hasRole('WH') || $auth->hasRole('MGR');
            case self::TYPE_TIMESHEET_EXCEPTION:
                return $auth->hasRole('HR') || $auth->hasRole('MGR');
            default:
                return false;
        }
    }
    
    /**
     * Log action to append-only approval_logs
     */
    private function logAction(
        int $requestId,
        string $requestType,
        string $entityType,
        int $entityId,
        ?int $jobId,
        string $action,
        string $title,
        ?string $notes = null,
        ?float $amount = null,
        ?string $impactSummary = null
    ): void {
        $auth = new Auth();
        $roles = $auth->getCurrentRoles();
        $roleCode = !empty($roles) ? $roles[0]['code'] : null;
        
        $stmt = $this->db->prepare("
            INSERT INTO approval_logs (
                approval_request_id, request_type, entity_type, entity_id, job_id,
                action, actor_id, actor_role, title, description, amount, impact_summary, notes
            ) VALUES (
                :request_id, :request_type, :entity_type, :entity_id, :job_id,
                :action, :actor_id, :actor_role, :title, :description, :amount, :impact_summary, :notes
            )
        ");
        $stmt->execute([
            'request_id' => $requestId,
            'request_type' => $requestType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'job_id' => $jobId,
            'action' => $action,
            'actor_id' => $_SESSION['user_id'],
            'actor_role' => $roleCode,
            'title' => $title,
            'description' => $notes,
            'amount' => $amount,
            'impact_summary' => $impactSummary,
            'notes' => $notes
        ]);
    }
    
    /**
     * Notify users who can approve this request type
     */
    private function notifyApprovers(int $requestId, string $requestType, string $title): void {
        $notification = new Notification();
        
        // Get users who can approve based on request type
        $approverRoles = match($requestType) {
            self::TYPE_PURCHASE_THRESHOLD => ['ADM', 'MGR', 'PUR'],
            self::TYPE_STOCK_ADJUST => ['ADM', 'MGR', 'WH'],
            self::TYPE_TIMESHEET_EXCEPTION => ['ADM', 'MGR', 'HR'],
            default => ['ADM', 'MGR']
        };
        
        $placeholders = str_repeat('?,', count($approverRoles) - 1) . '?';
        $stmt = $this->db->prepare("
            SELECT DISTINCT u.id 
            FROM users u
            JOIN user_roles ur ON u.id = ur.user_id
            JOIN roles r ON ur.role_id = r.id
            WHERE r.code IN ($placeholders) AND u.is_active = 1
        ");
        $stmt->execute($approverRoles);
        $approvers = $stmt->fetchAll();
        
        foreach ($approvers as $approver) {
            $notification->create(
                $approver['id'],
                'approval_request',
                'มีคำขออนุมัติใหม่',
                $title,
                '/4erpv2/modules/admin/approvals.php?id=' . $requestId,
                'APPROVAL',
                $requestId,
                'high'
            );
        }
    }
    
    /**
     * Notify the requester about approval result
     */
    private function notifyRequester(array $request, string $result, ?string $notes): void {
        $notification = new Notification();
        
        $title = $result === 'approved' ? 'คำขอได้รับอนุมัติแล้ว' : 'คำขอถูกปฏิเสธ';
        $message = $request['title'];
        if ($notes) {
            $message .= "\n" . ($result === 'approved' ? 'หมายเหตุ: ' : 'เหตุผล: ') . $notes;
        }
        
        $notification->create(
            $request['requested_by'],
            'approval_result',
            $title,
            $message,
            '/4erpv2/modules/admin/approvals.php?id=' . $request['id'],
            'APPROVAL',
            $request['id'],
            $result === 'approved' ? 'normal' : 'high'
        );
    }
    
    // ==================== GETTERS ====================
    
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT ar.*, 
                   u1.full_name as requested_by_name,
                   u2.full_name as approved_by_name,
                   u3.full_name as rejected_by_name
            FROM approval_requests ar
            LEFT JOIN users u1 ON ar.requested_by = u1.id
            LEFT JOIN users u2 ON ar.approved_by = u2.id
            LEFT JOIN users u3 ON ar.rejected_by = u3.id
            WHERE ar.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public function getPending(array $filters = []): array {
        $where = ['ar.status = "Pending"'];
        $params = [];
        
        if (!empty($filters['request_type'])) {
            $where[] = 'ar.request_type = :request_type';
            $params['request_type'] = $filters['request_type'];
        }
        
        $sql = "
            SELECT ar.*, u.full_name as requested_by_name
            FROM approval_requests ar
            JOIN users u ON ar.requested_by = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY ar.requested_at DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function getByEntity(string $entityType, int $entityId): array {
        $stmt = $this->db->prepare("
            SELECT ar.*, u.full_name as requested_by_name
            FROM approval_requests ar
            JOIN users u ON ar.requested_by = u.id
            WHERE ar.entity_type = ? AND ar.entity_id = ?
            ORDER BY ar.requested_at DESC
        ");
        $stmt->execute([$entityType, $entityId]);
        return $stmt->fetchAll();
    }
    
    public function getLogs(array $filters = [], int $limit = 50): array {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['entity_type'])) {
            $where[] = 'al.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['entity_id'])) {
            $where[] = 'al.entity_id = :entity_id';
            $params['entity_id'] = $filters['entity_id'];
        }
        
        $sql = "
            SELECT al.*, u.full_name as actor_name
            FROM approval_logs al
            JOIN users u ON al.actor_id = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY al.performed_at DESC
            LIMIT $limit
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function getPendingCount(): int {
        $stmt = $this->db->query("SELECT COUNT(*) FROM approval_requests WHERE status = 'Pending'");
        return (int) $stmt->fetchColumn();
    }
}
