<?php
/**
 * RBAC (Role-Based Access Control) Class
 * ERP v2 - Phase 1
 * 
 * Implements permission checking following the matrix from agents.md.
 * Supports status-based permissions and admin custom overrides.
 */

class RBAC {
    private PDO $db;
    private ?int $userId;
    private array $userRoles = [];
    private array $permissionCache = [];
    
    public function __construct(?int $userId = null) {
        $this->db = getDB();
        $this->userId = $userId ?? ($_SESSION['user_id'] ?? null);
        
        if ($this->userId) {
            $this->loadUserRoles();
        }
    }
    
    /**
     * Load user's roles from database
     */
    private function loadUserRoles(): void {
        $stmt = $this->db->prepare("
            SELECT r.id, r.code, r.name
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = :user_id
        ");
        $stmt->execute(['user_id' => $this->userId]);
        $this->userRoles = $stmt->fetchAll();
    }
    
    /**
     * Get user's role codes
     */
    public function getUserRoleCodes(): array {
        return array_column($this->userRoles, 'code');
    }
    
    /**
     * Check if user has a specific role
     */
    public function hasRole(string $roleCode): bool {
        return in_array($roleCode, $this->getUserRoleCodes());
    }
    
    /**
     * Check if user has any of the given roles
     */
    public function hasAnyRole(array $roleCodes): bool {
        return !empty(array_intersect($roleCodes, $this->getUserRoleCodes()));
    }
    
    /**
     * Check if user can perform action on entity (with optional status)
     * 
     * @param string $action Action to check (view/create/edit/approve/void/etc)
     * @param string $entityType Entity type (JOB/PO/PR/etc)
     * @param string|null $entityStatus Current status of the entity (for status-based checks)
     * @return bool
     */
    public function can(string $action, string $entityType, ?string $entityStatus = null): bool {
        // Admin always has access
        if ($this->hasRole(ROLE_ADMIN)) {
            return true;
        }
        
        // Manager has broad access (but still check specific restrictions)
        $isManager = $this->hasRole(ROLE_MANAGER);
        
        // Build cache key
        $cacheKey = "{$action}:{$entityType}:" . ($entityStatus ?? 'ALL');
        
        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }
        
        // Check custom permissions first (override role permissions)
        $custom = $this->checkCustomPermission($action, $entityType, $entityStatus);
        if ($custom !== null) {
            $this->permissionCache[$cacheKey] = $custom;
            return $custom;
        }
        
        // Check role-based permissions
        $granted = $this->checkRolePermission($action, $entityType, $entityStatus);
        
        $this->permissionCache[$cacheKey] = $granted;
        return $granted;
    }
    
