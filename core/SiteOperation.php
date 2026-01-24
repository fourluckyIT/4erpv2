<?php
/**
 * Site Operation Model Class
 * ERP v2 - M9: Site Operations
 * 
 * Handles:
 * - Site receiving confirmation
 * - Return notes
 * - Damage/Loss reports
 * - Partial delivery/return
 * 
 * Following blueprint.md §11
 */

require_once __DIR__ . '/DocumentNumber.php';

class SiteOperation {
    private PDO $db;
    private AuditLog $audit;
    private DocumentNumber $docNum;
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
        $this->docNum = new DocumentNumber();
    }
    
    // ==================== SITE RECEIPT ====================
    
    /**
     * Create site receipt from route
     */
    public function createSiteReceipt(int $routeId, string $receivedByName, ?string $receivedByPhone = null, ?string $notes = null): array {
        try {
            // Get route details
            $stmt = $this->db->prepare("
                SELECT r.*, p.job_id, j.site_id
                FROM routes r
                JOIN plans p ON r.plan_id = p.id
                JOIN jobs j ON p.job_id = j.id
                WHERE r.id = ?
            ");
            $stmt->execute([$routeId]);
            $route = $stmt->fetch();
            
            if (!$route) {
                return ['success' => false, 'error' => 'Route not found'];
            }
            
            $receiptNumber = $this->docNum->generate('SR');
            
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                INSERT INTO site_receipts (
                    receipt_number, route_id, job_id, site_id,
                    received_date, received_by_name, received_by_phone,
                    status, notes, created_by
                ) VALUES (
                    :receipt_number, :route_id, :job_id, :site_id,
                    CURDATE(), :received_by_name, :received_by_phone,
                    'Draft', :notes, :user_id
                )
            ");
            $stmt->execute([
                'receipt_number' => $receiptNumber,
                'route_id' => $routeId,
                'job_id' => $route['job_id'],
                'site_id' => $route['site_id'],
                'received_by_name' => $receivedByName,
                'received_by_phone' => $receivedByPhone,
                'notes' => $notes,
                'user_id' => $_SESSION['user_id']
            ]);
            
            $receiptId = (int) $this->db->lastInsertId();
            
            // Auto-populate items from route_items
            $stmt = $this->db->prepare("
                SELECT ri.*, i.name as item_name
                FROM route_items ri
                JOIN items i ON COALESCE(
                    (SELECT item_id FROM serials WHERE id = ri.serial_id), 
                    ri.serial_id
                ) = i.id OR i.id = (SELECT item_id FROM serials WHERE id = ri.serial_id)
                WHERE ri.route_id = ?
            ");
            $stmt->execute([$routeId]);
            $routeItems = $stmt->fetchAll();
            
            foreach ($routeItems as $ri) {
                // Get item_id from serial if available
                $itemId = null;
                if ($ri['serial_id']) {
                    $stmt2 = $this->db->prepare("SELECT item_id FROM serials WHERE id = ?");
                    $stmt2->execute([$ri['serial_id']]);
                    $itemId = $stmt2->fetchColumn();
                }
                
                if ($itemId) {
                    $stmt2 = $this->db->prepare("
                        INSERT INTO site_receipt_items (
                            receipt_id, route_item_id, item_id, serial_id,
                            qty_expected, qty_received, condition_received
                        ) VALUES (?, ?, ?, ?, ?, ?, 'Good')
                    ");
                    $stmt2->execute([
                        $receiptId, $ri['id'], $itemId, $ri['serial_id'],
                        $ri['qty_out'] ?? 1, $ri['qty_out'] ?? 1
                    ]);
                }
            }
            
            $this->audit->log(AUDIT_ACTION_CREATE, 'SITE_RECEIPT', $receiptId, null, [
                'receipt_number' => $receiptNumber, 'route_id' => $routeId
            ]);
            
            $this->db->commit();
            
            return ['success' => true, 'id' => $receiptId, 'receipt_number' => $receiptNumber];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Confirm site receipt
     */
    public function confirmSiteReceipt(int $receiptId, ?string $signaturePath = null): array {
        try {
            $receipt = $this->getSiteReceiptById($receiptId);
            if (!$receipt) {
                return ['success' => false, 'error' => 'Site receipt not found'];
            }
            if ($receipt['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'Receipt ไม่อยู่ในสถานะ Draft'];
            }
            
            $stmt = $this->db->prepare("
                UPDATE site_receipts 
                SET status = 'Confirmed', 
                    confirmed_at = NOW(), 
                    confirmed_by = ?,
                    signature_path = COALESCE(?, signature_path)
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $signaturePath, $receiptId]);
            
            $this->audit->log('confirm', 'SITE_RECEIPT', $receiptId, 
                ['status' => 'Draft'], ['status' => 'Confirmed']);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // ==================== RETURN NOTES ====================
    
    /**
     * Create return note
     */
    public function createReturnNote(int $jobId, string $returnType = 'Full', ?int $routeId = null, ?string $notes = null): array {
        try {
            $stmt = $this->db->prepare("SELECT id, site_id FROM jobs WHERE id = ?");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();
            
            if (!$job) {
                return ['success' => false, 'error' => 'Job not found'];
            }
            
            $returnNumber = $this->docNum->generate('RTN');
            
            $stmt = $this->db->prepare("
                INSERT INTO return_notes (
                    return_number, job_id, route_id, site_id,
                    return_date, return_type, status, notes, created_by
                ) VALUES (
                    :return_number, :job_id, :route_id, :site_id,
                    CURDATE(), :return_type, 'Draft', :notes, :user_id
                )
            ");
            $stmt->execute([
                'return_number' => $returnNumber,
                'job_id' => $jobId,
                'route_id' => $routeId,
                'site_id' => $job['site_id'],
                'return_type' => $returnType,
                'notes' => $notes,
                'user_id' => $_SESSION['user_id']
            ]);
            
            $returnId = (int) $this->db->lastInsertId();
            
            $this->audit->log(AUDIT_ACTION_CREATE, 'RETURN', $returnId, null, [
                'return_number' => $returnNumber, 'job_id' => $jobId
            ]);
            
            return ['success' => true, 'id' => $returnId, 'return_number' => $returnNumber];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Add item to return note
     */
    public function addReturnItem(int $returnNoteId, int $itemId, ?int $serialId, float $qtySent, string $conditionOut = 'Good'): array {
        try {
            $returnNote = $this->getReturnNoteById($returnNoteId);
            if (!$returnNote) {
                return ['success' => false, 'error' => 'Return note not found'];
            }
            if ($returnNote['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'Return note ไม่อยู่ในสถานะ Draft'];
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO return_note_items (
                    return_note_id, item_id, serial_id, qty_sent, condition_out
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$returnNoteId, $itemId, $serialId, $qtySent, $conditionOut]);
            
            return ['success' => true, 'id' => (int) $this->db->lastInsertId()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Mark return note as in transit
     */
    public function markReturnInTransit(int $returnNoteId): array {
        try {
            $returnNote = $this->getReturnNoteById($returnNoteId);
            if (!$returnNote || $returnNote['status'] !== 'Draft') {
                return ['success' => false, 'error' => 'Invalid return note status'];
            }
            
            $stmt = $this->db->prepare("
                UPDATE return_notes 
                SET status = 'InTransit', in_transit_at = NOW(), in_transit_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $returnNoteId]);
            
            // Update serial status to InTransit
            $stmt = $this->db->prepare("
                UPDATE serials s
                JOIN return_note_items rni ON s.id = rni.serial_id
                SET s.status = 'Returned'
                WHERE rni.return_note_id = ?
            ");
            $stmt->execute([$returnNoteId]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Warehouse receives return
     */
    public function receiveReturnAtWH(int $returnNoteId, array $itemsReceived): array {
        try {
            $returnNote = $this->getReturnNoteById($returnNoteId);
            if (!$returnNote || $returnNote['status'] !== 'InTransit') {
                return ['success' => false, 'error' => 'Return note ต้องอยู่ในสถานะ InTransit'];
            }
            
            $this->db->beginTransaction();
            
            foreach ($itemsReceived as $itemId => $data) {
                $stmt = $this->db->prepare("
                    UPDATE return_note_items 
                    SET qty_received = ?, condition_in = ?, inspection_notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['qty_received'] ?? 0,
                    $data['condition_in'] ?? 'Good',
                    $data['inspection_notes'] ?? null,
                    $itemId
                ]);
                
                // If serial, update status
                $stmt = $this->db->prepare("
                    SELECT serial_id, condition_in FROM return_note_items WHERE id = ?
                ");
                $stmt->execute([$itemId]);
                $item = $stmt->fetch();
                
                if ($item && $item['serial_id']) {
                    $newStatus = $item['condition_in'] === 'Damaged' ? 'Damaged' : 'Available';
                    $stmt = $this->db->prepare("
                        UPDATE serials SET status = ?, current_job_id = NULL WHERE id = ?
                    ");
                    $stmt->execute([$newStatus, $item['serial_id']]);
                }
            }
            
            $stmt = $this->db->prepare("
                UPDATE return_notes 
                SET status = 'WHReceived', wh_received_at = NOW(), wh_received_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $returnNoteId]);
            
            $this->audit->log('wh_receive', 'RETURN', $returnNoteId, 
                ['status' => 'InTransit'], ['status' => 'WHReceived']);
            
            $this->db->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // ==================== DAMAGE REPORTS ====================
    
    /**
     * Create damage report
     */
    public function createDamageReport(array $data): array {
        try {
            $reportNumber = $this->docNum->generate('DMG');
            
            $stmt = $this->db->prepare("
                INSERT INTO damage_reports (
                    report_number, job_id, route_id, return_note_id,
                    item_id, serial_id, qty, incident_type, incident_date,
                    incident_location, description, responsible_type,
                    responsible_name, responsible_people_id,
                    estimated_value, is_claimable, status, reported_by
                ) VALUES (
                    :report_number, :job_id, :route_id, :return_note_id,
                    :item_id, :serial_id, :qty, :incident_type, :incident_date,
                    :incident_location, :description, :responsible_type,
                    :responsible_name, :responsible_people_id,
                    :estimated_value, :is_claimable, 'Reported', :user_id
                )
            ");
            
            $stmt->execute([
                'report_number' => $reportNumber,
                'job_id' => $data['job_id'],
                'route_id' => $data['route_id'] ?? null,
                'return_note_id' => $data['return_note_id'] ?? null,
                'item_id' => $data['item_id'],
                'serial_id' => $data['serial_id'] ?? null,
                'qty' => $data['qty'] ?? 1,
                'incident_type' => $data['incident_type'],
                'incident_date' => $data['incident_date'],
                'incident_location' => $data['incident_location'] ?? null,
                'description' => $data['description'],
                'responsible_type' => $data['responsible_type'],
                'responsible_name' => $data['responsible_name'] ?? null,
                'responsible_people_id' => $data['responsible_people_id'] ?? null,
                'estimated_value' => $data['estimated_value'] ?? null,
                'is_claimable' => $data['is_claimable'] ?? 0,
                'user_id' => $_SESSION['user_id']
            ]);
            
            $reportId = (int) $this->db->lastInsertId();
            
            // If serial, update status
            if (!empty($data['serial_id'])) {
                $newStatus = $data['incident_type'] === 'Loss' ? 'Lost' : 'Damaged';
                $stmt = $this->db->prepare("UPDATE serials SET status = ? WHERE id = ?");
                $stmt->execute([$newStatus, $data['serial_id']]);
            }
            
            $this->audit->log(AUDIT_ACTION_CREATE, 'DAMAGE', $reportId, null, [
                'report_number' => $reportNumber, 'incident_type' => $data['incident_type']
            ]);
            
            return ['success' => true, 'id' => $reportId, 'report_number' => $reportNumber];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Add photo to damage report
     */
    public function addDamagePhoto(int $reportId, string $filePath, int $seq, ?string $caption = null): array {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO damage_report_photos (
                    damage_report_id, photo_seq, file_path, caption, uploaded_by
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$reportId, $seq, $filePath, $caption, $_SESSION['user_id']]);
            
            return ['success' => true, 'id' => (int) $this->db->lastInsertId()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Resolve damage report
     */
    public function resolveDamageReport(int $reportId, string $actionTaken, ?string $resolutionNotes = null): array {
        try {
            $report = $this->getDamageReportById($reportId);
            if (!$report) {
                return ['success' => false, 'error' => 'Damage report not found'];
            }
            
            $stmt = $this->db->prepare("
                UPDATE damage_reports 
                SET status = 'Resolved', 
                    action_taken = ?,
                    resolution_notes = ?,
                    resolved_at = NOW(),
                    resolved_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$actionTaken, $resolutionNotes, $_SESSION['user_id'], $reportId]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // ==================== GETTERS ====================
    
    public function getSiteReceiptById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT sr.*, j.job_number, r.route_number
            FROM site_receipts sr
            JOIN jobs j ON sr.job_id = j.id
            LEFT JOIN routes r ON sr.route_id = r.id
            WHERE sr.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public function getReturnNoteById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT rn.*, j.job_number
            FROM return_notes rn
            JOIN jobs j ON rn.job_id = j.id
            WHERE rn.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public function getReturnNoteItems(int $returnNoteId): array {
        $stmt = $this->db->prepare("
            SELECT rni.*, i.name as item_name, i.code as item_code, s.serial_number
            FROM return_note_items rni
            JOIN items i ON rni.item_id = i.id
            LEFT JOIN serials s ON rni.serial_id = s.id
            WHERE rni.return_note_id = ?
        ");
        $stmt->execute([$returnNoteId]);
        return $stmt->fetchAll();
    }
    
    public function getDamageReportById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT dr.*, i.name as item_name, s.serial_number, j.job_number
            FROM damage_reports dr
            JOIN items i ON dr.item_id = i.id
            LEFT JOIN serials s ON dr.serial_id = s.id
            JOIN jobs j ON dr.job_id = j.id
            WHERE dr.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public function getDamageReportPhotos(int $reportId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM damage_report_photos WHERE damage_report_id = ? ORDER BY photo_seq
        ");
        $stmt->execute([$reportId]);
        return $stmt->fetchAll();
    }
    
    public function getReturnNotesByJob(int $jobId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM return_notes WHERE job_id = ? ORDER BY created_at DESC
        ");
        $stmt->execute([$jobId]);
        return $stmt->fetchAll();
    }
    
    public function getDamageReportsByJob(int $jobId): array {
        $stmt = $this->db->prepare("
            SELECT dr.*, i.name as item_name
            FROM damage_reports dr
            JOIN items i ON dr.item_id = i.id
            WHERE dr.job_id = ?
            ORDER BY dr.created_at DESC
        ");
        $stmt->execute([$jobId]);
        return $stmt->fetchAll();
    }
    
    public function getPendingReturns(): array {
        $stmt = $this->db->query("
            SELECT rn.*, j.job_number
            FROM return_notes rn
            JOIN jobs j ON rn.job_id = j.id
            WHERE rn.status IN ('Draft', 'InTransit')
            ORDER BY rn.created_at DESC
        ");
        return $stmt->fetchAll();
    }
    
    public function getPendingDamageReports(): array {
        $stmt = $this->db->query("
            SELECT dr.*, i.name as item_name, j.job_number
            FROM damage_reports dr
            JOIN items i ON dr.item_id = i.id
            JOIN jobs j ON dr.job_id = j.id
            WHERE dr.status IN ('Reported', 'UnderReview')
            ORDER BY dr.created_at DESC
        ");
        return $stmt->fetchAll();
    }
}
