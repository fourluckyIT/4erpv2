<?php
/**
 * Document Number Generator
 * 4ERP - Phase 1
 * 
 * Requirements per agents.md:
 * 1. No duplicates (UNIQUE constraint + atomic increment)
 * 2. No rollback (numbers only go forward)
 * 3. Concurrent protection (DB transaction + FOR UPDATE lock)
 * 4. Reserved at Submitted/Approved stage (logged in doc_number_log)
 */

class DocumentNumber {
    private PDO $db;
    private AuditLog $audit;
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
    }
    
    /**
     * Generate next document number atomically
     * Uses SELECT FOR UPDATE to prevent concurrent collisions
     * 
     * @param string $docType Document type (JOB, PO, PR, etc.)
     * @param int|null $entityId Optional entity ID to link (for logging)
     * @return string Generated document number (e.g., "JOB-2026-00001")
     */
    public function generate(string $docType, ?int $entityId = null): string {
        $currentYear = (int) date('Y');
        $startedTransaction = false;
        
        try {
            // Start transaction if not already in one
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }
            
            // Lock the row for update (prevents concurrent access)
            $stmt = $this->db->prepare("
                SELECT id, prefix, current_year, next_number, padding, reset_yearly
                FROM doc_number_settings
                WHERE doc_type = :doc_type
                FOR UPDATE
            ");
            $stmt->execute(['doc_type' => $docType]);
            $setting = $stmt->fetch();
            
            if (!$setting) {
                // Only rollback if we started it
                if ($startedTransaction) $this->db->rollBack();
                throw new Exception("Document type '$docType' not configured");
            }
            
            // Check if year changed and reset is enabled
            $nextNumber = (int) $setting['next_number'];
            if ($setting['reset_yearly'] && $setting['current_year'] != $currentYear) {
                $nextNumber = 1;
                // Update year
                $updateYear = $this->db->prepare("
                    UPDATE doc_number_settings 
                    SET current_year = :year, next_number = 1
                    WHERE doc_type = :doc_type
                ");
                $updateYear->execute(['year' => $currentYear, 'doc_type' => $docType]);
            }
            
            // Format document number
            $docNumber = sprintf(
                "%s%d-%s",
                $setting['prefix'],
                $currentYear,
                str_pad($nextNumber, $setting['padding'], '0', STR_PAD_LEFT)
            );
            
            // Increment the counter
            $updateStmt = $this->db->prepare("
                UPDATE doc_number_settings 
                SET next_number = next_number + 1, updated_at = NOW()
                WHERE doc_type = :doc_type
            ");
            $updateStmt->execute(['doc_type' => $docType]);
            
            // Log the generated number (immutable record)
            $logStmt = $this->db->prepare("
                INSERT INTO doc_number_log (doc_type, doc_number, entity_id, generated_by, generated_at)
                VALUES (:doc_type, :doc_number, :entity_id, :user_id, NOW())
            ");
            $logStmt->execute([
                'doc_type' => $docType,
                'doc_number' => $docNumber,
                'entity_id' => $entityId,
                'user_id' => $_SESSION['user_id'] ?? 0
            ]);
            
            // Commit transaction if we started it
            if ($startedTransaction) {
                $this->db->commit();
            }
            
            return $docNumber;
            
        } catch (Exception $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Reserve a document number without linking to entity yet
     * For cases where number is needed before record is created
     */
    public function reserve(string $docType): string {
        return $this->generate($docType, null);
    }
    
    /**
     * Link a reserved number to an entity
     */
    public function linkToEntity(string $docNumber, int $entityId): bool {
        $stmt = $this->db->prepare("
            UPDATE doc_number_log 
            SET entity_id = :entity_id 
            WHERE doc_number = :doc_number AND entity_id IS NULL
        ");
        return $stmt->execute([
            'entity_id' => $entityId,
            'doc_number' => $docNumber
        ]);
    }
    
    /**
     * Check if a document number exists
     */
    public function exists(string $docNumber): bool {
        $stmt = $this->db->prepare("
            SELECT 1 FROM doc_number_log WHERE doc_number = :doc_number
        ");
        $stmt->execute(['doc_number' => $docNumber]);
        return (bool) $stmt->fetch();
    }
    
    /**
     * Get current setting for a document type
     */
    public function getSetting(string $docType): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM doc_number_settings WHERE doc_type = :doc_type
        ");
        $stmt->execute(['doc_type' => $docType]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get last generated number for a document type
     */
    public function getLastGenerated(string $docType): ?string {
        $stmt = $this->db->prepare("
            SELECT doc_number FROM doc_number_log 
            WHERE doc_type = :doc_type 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['doc_type' => $docType]);
        $result = $stmt->fetch();
        return $result ? $result['doc_number'] : null;
    }
    
    /**
     * Get all generated numbers for a document type
     */
    public function getHistory(string $docType, int $limit = 20): array {
        $stmt = $this->db->prepare("
            SELECT dnl.*, u.username
            FROM doc_number_log dnl
            LEFT JOIN users u ON dnl.generated_by = u.id
            WHERE dnl.doc_type = :doc_type 
            ORDER BY dnl.id DESC 
            LIMIT :limit
        ");
        $stmt->bindValue('doc_type', $docType);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
