<?php
/**
 * Timesheet Model Class
 * 4ERP - M6: Timesheet Module
 * 
 * Handles Timesheet CRUD with:
 * - Auto-populate from plan_assignments (manpower)
 * - Status workflow: Draft → Confirmed → Submitted → PayrollReady/Returned
 * - Immutability after confirm (corrections only)
 * - Anomaly detection
 * 
 * Following blueprint.md §12 and agents.md immutability rules
 */

require_once __DIR__ . '/DocumentNumber.php';

class Timesheet {
    private PDO $db;
    private AuditLog $audit;
    private DocumentNumber $docNum;
    
    const STATUS_DRAFT = 'Draft';
    const STATUS_CONFIRMED = 'Confirmed';
    const STATUS_SUBMITTED = 'Submitted';
    const STATUS_PAYROLL_READY = 'PayrollReady';
    const STATUS_RETURNED = 'Returned';
    const STATUS_VOIDED = 'Voided';
    
    const OT_THRESHOLD_HOURS = 4; // Anomaly if OT > this
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
        $this->docNum = new DocumentNumber();
    }
    
    /**
     * Create a new timesheet for a job/date
     * Auto-populates entries from plan_assignments if available
     */
    public function create(int $jobId, string $workDate, ?int $siteId = null, ?string $notes = null): array {
        try {
            // Validate job exists
            $job = $this->getJob($jobId);
            if (!$job) {
                return ['success' => false, 'error' => 'Job not found'];
            }
            
            // Check for existing timesheet for this job/date
            $existing = $this->getByJobAndDate($jobId, $workDate);
            if ($existing) {
                return ['success' => false, 'error' => 'Timesheet สำหรับ Job นี้ในวันที่ ' . $workDate . ' มีอยู่แล้ว (TS#' . $existing['ts_number'] . ')'];
            }
            
            // Generate timesheet number (TS-YYYYMMDD-NNN format)
            $tsNumber = 'TS-' . str_replace('-', '', $workDate) . '-' . str_pad($this->getNextDailySequence($workDate), 3, '0', STR_PAD_LEFT);
            
            $this->db->beginTransaction();
            
            // Insert timesheet header
            $stmt = $this->db->prepare("
                INSERT INTO timesheets (
                    ts_number, job_id, site_id, work_date, status, notes, created_by
                ) VALUES (
                    :ts_number, :job_id, :site_id, :work_date, 'Draft', :notes, :created_by
                )
            ");
            
            $stmt->execute([
                'ts_number' => $tsNumber,
                'job_id' => $jobId,
                'site_id' => $siteId ?? $job['site_id'],
                'work_date' => $workDate,
                'notes' => $notes,
                'created_by' => $_SESSION['user_id']
            ]);
            
            $timesheetId = (int) $this->db->lastInsertId();
            
            // Auto-populate entries from plan_assignments (manpower)
            $entriesAdded = $this->autoPopulateFromPlan($timesheetId, $jobId, $workDate);
            
            // Log status history
            $this->logStatusChange($timesheetId, null, self::STATUS_DRAFT);
            
            // Audit log
            $this->audit->log(
                AUDIT_ACTION_CREATE,
                'TIMESHEET',
                $timesheetId,
                null,
                ['ts_number' => $tsNumber, 'job_id' => $jobId, 'work_date' => $workDate, 'entries_auto_added' => $entriesAdded]
            );
            
            $this->db->commit();
            
            return [
                'success' => true, 
                'id' => $timesheetId, 
                'ts_number' => $tsNumber,
                'entries_added' => $entriesAdded
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Auto-populate timesheet entries from plan_assignments for the job
     */
    private function autoPopulateFromPlan(int $timesheetId, int $jobId, string $workDate): int {
        // Get manpower assignments from plans for this job
        // Only get from confirmed plans where the work_date falls within job dates
        $stmt = $this->db->prepare("
            SELECT DISTINCT pa.id as assignment_id, pa.people_id, p.daily_rate
            FROM plan_assignments pa
            JOIN plans pl ON pa.plan_id = pl.id
            JOIN people p ON pa.people_id = p.id
            WHERE pl.job_id = :job_id
              AND pl.status = 'Confirmed'
              AND pa.people_id IS NOT NULL
              AND p.is_active = 1
        ");
        $stmt->execute(['job_id' => $jobId]);
        $assignments = $stmt->fetchAll();
        
        $count = 0;
        foreach ($assignments as $a) {
            $stmt = $this->db->prepare("
                INSERT INTO timesheet_entries (
                    timesheet_id, people_id, is_present, daily_rate, 
                    from_plan_assignment_id, manually_added
                ) VALUES (
                    :timesheet_id, :people_id, 1, :daily_rate,
                    :assignment_id, 0
                )
            ");
            $stmt->execute([
                'timesheet_id' => $timesheetId,
                'people_id' => $a['people_id'],
                'daily_rate' => $a['daily_rate'] ?? 0,
                'assignment_id' => $a['assignment_id']
            ]);
            $count++;
        }
        
        // Update total_workers count
        $stmt = $this->db->prepare("UPDATE timesheets SET total_workers = ? WHERE id = ?");
        $stmt->execute([$count, $timesheetId]);
        
        return $count;
    }
    
    /**
     * Add a person manually to timesheet (requires reason)
     */
    public function addEntry(int $timesheetId, int $peopleId, string $reason): array {
        try {
            $ts = $this->getById($timesheetId);
            if (!$ts) {
                return ['success' => false, 'error' => 'Timesheet not found'];
            }
            if ($ts['status'] !== self::STATUS_DRAFT) {
                return ['success' => false, 'error' => 'ไม่สามารถเพิ่มคนใน Timesheet ที่ไม่ใช่ Draft'];
            }
            
            // Check person exists
            $stmt = $this->db->prepare("SELECT id, daily_rate FROM people WHERE id = ? AND is_active = 1");
            $stmt->execute([$peopleId]);
            $person = $stmt->fetch();
            if (!$person) {
                return ['success' => false, 'error' => 'ไม่พบบุคคลนี้หรือไม่ active'];
            }
            
            // Check not already in timesheet
            $stmt = $this->db->prepare("SELECT id FROM timesheet_entries WHERE timesheet_id = ? AND people_id = ?");
            $stmt->execute([$timesheetId, $peopleId]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'บุคคลนี้อยู่ใน Timesheet แล้ว'];
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO timesheet_entries (
                    timesheet_id, people_id, is_present, daily_rate,
                    manually_added, manual_add_reason
                ) VALUES (
                    :timesheet_id, :people_id, 1, :daily_rate, 1, :reason
                )
            ");
            $stmt->execute([
                'timesheet_id' => $timesheetId,
                'people_id' => $peopleId,
                'daily_rate' => $person['daily_rate'] ?? 0,
                'reason' => $reason
            ]);
            
            $entryId = (int) $this->db->lastInsertId();
            
            // Update total_workers
            $this->updateTotals($timesheetId);
            
            return ['success' => true, 'id' => $entryId];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Update timesheet entry (only in Draft status)
     */
    public function updateEntry(int $entryId, array $data): array {
        try {
            $entry = $this->getEntryById($entryId);
            if (!$entry) {
                return ['success' => false, 'error' => 'Entry not found'];
            }
            
            $ts = $this->getById($entry['timesheet_id']);
            if ($ts['status'] !== self::STATUS_DRAFT) {
                return ['success' => false, 'error' => 'ไม่สามารถแก้ไข Timesheet ที่ไม่ใช่ Draft (ใช้ Correction แทน)'];
            }
            
            $allowedFields = [
                'is_present', 'absence_reason', 'check_in', 'check_out',
                'break_minutes', 'work_hours', 'ot_hours', 'ot_reason',
                'is_late', 'late_minutes', 'missing_checkout', 'exception_note',
                'daily_rate', 'ot_rate'
            ];
            
            $updates = [];
            $params = [];
            foreach ($data as $field => $value) {
                if (in_array($field, $allowedFields)) {
                    $updates[] = "`$field` = :$field";
                    $params[$field] = $value;
                }
            }
            
            if (empty($updates)) {
                return ['success' => false, 'error' => 'No valid fields to update'];
            }
            
            // Calculate total_cost
            $workHours = $data['work_hours'] ?? $entry['work_hours'];
            $otHours = $data['ot_hours'] ?? $entry['ot_hours'];
            $dailyRate = $data['daily_rate'] ?? $entry['daily_rate'];
            $otRate = $data['ot_rate'] ?? $entry['ot_rate'] ?? ($dailyRate * 1.5 / 8);
            $totalCost = $dailyRate + ($otHours * $otRate);
            
            $updates[] = "`total_cost` = :total_cost";
            $params['total_cost'] = $totalCost;
            $params['id'] = $entryId;
            
            $sql = "UPDATE timesheet_entries SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            // Update timesheet totals
            $this->updateTotals($entry['timesheet_id']);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Remove entry from timesheet (only in Draft status)
     */
    public function removeEntry(int $entryId): array {
        try {
            $entry = $this->getEntryById($entryId);
            if (!$entry) {
                return ['success' => false, 'error' => 'Entry not found'];
            }
            
            $ts = $this->getById($entry['timesheet_id']);
            if ($ts['status'] !== self::STATUS_DRAFT) {
                return ['success' => false, 'error' => 'ไม่สามารถลบ Entry จาก Timesheet ที่ไม่ใช่ Draft'];
            }
            
            $stmt = $this->db->prepare("DELETE FROM timesheet_entries WHERE id = ?");
            $stmt->execute([$entryId]);
            
            $this->updateTotals($entry['timesheet_id']);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Supervisor confirms timesheet - locks entries
     */
    public function confirm(int $timesheetId): array {
        try {
            $ts = $this->getById($timesheetId);
            if (!$ts) {
                return ['success' => false, 'error' => 'Timesheet not found'];
            }
            if ($ts['status'] !== self::STATUS_DRAFT) {
                return ['success' => false, 'error' => 'เฉพาะ Timesheet ที่เป็น Draft เท่านั้นที่สามารถ Confirm ได้'];
            }
            
            // Check for anomalies
            $anomalies = $this->detectAnomalies($timesheetId);
            
            $this->db->beginTransaction();
            
            // Update status
            $stmt = $this->db->prepare("
                UPDATE timesheets 
                SET status = 'Confirmed', 
                    confirmed_at = NOW(), 
                    confirmed_by = ?,
                    supervisor_notes = ?
                WHERE id = ?
            ");
            $supervisorNotes = null;
            if (!empty($anomalies)) {
                $supervisorNotes = 'Anomalies detected: ' . implode('; ', $anomalies);
            }
            $stmt->execute([$_SESSION['user_id'], $supervisorNotes, $timesheetId]);
            
            // Log status change
            $this->logStatusChange($timesheetId, self::STATUS_DRAFT, self::STATUS_CONFIRMED);
            
            // Auto-submit to HR
            $stmt = $this->db->prepare("
                UPDATE timesheets 
                SET status = 'Submitted', 
                    submitted_at = NOW(), 
                    submitted_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $timesheetId]);
            
            $this->logStatusChange($timesheetId, self::STATUS_CONFIRMED, self::STATUS_SUBMITTED);
            
            // Audit log
            $this->audit->log(
                'confirm',
                'TIMESHEET',
                $timesheetId,
                ['status' => self::STATUS_DRAFT],
                ['status' => self::STATUS_SUBMITTED, 'anomalies' => $anomalies]
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'anomalies' => $anomalies
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * HR marks timesheet as payroll ready
     */
    public function markPayrollReady(int $timesheetId): array {
        try {
            $ts = $this->getById($timesheetId);
            if (!$ts) {
                return ['success' => false, 'error' => 'Timesheet not found'];
            }
            if ($ts['status'] !== self::STATUS_SUBMITTED && $ts['status'] !== self::STATUS_RETURNED) {
                return ['success' => false, 'error' => 'Timesheet ต้องอยู่ในสถานะ Submitted หรือ Returned'];
            }
            
            $oldStatus = $ts['status'];
            
            $stmt = $this->db->prepare("
                UPDATE timesheets 
                SET status = 'PayrollReady', 
                    payroll_ready_at = NOW(), 
                    payroll_ready_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $timesheetId]);
            
            $this->logStatusChange($timesheetId, $oldStatus, self::STATUS_PAYROLL_READY);
            
            $this->audit->log(
                'payroll_ready',
                'TIMESHEET',
                $timesheetId,
                ['status' => $oldStatus],
                ['status' => self::STATUS_PAYROLL_READY]
            );
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * HR returns timesheet for correction
     */
    public function returnForCorrection(int $timesheetId, string $reason): array {
        try {
            $ts = $this->getById($timesheetId);
            if (!$ts) {
                return ['success' => false, 'error' => 'Timesheet not found'];
            }
            if ($ts['status'] !== self::STATUS_SUBMITTED) {
                return ['success' => false, 'error' => 'เฉพาะ Timesheet ที่ Submitted แล้วเท่านั้น'];
            }
            
            $stmt = $this->db->prepare("
                UPDATE timesheets 
                SET status = 'Returned', 
                    returned_at = NOW(), 
                    returned_by = ?,
                    return_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $reason, $timesheetId]);
            
            $this->logStatusChange($timesheetId, self::STATUS_SUBMITTED, self::STATUS_RETURNED, $reason);
            
            $this->audit->log(
                'return',
                'TIMESHEET',
                $timesheetId,
                ['status' => self::STATUS_SUBMITTED],
                ['status' => self::STATUS_RETURNED, 'reason' => $reason]
            );
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Create a correction request for confirmed timesheet
     */
    public function requestCorrection(int $timesheetId, ?int $entryId, string $correctionType, 
                                       string $fieldName, ?string $oldValue, string $newValue, string $reason): array {
        try {
            $ts = $this->getById($timesheetId);
            if (!$ts) {
                return ['success' => false, 'error' => 'Timesheet not found'];
            }
            if ($ts['status'] === self::STATUS_DRAFT) {
                return ['success' => false, 'error' => 'Timesheet ที่เป็น Draft สามารถแก้ไขได้โดยตรง'];
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO timesheet_corrections (
                    timesheet_id, entry_id, correction_type, field_name,
                    old_value, new_value, reason, requested_by
                ) VALUES (
                    :timesheet_id, :entry_id, :correction_type, :field_name,
                    :old_value, :new_value, :reason, :requested_by
                )
            ");
            $stmt->execute([
                'timesheet_id' => $timesheetId,
                'entry_id' => $entryId,
                'correction_type' => $correctionType,
                'field_name' => $fieldName,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'reason' => $reason,
                'requested_by' => $_SESSION['user_id']
            ]);
            
            $correctionId = (int) $this->db->lastInsertId();
            
            $this->audit->log(
                'correction_request',
                'TIMESHEET',
                $timesheetId,
                null,
                ['correction_id' => $correctionId, 'field' => $fieldName, 'new_value' => $newValue]
            );
            
            return ['success' => true, 'id' => $correctionId];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Detect anomalies in timesheet entries
     */
    public function detectAnomalies(int $timesheetId): array {
        $anomalies = [];
        
        $entries = $this->getEntries($timesheetId);
        
        foreach ($entries as $entry) {
            // OT > threshold
            if ($entry['ot_hours'] > self::OT_THRESHOLD_HOURS) {
                $anomalies[] = "{$entry['full_name']}: OT {$entry['ot_hours']} ชม. (เกิน {self::OT_THRESHOLD_HOURS} ชม.)";
            }
            
            // Missing checkout
            if ($entry['is_present'] && empty($entry['check_out']) && !empty($entry['check_in'])) {
                $anomalies[] = "{$entry['full_name']}: ไม่มี Check-out";
            }
            
            // Manually added without plan
            if ($entry['manually_added']) {
                $anomalies[] = "{$entry['full_name']}: เพิ่มด้วยมือ (ไม่อยู่ใน Plan)";
            }
        }
        
        return $anomalies;
    }
    
    /**
     * Update timesheet totals
     */
    private function updateTotals(int $timesheetId): void {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_workers,
                COALESCE(SUM(work_hours), 0) as total_hours,
                COALESCE(SUM(ot_hours), 0) as total_ot_hours
            FROM timesheet_entries
            WHERE timesheet_id = ? AND is_present = 1
        ");
        $stmt->execute([$timesheetId]);
        $totals = $stmt->fetch();
        
        $stmt = $this->db->prepare("
            UPDATE timesheets 
            SET total_workers = ?, total_hours = ?, total_ot_hours = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $totals['total_workers'],
            $totals['total_hours'],
            $totals['total_ot_hours'],
            $timesheetId
        ]);
    }
    
    /**
     * Get next daily sequence number for TS number
     */
    private function getNextDailySequence(string $workDate): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) + 1 as next_seq
            FROM timesheets
            WHERE work_date = ?
        ");
        $stmt->execute([$workDate]);
        $result = $stmt->fetch();
        return (int) $result['next_seq'];
    }
    
    /**
     * Log status change to history table
     */
    private function logStatusChange(int $timesheetId, ?string $oldStatus, string $newStatus, ?string $reason = null): void {
        $stmt = $this->db->prepare("
            INSERT INTO timesheet_status_history (timesheet_id, old_status, new_status, changed_by, reason)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$timesheetId, $oldStatus, $newStatus, $_SESSION['user_id'], $reason]);
    }
    
    // ==================== GETTERS ====================
    
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT t.*, j.job_number, j.scope_short as job_scope,
                   c.name as customer_name, s.name as site_name
            FROM timesheets t
            JOIN jobs j ON t.job_id = j.id
            JOIN customers c ON j.customer_id = c.id
            LEFT JOIN sites s ON t.site_id = s.id
            WHERE t.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public function getByJobAndDate(int $jobId, string $workDate): ?array {
        $stmt = $this->db->prepare("SELECT * FROM timesheets WHERE job_id = ? AND work_date = ?");
        $stmt->execute([$jobId, $workDate]);
        return $stmt->fetch() ?: null;
    }
    
    public function getEntries(int $timesheetId): array {
        $stmt = $this->db->prepare("
            SELECT te.*, p.code as people_code, p.full_name, p.position
            FROM timesheet_entries te
            JOIN people p ON te.people_id = p.id
            WHERE te.timesheet_id = ?
            ORDER BY p.full_name
        ");
        $stmt->execute([$timesheetId]);
        return $stmt->fetchAll();
    }
    
    public function getEntryById(int $entryId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM timesheet_entries WHERE id = ?");
        $stmt->execute([$entryId]);
        return $stmt->fetch() ?: null;
    }
    
    public function getCorrections(int $timesheetId): array {
        $stmt = $this->db->prepare("
            SELECT tc.*, u.full_name as requested_by_name
            FROM timesheet_corrections tc
            JOIN users u ON tc.requested_by = u.id
            WHERE tc.timesheet_id = ?
            ORDER BY tc.requested_at DESC
        ");
        $stmt->execute([$timesheetId]);
        return $stmt->fetchAll();
    }
    
    public function getStatusHistory(int $timesheetId): array {
        $stmt = $this->db->prepare("
            SELECT tsh.*, u.full_name as changed_by_name
            FROM timesheet_status_history tsh
            JOIN users u ON tsh.changed_by = u.id
            WHERE tsh.timesheet_id = ?
            ORDER BY tsh.created_at ASC
        ");
        $stmt->execute([$timesheetId]);
        return $stmt->fetchAll();
    }
    
    public function getList(array $filters = [], int $limit = 50, int $offset = 0): array {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['job_id'])) {
            $where[] = 't.job_id = :job_id';
            $params['job_id'] = $filters['job_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 't.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 't.work_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 't.work_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        
        $sql = "
            SELECT t.*, j.job_number, c.name as customer_name, s.name as site_name
            FROM timesheets t
            JOIN jobs j ON t.job_id = j.id
            JOIN customers c ON j.customer_id = c.id
            LEFT JOIN sites s ON t.site_id = s.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.work_date DESC, t.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function getPendingForHR(): array {
        return $this->getList(['status' => self::STATUS_SUBMITTED]);
    }
    
    private function getJob(int $jobId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        return $stmt->fetch() ?: null;
    }
}
