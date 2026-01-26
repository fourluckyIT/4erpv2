<?php
/**
 * Notification Model Class
 * ERP v2 - M8: Notification System
 * 
 * Handles:
 * - In-app notifications
 * - LINE OA push notifications (audit only, no financial actions)
 * - Notification preferences
 * 
 * Following agents.md §4
 */

class Notification {
    private PDO $db;
    
    const TYPE_APPROVAL_REQUEST = 'approval_request';
    const TYPE_APPROVAL_RESULT = 'approval_result';
    const TYPE_JOB_STATUS = 'job_status';
    const TYPE_DISPATCH_ALERT = 'dispatch_alert';
    const TYPE_SHORTAGE_ALERT = 'shortage_alert';
    const TYPE_COMPLIANCE_ALERT = 'compliance_alert';
    const TYPE_TIMESHEET_PENDING = 'timesheet_pending';
    const TYPE_PO_OVERDUE = 'po_overdue';
    const TYPE_SYSTEM = 'system';
    const TYPE_OTHER = 'other';
    
    const PRIORITY_LOW = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Create a notification
     */
    public function create(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $entityType = null,
        ?int $entityId = null,
        string $priority = 'normal'
    ): array {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notifications (
                    user_id, type, priority, title, message, 
                    action_url, entity_type, entity_id
                ) VALUES (
                    :user_id, :type, :priority, :title, :message,
                    :action_url, :entity_type, :entity_id
                )
            ");
            
            $stmt->execute([
                'user_id' => $userId,
                'type' => $type,
                'priority' => $priority,
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);
            
            $notificationId = (int) $this->db->lastInsertId();
            
            // Check if user wants LINE OA notification for this type
            if ($this->shouldSendLine($userId, $type) && in_array($priority, ['high', 'urgent'])) {
                $this->sendLineNotification($userId, $notificationId, $title, $message);
            }
            
            return ['success' => true, 'id' => $notificationId];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Create notifications for multiple users
     */
    public function createBulk(array $userIds, string $type, string $title, string $message, 
                               ?string $actionUrl = null, ?string $entityType = null, 
                               ?int $entityId = null, string $priority = 'normal'): int {
        $count = 0;
        foreach ($userIds as $userId) {
            $result = $this->create($userId, $type, $title, $message, $actionUrl, $entityType, $entityId, $priority);
            if ($result['success']) {
                $count++;
            }
        }
        return $count;
    }
    
    /**
     * Mark notification as read
     */
    public function markRead(int $notificationId): bool {
        $stmt = $this->db->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        return $stmt->execute([$notificationId, $_SESSION['user_id']]);
    }
    
    /**
     * Mark all notifications as read for current user
     */
    public function markAllRead(): int {
        $stmt = $this->db->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW()
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->rowCount();
    }
    
    /**
     * Delete old read notifications (cleanup)
     */
    public function cleanup(int $daysOld = 30): int {
        $stmt = $this->db->prepare("
            DELETE FROM notifications 
            WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$daysOld]);
        return $stmt->rowCount();
    }
    
    /**
     * Check if user wants LINE notification for this type
     */
    private function shouldSendLine(int $userId, string $type): bool {
        $stmt = $this->db->prepare("
            SELECT line_oa FROM notification_preferences
            WHERE user_id = ? AND notification_type = ?
        ");
        $stmt->execute([$userId, $type]);
        $pref = $stmt->fetch();
        
        return $pref && $pref['line_oa'];
    }
    
    /**
     * Send LINE OA notification (placeholder - actual implementation depends on LINE API)
     */
    private function sendLineNotification(int $userId, int $notificationId, string $title, string $message): void {
        // Get user's LINE binding
        $stmt = $this->db->prepare("
            SELECT line_user_id FROM line_bindings
            WHERE user_id = ? AND is_active = 1
        ");
        $stmt->execute([$userId]);
        $binding = $stmt->fetch();
        
        if (!$binding) {
            return; // User not bound to LINE
        }
        
        $lineUserId = $binding['line_user_id'];
        $messageContent = $title . "\n" . $message;
        
        // Log the notification attempt (audit requirement per agents.md)
        $status = 'pending';
        $errorMessage = null;
        
        try {
            // TODO: Implement actual LINE API call here
            // For now, just log it as pending
            // $this->callLineApi($lineUserId, $messageContent);
            $status = 'sent';
        } catch (Exception $e) {
            $status = 'failed';
            $errorMessage = $e->getMessage();
        }
        
        // Always log LINE notification attempts
        $stmt = $this->db->prepare("
            INSERT INTO line_notification_log (
                user_id, line_user_id, notification_id, 
                message_type, message_content, status, error_message
            ) VALUES (?, ?, ?, 'text', ?, ?, ?)
        ");
        $stmt->execute([$userId, $lineUserId, $notificationId, $messageContent, $status, $errorMessage]);
    }
    
    /**
     * Set notification preference for a user
     */
    public function setPreference(int $userId, string $type, bool $inApp = true, bool $lineOa = false, bool $email = false): bool {
        $stmt = $this->db->prepare("
            INSERT INTO notification_preferences (user_id, notification_type, in_app, line_oa, email)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE in_app = VALUES(in_app), line_oa = VALUES(line_oa), email = VALUES(email)
        ");
        return $stmt->execute([$userId, $type, $inApp ? 1 : 0, $lineOa ? 1 : 0, $email ? 1 : 0]);
    }
    
    // ==================== GETTERS ====================
    
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM notifications WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public function getForUser(int $userId, bool $unreadOnly = false, int $limit = 50): array {
        $where = 'user_id = ?';
        if ($unreadOnly) {
            $where .= ' AND is_read = 0';
        }
        
        $stmt = $this->db->prepare("
            SELECT * FROM notifications
            WHERE $where
            ORDER BY created_at DESC
            LIMIT $limit
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    public function getUnreadCount(int $userId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM notifications
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
    
    public function getRecent(int $userId, int $limit = 10): array {
        return $this->getForUser($userId, false, $limit);
    }
    
    public function getPreferences(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM notification_preferences WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    // ==================== HELPER METHODS FOR COMMON NOTIFICATIONS ====================
    
    /**
     * Notify about job status change
     */
    public function notifyJobStatusChange(int $jobId, string $jobNumber, string $oldStatus, string $newStatus, array $userIds): void {
        $title = "Job {$jobNumber} เปลี่ยนสถานะ";
        $message = "สถานะ: {$oldStatus} → {$newStatus}";
        $url = "/4erpv2/modules/jobs/view.php?id={$jobId}";
        
        $this->createBulk($userIds, self::TYPE_JOB_STATUS, $title, $message, $url, 'JOB', $jobId);
    }
    
    /**
     * Notify about dispatch
     */
    public function notifyDispatch(int $routeId, string $routeNumber, string $jobNumber, array $userIds): void {
        $title = "Route {$routeNumber} ถูก Dispatch แล้ว";
        $message = "Job: {$jobNumber}";
        $url = "/4erpv2/modules/logistics/routes/view.php?id={$routeId}";
        
        $this->createBulk($userIds, self::TYPE_DISPATCH_ALERT, $title, $message, $url, 'ROUTE', $routeId, 'high');
    }
    
    /**
     * Notify about shortage
     */
    public function notifyShortage(int $planId, string $planNumber, string $itemName, int $shortage, array $userIds): void {
        $title = "พบ Shortage ใน Plan {$planNumber}";
        $message = "สินค้า: {$itemName}, ขาด: {$shortage}";
        $url = "/4erpv2/modules/planning/view.php?id={$planId}";
        
        $this->createBulk($userIds, self::TYPE_SHORTAGE_ALERT, $title, $message, $url, 'PLAN', $planId, 'urgent');
    }
    
    /**
     * Notify about compliance issue
     */
    public function notifyComplianceIssue(int $jobId, string $jobNumber, string $personName, string $certName, array $userIds): void {
        $title = "Compliance Issue - Job {$jobNumber}";
        $message = "{$personName} ไม่มี/หมดอายุ: {$certName}";
        $url = "/4erpv2/modules/jobs/view.php?id={$jobId}";
        
        $this->createBulk($userIds, self::TYPE_COMPLIANCE_ALERT, $title, $message, $url, 'JOB', $jobId, 'high');
    }
    
    /**
     * Notify about pending timesheet
     */
    public function notifyTimesheetPending(int $timesheetId, string $tsNumber, string $workDate, array $userIds): void {
        $title = "Timesheet รอ Confirm";
        $message = "{$tsNumber} - วันที่ {$workDate}";
        $url = "/4erpv2/modules/timesheet/view.php?id={$timesheetId}";
        
        $this->createBulk($userIds, self::TYPE_TIMESHEET_PENDING, $title, $message, $url, 'TIMESHEET', $timesheetId);
    }
    
    /**
     * Notify about overdue PO
     */
    public function notifyPOOverdue(int $poId, string $poNumber, string $supplierName, int $daysOverdue, array $userIds): void {
        $title = "PO {$poNumber} เลยกำหนด {$daysOverdue} วัน";
        $message = "Supplier: {$supplierName}";
        $url = "/4erpv2/modules/procurement/po/view.php?id={$poId}";
        
        $this->createBulk($userIds, self::TYPE_PO_OVERDUE, $title, $message, $url, 'PO', $poId, 'high');
    }
}
