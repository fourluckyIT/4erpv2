<?php
/**
 * Role-Based Access Control (RBAC) Class
 * 4ERP - Phase 1
 * 
 * Implements permission checking based on:
 * - User roles (from user_roles table)
 * - Role permissions (from role_permissions table)
 * - Custom user permissions (from custom_permissions table)
 * - Status-based restrictions
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
            SELECT r.code, r.name
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ?
        ");
        $stmt->execute([$this->userId]);
        $this->userRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
     * Check if user has any of the specified roles
     */
    public function hasAnyRole(array $roleCodes): bool {
        return !empty(array_intersect($roleCodes, $this->getUserRoleCodes()));
    }
    
    /**
     * Check if user can perform action on entity
     * 
     * @param string $action Action name (view, create, edit, approve, void, etc.)
     * @param string $entityType Entity type (JOB, PO, PR, etc.)
     * @param string|null $entityStatus Optional entity status for status-based checks
     * @return bool
     */
    public function can(string $action, string $entityType, ?string $entityStatus = null): bool {
        if (!$this->userId) {
            return false;
        }
        
        // Cache key
        $cacheKey = "{$action}:{$entityType}:{$entityStatus}";
        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }
        
        // Admin always has access
        if ($this->hasRole(ROLE_ADMIN)) {
            $this->permissionCache[$cacheKey] = true;
            return true;
        }
        
        // Check custom permissions first (user-specific overrides)
        $customPerm = $this->checkCustomPermission($action, $entityType, $entityStatus);
        if ($customPerm !== null) {
            $this->permissionCache[$cacheKey] = $customPerm;
            return $customPerm;
        }
        
        // Check role permissions
        $rolePerm = $this->checkRolePermission($action, $entityType, $entityStatus);
        $this->permissionCache[$cacheKey] = $rolePerm;
        
        return $rolePerm;
    }
    
    /**
     * Check custom user permission
     * @return bool|null null if no custom permission found
     */
    private function checkCustomPermission(string $action, string $entityType, ?string $entityStatus): ?bool {
        $stmt = $this->db->prepare("
            SELECT cp.is_granted
            FROM custom_permissions cp
            JOIN permissions p ON cp.permission_id = p.id
            WHERE cp.user_id = ?
            AND p.action = ?
            AND p.entity_type = ?
            AND (cp.entity_status IS NULL OR cp.entity_status = ?)
            AND (cp.expires_at IS NULL OR cp.expires_at > NOW())
            ORDER BY cp.entity_status DESC
            LIMIT 1
        ");
        $stmt->execute([$this->userId, $action, $entityType, $entityStatus]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return (bool) $result['is_granted'];
        }
        
        return null;
    }
    
    /**
     * Check role-based permission
     */
    private function checkRolePermission(string $action, string $entityType, ?string $entityStatus): bool {
        $roleCodes = $this->getUserRoleCodes();
        if (empty($roleCodes)) {
            return false;
        }
        
        $placeholders = implode(',', array_fill(0, count($roleCodes), '?'));
        
        $sql = "
            SELECT rp.is_granted
            FROM role_permissions rp
            JOIN roles r ON rp.role_id = r.id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE r.code IN ($placeholders)
            AND p.action = ?
            AND p.entity_type = ?
            AND (rp.entity_status IS NULL OR rp.entity_status = ?)
            AND rp.is_granted = 1
            ORDER BY rp.entity_status DESC
            LIMIT 1
        ";
        
        $params = array_merge($roleCodes, [$action, $entityType, $entityStatus]);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return (bool) $stmt->fetchColumn();
    }
    
    /**
     * Get all permissions for current user
     */
    public function getAllPermissions(): array {
        if (!$this->userId) {
            return [];
        }
        
        $roleCodes = $this->getUserRoleCodes();
        if (empty($roleCodes)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($roleCodes), '?'));
        
        $sql = "
            SELECT DISTINCT p.code, p.name, p.entity_type, p.action
            FROM role_permissions rp
            JOIN roles r ON rp.role_id = r.id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE r.code IN ($placeholders)
            AND rp.is_granted = 1
            ORDER BY p.entity_type, p.action
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($roleCodes);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Require permission or redirect
     */
    public function requireOrRedirect(string $action, string $entityType, ?string $entityStatus = null, string $redirectUrl = null): void {
        if (!$this->can($action, $entityType, $entityStatus)) {
            $url = $redirectUrl ?? BASE_URL . '/';
            setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
            redirect($url);
        }
    }
    
    /**
     * Assign role to user
     */
    public function assignRole(int $userId, string $roleCode, int $assignedBy): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_roles (user_id, role_id, assigned_by)
                SELECT ?, r.id, ?
                FROM roles r
                WHERE r.code = ?
                ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by)
            ");
            $stmt->execute([$userId, $assignedBy, $roleCode]);
            
            $audit = new AuditLog();
            $audit->log('assign_role', 'USER', $userId, null, ['role' => $roleCode]);
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Remove role from user
     */
    public function removeRole(int $userId, string $roleCode): bool {
        try {
            $stmt = $this->db->prepare("
                DELETE ur FROM user_roles ur
                JOIN roles r ON ur.role_id = r.id
                WHERE ur.user_id = ? AND r.code = ?
            ");
            $stmt->execute([$userId, $roleCode]);
            
            $audit = new AuditLog();
            $audit->log('remove_role', 'USER', $userId, ['role' => $roleCode], null);
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Grant custom permission to user
     */
    public function grantPermission(int $userId, string $permissionCode, int $assignedBy, ?string $reason = null, ?string $expiresAt = null): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO custom_permissions (user_id, permission_id, is_granted, reason, assigned_by, expires_at)
                SELECT ?, p.id, 1, ?, ?, ?
                FROM permissions p
                WHERE p.code = ?
                ON DUPLICATE KEY UPDATE is_granted = 1, reason = VALUES(reason), assigned_by = VALUES(assigned_by), expires_at = VALUES(expires_at)
            ");
            $stmt->execute([$userId, $reason, $assignedBy, $expiresAt, $permissionCode]);
            
            $audit = new AuditLog();
            $audit->log('grant_permission', 'USER', $userId, null, ['permission' => $permissionCode], $reason);
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Revoke custom permission from user
     */
    public function revokePermission(int $userId, string $permissionCode, int $assignedBy, ?string $reason = null): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO custom_permissions (user_id, permission_id, is_granted, reason, assigned_by)
                SELECT ?, p.id, 0, ?, ?
                FROM permissions p
                WHERE p.code = ?
                ON DUPLICATE KEY UPDATE is_granted = 0, reason = VALUES(reason), assigned_by = VALUES(assigned_by)
            ");
            $stmt->execute([$userId, $reason, $assignedBy, $permissionCode]);
            
            $audit = new AuditLog();
            $audit->log('revoke_permission', 'USER', $userId, ['permission' => $permissionCode], null, $reason);
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get all roles from database
     */
    public function getAllRoles(): array {
        $stmt = $this->db->query("SELECT * FROM roles ORDER BY code");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all users with their roles
     */
    public function getAllUsers(): array {
        $stmt = $this->db->query("
            SELECT u.*, GROUP_CONCAT(r.code) as role_codes
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            GROUP BY u.id
            ORDER BY u.username
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get user roles by user ID
     */
    public function getUserRolesById(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT r.*
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all permissions for a role
     */
    public function getRolePermissions(int $roleId): array {
        $stmt = $this->db->prepare("
            SELECT p.*, rp.is_granted, rp.entity_status
            FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ?
            ORDER BY p.entity_type, p.action
        ");
        $stmt->execute([$roleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all permissions from database
     */
    public function getAllPermissionsFromDB(): array {
        $stmt = $this->db->query("SELECT * FROM permissions ORDER BY entity_type, action");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Assign custom permission to user
     */
    public function assignCustomPermission(int $userId, int $permissionId, int $assignedBy, ?string $reason = null, ?string $entityStatus = null): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO custom_permissions (user_id, permission_id, is_granted, reason, assigned_by, entity_status)
                VALUES (?, ?, 1, ?, ?, ?)
                ON DUPLICATE KEY UPDATE is_granted = 1, reason = VALUES(reason), assigned_by = VALUES(assigned_by)
            ");
            $stmt->execute([$userId, $permissionId, $reason, $assignedBy, $entityStatus]);
            
            $this->audit->log('assign_custom_permission', 'USER', $userId, null, ['permission_id' => $permissionId], $reason);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Remove custom permission from user
     */
    public function removeCustomPermission(int $userId, int $permissionId): bool {
        try {
            $stmt = $this->db->prepare("DELETE FROM custom_permissions WHERE user_id = ? AND permission_id = ?");
            $stmt->execute([$userId, $permissionId]);
            
            $this->audit->log('remove_custom_permission', 'USER', $userId, ['permission_id' => $permissionId], null);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get user's custom permissions
     */
    public function getUserCustomPermissions(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT cp.*, p.code, p.name, p.entity_type, p.action
            FROM custom_permissions cp
            JOIN permissions p ON cp.permission_id = p.id
            WHERE cp.user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Assign permission to role
     */
    public function assignRolePermission(int $roleId, int $permissionId, ?string $entityStatus = null): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO role_permissions (role_id, permission_id, is_granted, entity_status)
                VALUES (?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE is_granted = 1
            ");
            $stmt->execute([$roleId, $permissionId, $entityStatus]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Remove permission from role
     */
    public function removeRolePermission(int $roleId, int $permissionId): bool {
        try {
            $stmt = $this->db->prepare("DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?");
            $stmt->execute([$roleId, $permissionId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
