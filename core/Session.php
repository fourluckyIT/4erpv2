<?php
/**
 * Session Management Class
 * 4ERP - Phase 1
 */

class Session {
    private PDO $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Create new session record
     */
    public function create(int $userId, string $sessionId): void {
        $stmt = $this->db->prepare("
            INSERT INTO sessions (id, user_id, ip_address, user_agent, created_at, last_activity)
            VALUES (:id, :user_id, :ip, :ua, NOW(), NOW())
            ON DUPLICATE KEY UPDATE last_activity = NOW()
        ");
        
        $stmt->execute([
            'id' => $sessionId,
            'user_id' => $userId,
            'ip' => getClientIP(),
            'ua' => substr(getUserAgent(), 0, 255)
        ]);
    }
    
    /**
     * Update session last activity
     */
    public function touch(string $sessionId): void {
        $stmt = $this->db->prepare("
            UPDATE sessions SET last_activity = NOW() 
            WHERE id = :id
        ");
        $stmt->execute(['id' => $sessionId]);
    }
    
    /**
     * Destroy session record
     */
    public function destroy(string $sessionId): void {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = :id");
        $stmt->execute(['id' => $sessionId]);
    }
    
    /**
     * Destroy all sessions for user (logout everywhere)
     */
    public function destroyAll(int $userId): void {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
    }
    
    /**
     * Get active sessions for user
     */
    public function getUserSessions(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM sessions 
            WHERE user_id = :user_id 
            ORDER BY last_activity DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Clean expired sessions
     */
    public function cleanup(): int {
        $lifetime = SESSION_LIFETIME ?? 28800; // default 8 hours
        $stmt = $this->db->prepare("
            DELETE FROM sessions 
            WHERE last_activity < DATE_SUB(NOW(), INTERVAL :lifetime SECOND)
        ");
        $stmt->execute(['lifetime' => $lifetime]);
        return $stmt->rowCount();
    }
}
