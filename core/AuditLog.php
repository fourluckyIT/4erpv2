<?php
/**
 * Audit Log Class
 * 4ERP - Phase 1
 * 
 * APPEND-ONLY audit trail per agents.md requirements.
 * Every significant action is logged with full context.
 */

class AuditLog {
    private PDO $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Log an action to the audit trail
     * 
     * @param string $action Action name (create/update/approve/void/etc)
     * @param string $entityType Entity type (JOB/PO/USER/etc)
     * @param int|null $entityId Entity ID
     * @param array|null $oldValue Previous values (for updates)
     * @param array|null $newValue New values
     * @param string|null $reason Required for cancel/void/override
     */
    public function log(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $reason = null
    ): int {
        // Validate: reason required for sensitive actions
        $requiresReason = [
            AUDIT_ACTION_VOID, 
            AUDIT_ACTION_CANCEL, 
            AUDIT_ACTION_OVERRIDE,
            AUDIT_ACTION_DELETE
        ];
        
        if (in_array($action, $requiresReason) && empty($reason)) {
            // Log but flag missing reason
            $reason = '[REASON NOT PROVIDED - VIOLATION]';
        }
        
        $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
        [$userId, $fallbackNote] = $this->resolveAuditUserId($sessionUserId);
        $userRole = $this->getCurrentUserRole();
        if ($fallbackNote !== null) {
            $reason = trim(($reason ? $reason . ' ' : '') . $fallbackNote);
            $userRole = $this->getUserPrimaryRoleById($userId) ?? $userRole;
        }
        $requestId = getRequestId();
        
        $stmt = $this->db->prepare("
            INSERT INTO audit_logs (
                user_id, user_role, request_id, action_name, 
                entity_type, entity_id, old_value, new_value, 
                reason, ip_address, user_agent, created_at
            ) VALUES (
                :user_id, :user_role, :request_id, :action,
                :entity_type, :entity_id, :old_value, :new_value,
                :reason, :ip, :ua, NOW()
            )
        ");
        
        $stmt->execute([
            'user_id' => $userId,
            'user_role' => $userRole,
            'request_id' => $requestId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_value' => $oldValue ? json_encode($oldValue) : null,
            'new_value' => $newValue ? json_encode($newValue) : null,
            'reason' => $reason,
            'ip' => getClientIP(),
            'ua' => substr(getUserAgent(), 0, 255)
        ]);
        
        return (int) $this->db->lastInsertId();
    }
    
    /**
     * Get current user's primary role for logging
     */
    private function getCurrentUserRole(): string {
        $roles = $_SESSION['roles'] ?? [];
        // Priority: ADM > MGR > others
        if (in_array(ROLE_ADMIN, $roles)) return ROLE_ADMIN;
        if (in_array(ROLE_MANAGER, $roles)) return ROLE_MANAGER;
        return $roles[0] ?? 'GUEST';
    }

    /**
     * Resolve a safe audit user id that satisfies FK constraints.
     * Falls back to username/admin/any user if session user_id is stale.
     *
     * @return array{0:int,1:?string} [resolvedUserId, fallbackNote]
     */
    private function resolveAuditUserId(int $sessionUserId): array {
        if ($sessionUserId > 0 && $this->userIdExists($sessionUserId)) {
            return [$sessionUserId, null];
        }

        $resolvedId = null;
        $sessionUsername = $_SESSION['username'] ?? null;
        if (!empty($sessionUsername)) {
            $resolvedId = $this->getUserIdByUsername($sessionUsername);
        }
        if (!$resolvedId) {
            $resolvedId = $this->getAdminUserId();
        }
        if (!$resolvedId) {
            $resolvedId = $this->getAnyUserId();
        }
        if (!$resolvedId) {
            throw new RuntimeException('Audit log requires at least one user record.');
        }

        $note = sprintf(
            '[AUDIT_USER_FALLBACK: session_user_id=%s resolved_user_id=%d]',
            $sessionUserId > 0 ? (string) $sessionUserId : 'NULL',
            $resolvedId
        );

        return [$resolvedId, $note];
    }

    private function userIdExists(int $userId): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    private function getUserIdByUsername(string $username): ?int {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
        $stmt->execute(['username' => $username]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    private function getAdminUserId(): ?int {
        $stmt = $this->db->prepare("
            SELECT u.id
            FROM users u
            INNER JOIN user_roles ur ON ur.user_id = u.id
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE r.code = :code
            ORDER BY u.id ASC
            LIMIT 1
        ");
        $stmt->execute(['code' => ROLE_ADMIN]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    private function getAnyUserId(): ?int {
        $stmt = $this->db->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    private function getUserPrimaryRoleById(int $userId): ?string {
        $stmt = $this->db->prepare("
            SELECT r.code
            FROM roles r
            INNER JOIN user_roles ur ON ur.role_id = r.id
            WHERE ur.user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($roles)) {
            return null;
        }
        if (in_array(ROLE_ADMIN, $roles, true)) return ROLE_ADMIN;
        if (in_array(ROLE_MANAGER, $roles, true)) return ROLE_MANAGER;
        return $roles[0];
    }
    
    /**
     * Get audit logs for an entity
     */
    public function getEntityLogs(string $entityType, int $entityId, int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT al.*, u.username, u.full_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.entity_type = :entity_type 
            AND al.entity_id = :entity_id
            ORDER BY al.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue('entity_type', $entityType);
        $stmt->bindValue('entity_id', $entityId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get audit logs for a user
     */
    public function getUserLogs(int $userId, int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT * FROM audit_logs 
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue('user_id', $userId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get recent logs (for admin dashboard)
     */
    public function getRecentLogs(int $limit = 100): array {
        $stmt = $this->db->prepare("
            SELECT al.*, u.username, u.full_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Search logs
     */
    public function search(array $filters, int $limit = 100): array {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $where[] = 'al.user_id = :user_id';
            $params['user_id'] = $filters['user_id'];
        }
        
        if (!empty($filters['entity_type'])) {
            $where[] = 'al.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        
        if (!empty($filters['action_name'])) {
            $where[] = 'al.action_name = :action_name';
            $params['action_name'] = $filters['action_name'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = 'al.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = 'al.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        
        $whereClause = implode(' AND ', $where);
        
        $stmt = $this->db->prepare("
            SELECT al.*, u.username, u.full_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE $whereClause
            ORDER BY al.created_at DESC
            LIMIT :limit
        ");
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Calculate diff between old and new values
     */
    public static function diff(array $old, array $new): array {
        $changes = [];
        
        foreach ($new as $key => $value) {
            if (!isset($old[$key]) || $old[$key] !== $value) {
                $changes[$key] = [
                    'old' => $old[$key] ?? null,
                    'new' => $value
                ];
            }
        }
        
        return $changes;
    }
}