    /**
     * Check custom permission for specific user
     */
    private function checkCustomPermission(string $action, string $entityType, ?string $entityStatus): ?bool {
        $stmt = $this->db->prepare("
            SELECT cp.is_granted
            FROM custom_permissions cp
            JOIN permissions p ON cp.permission_id = p.id
            WHERE cp.user_id = :user_id
            AND p.entity_type = :entity_type
            AND p.action = :action
            AND (cp.entity_status IS NULL OR cp.entity_status = :status)
            AND (cp.expires_at IS NULL OR cp.expires_at > NOW())
            ORDER BY cp.entity_status DESC
            LIMIT 1
        ");
        
        $stmt->execute([
            'user_id' => $this->userId,
            'entity_type' => $entityType,
            'action' => $action,
            'status' => $entityStatus
        ]);
        
        $result = $stmt->fetch();
        
        if ($result) {
            return (bool) $result['is_granted'];
        }
        
        return null; // No custom permission found
    }
    
    /**
     * Check permission based on user's roles
     */
    private function checkRolePermission(string $action, string $entityType, ?string $entityStatus): bool {
        $roleIds = array_column($this->userRoles, 'id');
        
        if (empty($roleIds)) {
            return false;
        }
        
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        
        $sql = "
            SELECT rp.is_granted
            FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id IN ($placeholders)
            AND p.entity_type = ?
            AND p.action = ?
            AND (rp.entity_status IS NULL OR rp.entity_status = ?)
            AND rp.is_granted = 1
            ORDER BY rp.entity_status DESC
            LIMIT 1
        ";
        
        $params = array_merge($roleIds, [$entityType, $action, $entityStatus]);
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return (bool) $stmt->fetch();
    }
    
    /**
     * Get all permissions for a role
     */
    public function getRolePermissions(int $roleId): array {
        $stmt = $this->db->prepare("
            SELECT p.*, rp.entity_status, rp.is_granted
            FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = :role_id
            ORDER BY p.entity_type, p.action
        ");
        $stmt->execute(['role_id' => $roleId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get all roles
     */
    public function getAllRoles(): array {
        $stmt = $this->db->query("SELECT * FROM roles ORDER BY id");
        return $stmt->fetchAll();
    }
    
    /**
     * Get all permissions
     */
    public function getAllPermissions(): array {
        $stmt = $this->db->query("SELECT * FROM permissions ORDER BY entity_type, action");
        return $stmt->fetchAll();
    }
    
    /**
     * Assign role to user
     */
    public function assignRole(int $userId, int $roleId, int $assignedBy): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_roles (user_id, role_id, assigned_by, assigned_at)
                VALUES (:user_id, :role_id, :assigned_by, NOW())
                ON DUPLICATE KEY UPDATE assigned_by = :assigned_by2, assigned_at = NOW()
            ");
            
            return $stmt->execute([
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_by' => $assignedBy,
                'assigned_by2' => $assignedBy
            ]);
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Remove role from user
     */
    public function removeRole(int $userId, int $roleId): bool {
        $stmt = $this->db->prepare("
            DELETE FROM user_roles WHERE user_id = :user_id AND role_id = :role_id
        ");
        return $stmt->execute(['user_id' => $userId, 'role_id' => $roleId]);
    }
    
    /**
     * Assign custom permission to user
     */
    public function assignCustomPermission(
        int $userId, 
        int $permissionId, 
        bool $isGranted,
        int $assignedBy,
        ?string $entityStatus = null,
        ?string $reason = null,
        ?string $expiresAt = null
    ): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO custom_permissions 
                (user_id, permission_id, entity_status, is_granted, reason, assigned_by, expires_at, created_at)
                VALUES 
                (:user_id, :perm_id, :status, :granted, :reason, :assigned_by, :expires, NOW())
                ON DUPLICATE KEY UPDATE 
                is_granted = :granted2, reason = :reason2, assigned_by = :assigned_by2, expires_at = :expires2
            ");
            
            return $stmt->execute([
                'user_id' => $userId,
                'perm_id' => $permissionId,
                'status' => $entityStatus,
                'granted' => $isGranted ? 1 : 0,
                'reason' => $reason,
                'assigned_by' => $assignedBy,
                'expires' => $expiresAt,
                'granted2' => $isGranted ? 1 : 0,
                'reason2' => $reason,
                'assigned_by2' => $assignedBy,
                'expires2' => $expiresAt
            ]);
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Remove custom permission from user
     */
    public function removeCustomPermission(int $userId, int $permissionId, ?string $entityStatus = null): bool {
        $sql = "DELETE FROM custom_permissions WHERE user_id = :user_id AND permission_id = :perm_id";
        $params = ['user_id' => $userId, 'perm_id' => $permissionId];
        
        if ($entityStatus !== null) {
            $sql .= " AND entity_status = :status";
            $params['status'] = $entityStatus;
        }
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Get user's custom permissions
     */
    public function getUserCustomPermissions(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT cp.*, p.code as permission_code, p.name as permission_name, 
                   p.entity_type, p.action, u.username as assigned_by_name
            FROM custom_permissions cp
            JOIN permissions p ON cp.permission_id = p.id
            JOIN users u ON cp.assigned_by = u.id
            WHERE cp.user_id = :user_id
            ORDER BY p.entity_type, p.action
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Require permission - throws exception if not granted
     */
    public function require(string $action, string $entityType, ?string $entityStatus = null): void {
        if (!$this->can($action, $entityType, $entityStatus)) {
            throw new Exception("Permission denied: {$action} on {$entityType}");
        }
    }
    
    /**
     * Check and redirect if permission denied
     */
    public function requireOrRedirect(string $action, string $entityType, ?string $entityStatus = null): void {
        if (!$this->can($action, $entityType, $entityStatus)) {
            setFlash('error', 'คุณไม่มีสิทธิ์ดำเนินการนี้');
            redirect('/4erpv2/index.php');
            exit;
        }
    }
}
