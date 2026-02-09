<?php
/**
 * Job Model Class
 * 4ERP - Phase 2
 * 
 * Handles Job CRUD with:
 * - Status machine integration
 * - Lockpoint enforcement
 * - Audit logging
 * - Document number generation
 */

require_once __DIR__ . '/StatusMachine.php';
require_once __DIR__ . '/RouteReminder.php';
require_once __DIR__ . '/DocumentNumber.php';
require_once __DIR__ . '/Notification.php';
require_once __DIR__ . '/RBAC.php';

class Job {
    private PDO $db;
    private AuditLog $audit;
    private DocumentNumber $docNum;
    private Notification $notification;
    private RBAC $rbac;
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
        $this->docNum = new DocumentNumber();
        $this->notification = new Notification();
        $this->rbac = new RBAC();
    }
    
    /**
     * Create a new job (Draft status)
     */
    public function create(array $data): array {
        try {
            // Validate required fields first
            $required = ['customer_id', 'job_type', 'scope_short', 'plan_start_date', 'plan_end_date', 'owner_sale_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Field '$field' is required");
                }
            }
            
            // Data integrity: site must belong to customer
            if (!empty($data['site_id'])) {
                $stmt = $this->db->prepare("SELECT customer_id FROM sites WHERE id = ?");
                $stmt->execute([$data['site_id']]);
                $site = $stmt->fetch();
                if (!$site || $site['customer_id'] != $data['customer_id']) {
                    throw new Exception("Site ไม่ได้เป็นของ Customer ที่เลือก");
                }
            }
            
            // Generate job number BEFORE transaction (has its own transaction)
            $jobNumber = $this->docNum->generate('JOB');
            
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                INSERT INTO jobs (
                    job_number, customer_id, site_id, job_type, 
                    scope_short, scope_detail, quotation_no,
                    plan_start_date, plan_end_date, 
                    owner_sale_id, owner_planner_id,
                    contract_value, budget, status, created_by
                ) VALUES (
                    :job_number, :customer_id, :site_id, :job_type,
                    :scope_short, :scope_detail, :quotation_no,
                    :plan_start_date, :plan_end_date,
                    :owner_sale_id, :owner_planner_id,
                    :contract_value, :budget, 'Draft', :created_by
                )
            ");
            
            $stmt->execute([
                'job_number' => $jobNumber,
                'customer_id' => $data['customer_id'],
                'site_id' => $data['site_id'] ?? null,
                'job_type' => $data['job_type'],
                'scope_short' => $data['scope_short'],
                'scope_detail' => $data['scope_detail'] ?? null,
                'quotation_no' => $data['quotation_no'] ?? null,
                'plan_start_date' => $data['plan_start_date'],
                'plan_end_date' => $data['plan_end_date'],
                'owner_sale_id' => $data['owner_sale_id'],
                'owner_planner_id' => $data['owner_planner_id'] ?? null,
                'contract_value' => $data['contract_value'] ?? 0,
                'budget' => $data['budget'] ?? 0,
                'created_by' => $_SESSION['user_id']
            ]);
            
            $jobId = (int) $this->db->lastInsertId();
            
            // Log initial status
            $this->logStatusChange($jobId, null, 'Draft');
            
            // Audit log
            $this->audit->log(
                AUDIT_ACTION_CREATE,
                'JOB',
                $jobId,
                null,
                ['job_number' => $jobNumber, 'customer_id' => $data['customer_id']]
            );
            
            $this->db->commit();
            
            return ['success' => true, 'id' => $jobId, 'job_number' => $jobNumber];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Update job (respects lockpoints)
     */
    public function update(int $id, array $data): array {
        try {
            $job = $this->getById($id);
            if (!$job) {
                return ['success' => false, 'error' => 'Job not found'];
            }
            
            // Check what fields can be edited at current status
            $allowedFields = [];
            $fieldsToUpdate = [];
            
            foreach ($data as $field => $value) {
                if (StatusMachine::isFieldEditable($job['status'], $field)) {
                    $allowedFields[] = $field;
                    $fieldsToUpdate[$field] = $value;
                }
            }
            
            if (empty($fieldsToUpdate)) {
                return ['success' => false, 'error' => 'ไม่มีฟิลด์ที่สามารถแก้ไขได้ที่สถานะ ' . $job['status']];
            }
            
            // Build update query
            $setParts = [];
            $params = ['id' => $id];
            foreach ($fieldsToUpdate as $field => $value) {
                $setParts[] = "$field = :$field";
                $params[$field] = $value;
            }
            
            $sql = "UPDATE jobs SET " . implode(', ', $setParts) . ", updated_at = NOW() WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            // Audit log
            $this->audit->log(
                AUDIT_ACTION_UPDATE,
                'JOB',
                $id,
                $job,
                $fieldsToUpdate
            );
            
            return ['success' => true, 'updated_fields' => array_keys($fieldsToUpdate)];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Change status with validation
     */
    public function changeStatus(int $id, string $action, ?string $reason = null): array {
        try {
            $job = $this->getById($id);
            if (!$job) {
                return ['success' => false, 'error' => 'Job not found'];
            }
            
            // Get action config
            if (!isset(StatusMachine::ACTIONS[$action])) {
                return ['success' => false, 'error' => "Invalid action: $action"];
            }
            
            $actionConfig = StatusMachine::ACTIONS[$action];
            $fromStatuses = is_array($actionConfig['from']) ? $actionConfig['from'] : [$actionConfig['from']];
            $toStatus = $actionConfig['to'];
            
            // Validate current status
            if (!in_array($job['status'], $fromStatuses)) {
                return ['success' => false, 'error' => "ไม่สามารถทำ $action จากสถานะ {$job['status']}"];
            }
            
            // Check user role
            $userRoles = $_SESSION['roles'] ?? [];
            $allowedRoles = StatusMachine::ACTION_ROLES[$action] ?? [];
            if (empty(array_intersect($userRoles, $allowedRoles))) {
                return ['success' => false, 'error' => 'คุณไม่มีสิทธิ์ทำ action นี้'];
            }

            // Permission-based check (if permission defined)
            $rbac = new RBAC();
            $permAction = StatusMachine::getPermissionAction($action);
            if ($permAction && $rbac->permissionExists($permAction, 'JOB')) {
                if (!$rbac->can($permAction, 'JOB', $job['status'])) {
                    return ['success' => false, 'error' => 'คุณไม่มีสิทธิ์ทำ action นี้'];
                }
            }
            
            // Validate transition
            $validation = StatusMachine::validateTransition($job['status'], $toStatus, $reason);
            if (!$validation['valid']) {
                return ['success' => false, 'error' => $validation['error']];
            }

            // Route-driven statuses should not be changed directly on Job
            $routeDrivenActions = ['dispatch', 'start', 'return', 'wh_receive'];
            if (in_array($action, $routeDrivenActions)) {
                return ['success' => false, 'error' => 'สถานะนี้อัปเดตผ่าน Route เท่านั้น'];
            }
            
            $this->db->beginTransaction();
            
            // Update status
            $updateFields = ['status' => $toStatus];
            
            // Set additional fields based on action
            switch ($action) {
                case 'submit':
                    $updateFields['submitted_at'] = date('Y-m-d H:i:s');
                    $updateFields['submitted_by'] = $_SESSION['user_id'];
                    break;
                case 'approve':
                    $updateFields['approved_at'] = date('Y-m-d H:i:s');
                    $updateFields['approved_by'] = $_SESSION['user_id'];
                    break;
                case 'close':
                    $updateFields['closed_at'] = date('Y-m-d H:i:s');
                    $updateFields['closed_by'] = $_SESSION['user_id'];
                    break;
                case 'cancel':
                case 'reject':
                    $updateFields['voided_at'] = date('Y-m-d H:i:s'); // Use same field
                    $updateFields['voided_by'] = $_SESSION['user_id'];
                    $updateFields['void_reason'] = $reason;
                    break;
                case 'void':
                    $updateFields['voided_at'] = date('Y-m-d H:i:s');
                    $updateFields['voided_by'] = $_SESSION['user_id'];
                    $updateFields['void_reason'] = $reason;
                    break;
            }
            
            $setParts = [];
            $params = ['id' => $id];
            foreach ($updateFields as $field => $value) {
                $setParts[] = "$field = :$field";
                $params[$field] = $value;
            }
            
            $sql = "UPDATE jobs SET " . implode(', ', $setParts) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            // Log status change
            $this->logStatusChange($id, $job['status'], $toStatus, $reason);
            
            // Audit log
            $this->audit->log(
                $action,
                'JOB',
                $id,
                ['status' => $job['status']],
                ['status' => $toStatus],
                $reason
            );

            // Stop route dispatch reminders if job is cancelled/voided
            if (in_array($action, ['cancel', 'reject', 'void'])) {
                $reminder = new RouteReminder();
                $reminder->stopByJobId($id, 'Job cancelled/voided', $_SESSION['user_id']);
            }
            
            $this->db->commit();

            // Notifications (after commit)
            if ($action === 'submit') {
                $approverIds = $this->rbac->getUserIdsWithPermission('approve', 'JOB', 'Submitted');
                if (!empty($approverIds)) {
                    $title = "Job {$job['job_number']} รออนุมัติ";
                    $message = "ลูกค้า: {$job['customer_name']}";
                    $url = "/4erpv2/modules/jobs/view.php?id={$id}";
                    $this->notification->createBulk(
                        $approverIds,
                        Notification::TYPE_APPROVAL_REQUEST,
                        $title,
                        $message,
                        $url,
                        'JOB',
                        $id,
                        Notification::PRIORITY_HIGH
                    );
                }
            }

            if (in_array($action, ['approve', 'reject', 'cancel', 'void'], true)) {
                $this->notification->markReadByEntity('JOB', $id, Notification::TYPE_APPROVAL_REQUEST);
                $requesterId = $job['submitted_by'] ?? $job['created_by'];
                if (!empty($requesterId)) {
                    $statusText = match ($action) {
                        'approve' => 'อนุมัติแล้ว',
                        'reject' => 'ถูกปฏิเสธ',
                        'cancel' => 'ถูกยกเลิก',
                        'void' => 'ถูก Void',
                        default => 'อัปเดตแล้ว'
                    };
                    $title = "Job {$job['job_number']} {$statusText}";
                    $message = "ลูกค้า: {$job['customer_name']}";
                    if (!empty($reason)) {
                        $message .= "\nเหตุผล: {$reason}";
                    }
                    $url = "/4erpv2/modules/jobs/view.php?id={$id}";
                    $this->notification->create(
                        (int)$requesterId,
                        Notification::TYPE_APPROVAL_RESULT,
                        $title,
                        $message,
                        $url,
                        'JOB',
                        $id,
                        Notification::PRIORITY_NORMAL
                    );
                }
            }
            
            return ['success' => true, 'new_status' => $toStatus];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Log status change to history table
     */
    private function logStatusChange(int $jobId, ?string $oldStatus, string $newStatus, ?string $reason = null): void {
        $stmt = $this->db->prepare("
            INSERT INTO job_status_history (job_id, old_status, new_status, changed_by, reason)
            VALUES (:job_id, :old_status, :new_status, :changed_by, :reason)
        ");
        $stmt->execute([
            'job_id' => $jobId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_by' => $_SESSION['user_id'],
            'reason' => $reason
        ]);
    }
    
    /**
     * Get job by ID with related data
     */
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT j.*, 
                   c.name as customer_name, c.code as customer_code,
                   s.name as site_name,
                   sale.full_name as owner_sale_name,
                   plan.full_name as owner_planner_name,
                   cr.full_name as created_by_name
            FROM jobs j
            LEFT JOIN customers c ON j.customer_id = c.id
            LEFT JOIN sites s ON j.site_id = s.id
            LEFT JOIN users sale ON j.owner_sale_id = sale.id
            LEFT JOIN users plan ON j.owner_planner_id = plan.id
            LEFT JOIN users cr ON j.created_by = cr.id
            WHERE j.id = :id
        ");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get job list with filters
     */
    public function getList(array $filters = [], int $limit = 50, int $offset = 0): array {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'j.status = :status';
            $params['status'] = $filters['status'];
        }
        
        if (!empty($filters['customer_id'])) {
            $where[] = 'j.customer_id = :customer_id';
            $params['customer_id'] = $filters['customer_id'];
        }
        
        if (!empty($filters['owner_sale_id'])) {
            $where[] = 'j.owner_sale_id = :owner_sale_id';
            $params['owner_sale_id'] = $filters['owner_sale_id'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = '(j.job_number LIKE :search OR j.scope_short LIKE :search OR c.name LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        
        $sql = "
            SELECT j.*, 
                   c.name as customer_name,
                   sale.full_name as owner_sale_name
            FROM jobs j
            LEFT JOIN customers c ON j.customer_id = c.id
            LEFT JOIN users sale ON j.owner_sale_id = sale.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY j.id DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Get status history for a job
     */
    public function getStatusHistory(int $jobId): array {
        $stmt = $this->db->prepare("
            SELECT jsh.*, u.full_name as changed_by_name, u.username
            FROM job_status_history jsh
            JOIN users u ON jsh.changed_by = u.id
            WHERE jsh.job_id = :job_id
            ORDER BY jsh.created_at DESC
        ");
        $stmt->execute(['job_id' => $jobId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get dashboard stats
     */
    public function getStats(): array {
        $stats = [];
        
        // Count by status
        $stmt = $this->db->query("
            SELECT status, COUNT(*) as count 
            FROM jobs 
            GROUP BY status
        ");
        $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Active jobs (not Closed/Voided)
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM jobs 
            WHERE status NOT IN ('Closed', 'Voided')
        ");
        $stats['active'] = (int) $stmt->fetchColumn();
        
        // Pending approval
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM jobs WHERE status = 'Submitted'
        ");
        $stats['pending_approval'] = (int) $stmt->fetchColumn();
        
        // This month
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM jobs 
            WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())
        ");
        $stats['this_month'] = (int) $stmt->fetchColumn();
        
        return $stats;
    }
}
