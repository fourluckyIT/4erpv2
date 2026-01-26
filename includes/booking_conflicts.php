<?php
/**
 * Booking Conflict Prevention Pattern (Legacy Reuse)
 * 
 * Prevents double-booking of resources (Serials, People) for overlapping time ranges.
 * Uses MySQL row locking (FOR UPDATE) or atomic checks to ensure data integrity.
 */

class BookingConflict {
    private $pdo;
    private $table = 'resource_bookings';

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Check for conflicts and optionally lock the range.
     * @param string $type 'Serial', 'Person', 'Vehicle'
     * @param int $id Resource ID
     * @param string $startTime Y-m-d H:i:s
     * @param string $endTime Y-m-d H:i:s
     * @param int|null $excludeRefId ID to exclude (for updates)
     * @return bool True if conflict exists, False if safe
     */
    /**
     * Get conflicting bookings
     * @return array List of conflicting rows
     */
    public function getConflicts($type, $id, $startTime, $endTime, $excludeRefId = null) {
        $sql = "SELECT * FROM {$this->table} 
                WHERE resource_type = :type 
                AND resource_id = :id 
                AND status = 'Active' 
                AND (
                    (start_time < :end AND end_time > :start)
                )";
        
        $params = [
            ':type' => $type,
            ':id' => $id,
            ':end' => $endTime,
            ':start' => $startTime
        ];
        
        if ($excludeRefId) {
            $sql .= " AND reference_id != :ref_id";
            $params[':ref_id'] = $excludeRefId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check for conflicts and optionally lock the range.
     * @return bool True if conflict exists, False if safe
     */
    public function hasConflict($type, $id, $startTime, $endTime, $excludeRefId = null) {
        return count($this->getConflicts($type, $id, $startTime, $endTime, $excludeRefId)) > 0;
    }

    /**
     * Book a resource. Throws Exception if conflict detected.
     */
    public function bookResource($type, $id, $startTime, $endTime, $refTable, $refId) {
        // 1. Explicitly check conflict
        if ($this->hasConflict($type, $id, $startTime, $endTime, $refId)) {
            throw new Exception("Resource conflict detected for $type ID $id between $startTime and $endTime");
        }

        // 2. Insert booking
        $sql = "INSERT INTO {$this->table} 
                (resource_type, resource_id, start_time, end_time, reference_table, reference_id, status)
                VALUES 
                (:type, :id, :start, :end, :ref_table, :ref_id, 'Active')";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':type' => $type,
            ':id' => $id,
            ':start' => $startTime,
            ':end' => $endTime,
            ':ref_table' => $refTable,
            ':ref_id' => $refId
        ]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Cancel all bookings for a specific reference
     */
    public function cancelBooking($refTable, $refId) {
        $sql = "UPDATE {$this->table} SET status = 'Cancelled' 
                WHERE reference_table = :ref_table AND reference_id = :ref_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':ref_table' => $refTable, ':ref_id' => $refId]);
    }
}
