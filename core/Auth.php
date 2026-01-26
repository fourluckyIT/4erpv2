<?php
/**
 * Authentication Class
 * ERP v2 - Phase 1
 * 
 * Handles login, logout, password hashing and session management.
 * All actions are audit logged per agents.md requirements.
 */

class Auth {
    private PDO $db;
    private Session $session;
    private AuditLog $audit;
    
    public function __construct() {
        $this->db = getDB();
        $this->session = new Session();
        $this->audit = new AuditLog();
    }
    
    /**
     * Attempt to login with username and password
     */
    public function login(string $username, string $password): array {
        // Find user by username or email
        $stmt = $this->db->prepare("
            SELECT u.*, GROUP_CONCAT(r.code) as role_codes
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            WHERE (u.username = :username OR u.email = :email)
            AND u.is_active = 1
            GROUP BY u.id
        ");
        $stmt->execute(['username' => $username, 'email' => $username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['success' => false, 'error' => 'ไม่พบผู้ใช้งาน'];
        }
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'รหัสผ่านไม่ถูกต้อง'];
        }
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        $sessionId = session_id();
        
        // Set session data
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['roles'] = $user['role_codes'] ? explode(',', $user['role_codes']) : [];
        $_SESSION['logged_in'] = true;
        $_SESSION['request_id'] = generateUUID();
        
        // Create session record
        $this->session->create($user['id'], $sessionId);
        
        // Update last login
        $this->updateLastLogin($user['id']);
        
        // Audit log
        $this->audit->log(
            AUDIT_ACTION_LOGIN,
            'USER',
            $user['id'],
            null,
            ['username' => $user['username'], 'ip' => getClientIP()],
            null
        );
        
        return ['success' => true, 'user' => $user];
    }
    
    /**
     * Logout current user
     */
    public function logout(): void {
        $userId = $_SESSION['user_id'] ?? null;
        $sessionId = session_id();
        
        if ($userId) {
            // Audit log before clearing session
            $this->audit->log(
                AUDIT_ACTION_LOGOUT,
                'USER',
                $userId,
                null,
                ['ip' => getClientIP()],
                null
            );
            
            // Destroy session record
            $this->session->destroy($sessionId);
        }
        
        // Clear session data
        $_SESSION = [];
        
        // Destroy session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        
        // Destroy session
        session_destroy();
    }
    
    /**
     * Check if user is authenticated
     */
    public function isAuthenticated(): bool {
        return !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);
    }
    
    /**
     * Get current user data
     */
    public function getCurrentUser(): ?array {
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        $stmt = $this->db->prepare("
            SELECT u.*, GROUP_CONCAT(r.code) as role_codes
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            WHERE u.id = :id
            GROUP BY u.id
        ");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get current user ID
     */
    public function getCurrentUserId(): ?int {
        return $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Get current user's roles
     */
    public function getCurrentRoles(): array {
        return $_SESSION['roles'] ?? [];
    }
    
    /**
     * Check if current user has specific role
     */
    public function hasRole(string $roleCode): bool {
        return in_array($roleCode, $this->getCurrentRoles());
    }
    
    /**
     * Check if current user is admin
     */
    public function isAdmin(): bool {
        return $this->hasRole(ROLE_ADMIN);
    }
    
    /**
     * Hash a password
     */
    public function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }
    
    /**
     * Verify password against hash
     */
    public function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }
    
    /**
     * Update user's last login timestamp
     */
    private function updateLastLogin(int $userId): void {
        $stmt = $this->db->prepare("
            UPDATE users SET last_login_at = NOW() WHERE id = :id
        ");
        $stmt->execute(['id' => $userId]);
    }
    
    /**
     * Require authentication - redirect if not logged in
     */
    public function requireAuth(): void {
        if (!$this->isAuthenticated()) {
            setFlash('error', 'กรุณาเข้าสู่ระบบก่อน');
            redirect(BASE_URL . '/modules/auth/login.php');
        }
    }
    
    /**
     * Require specific role(s)
     */
    public function requireRole(array $roles): void {
        $this->requireAuth();
        
        $userRoles = $this->getCurrentRoles();
        $hasRole = !empty(array_intersect($roles, $userRoles));
        
        if (!$hasRole) {
            setFlash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
            redirect(BASE_URL . '/index.php');
        }
    }
}
