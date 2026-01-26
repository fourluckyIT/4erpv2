<?php
/**
 * Compliance Gate Model Class
 * 4ERP - M11: Compliance Gate
 * 
 * Handles:
 * - Site-specific compliance requirements
 * - Certificate tracking for people and serials
 * - Pre-dispatch compliance checks
 * - Override with manager approval
 * 
 * Following blueprint.md §14 and agents.md §3.5
 */

class Compliance {
    private PDO $db;
    private AuditLog $audit;
    
    const STATUS_PASS = 'Pass';
    const STATUS_FAIL = 'Fail';
    const STATUS_WARNING = 'Warning';
    const STATUS_OVERRIDE = 'Override';
    const STATUS_NA = 'NA';
    
    public function __construct() {
        $this->db = getDB();
        $this->audit = new AuditLog();
    }
    
    // ==================== REQUIREMENTS ====================
    
    /**
     * Get site requirements
     */
    public function getSiteRequirements(int $siteId, bool $activeOnly = true): array {
        $sql = "SELECT * FROM compliance_requirements WHERE site_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY is_mandatory DESC, requirement_type, name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$siteId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Add requirement to site
     */
    public function addRequirement(int $siteId, array $data): array {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO compliance_requirements (
                    site_id, requirement_type, name, description,
                    is_mandatory, applies_to, validity_days, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $siteId,
                $data['requirement_type'],
                $data['name'],
                $data['description'] ?? null,
                $data['is_mandatory'] ?? 1,
                $data['applies_to'] ?? 'All',
                $data['validity_days'] ?? null,
                $_SESSION['user_id']
            ]);
            
            return ['success' => true, 'id' => (int) $this->db->lastInsertId()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // ==================== CERTIFICATES ====================
    
    /**
     * Add certificate to person
     */
    public function addPeopleCertificate(int $peopleId, array $data): array {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO people_certificates (
                    people_id, certificate_type, certificate_number,
                    issuer, issue_date, expiry_date, file_path,
                    status, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $peopleId,
                $data['certificate_type'],
                $data['certificate_number'] ?? null,
                $data['issuer'] ?? null,
                $data['issue_date'] ?? null,
                $data['expiry_date'] ?? null,
                $data['file_path'] ?? null,
                $data['status'] ?? 'Valid',
                $data['notes'] ?? null,
                $_SESSION['user_id']
            ]);
            
            return ['success' => true, 'id' => (int) $this->db->lastInsertId()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get person certificates
     */
    public function getPeopleCertificates(int $peopleId): array {
        $stmt = $this->db->prepare("
            SELECT pc.*, 
                   CASE 
                       WHEN pc.expiry_date IS NULL THEN 'Valid'
                       WHEN pc.expiry_date < CURDATE() THEN 'Expired'
                       WHEN pc.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Expiring'
                       ELSE 'Valid'
                   END as validity_status
            FROM people_certificates pc
            WHERE pc.people_id = ?
            ORDER BY pc.expiry_date ASC
        ");
        $stmt->execute([$peopleId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Add certificate to serial
     */
    public function addSerialCertificate(int $serialId, array $data): array {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO serial_certificates (
                    serial_id, certificate_type, certificate_number,
                    issuer, issue_date, expiry_date, file_path,
                    status, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $serialId,
                $data['certificate_type'],
                $data['certificate_number'] ?? null,
                $data['issuer'] ?? null,
                $data['issue_date'] ?? null,
                $data['expiry_date'] ?? null,
                $data['file_path'] ?? null,
                $data['status'] ?? 'Valid',
                $data['notes'] ?? null,
                $_SESSION['user_id']
            ]);
            
            return ['success' => true, 'id' => (int) $this->db->lastInsertId()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get serial certificates
     */
    public function getSerialCertificates(int $serialId): array {
        $stmt = $this->db->prepare("
            SELECT sc.*,
                   CASE 
                       WHEN sc.expiry_date IS NULL THEN 'Valid'
                       WHEN sc.expiry_date < CURDATE() THEN 'Expired'
                       WHEN sc.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Expiring'
                       ELSE 'Valid'
                   END as validity_status
            FROM serial_certificates sc
            WHERE sc.serial_id = ?
            ORDER BY sc.expiry_date ASC
        ");
        $stmt->execute([$serialId]);
        return $stmt->fetchAll();
    }
    
    // ==================== COMPLIANCE CHECKS ====================
    
    /**
     * Perform pre-dispatch compliance check for a plan
     */
    public function checkPlanCompliance(int $planId): array {
        $plan = new Plan();
        $planData = $plan->getById($planId);
        
        if (!$planData) {
            return ['success' => false, 'error' => 'Plan not found'];
        }
        
        // Get site requirements
        $stmt = $this->db->prepare("SELECT site_id FROM jobs WHERE id = ?");
        $stmt->execute([$planData['job_id']]);
        $siteId = $stmt->fetchColumn();
        
        if (!$siteId) {
            return ['success' => true, 'status' => self::STATUS_PASS, 'items' => [], 'message' => 'No site requirements'];
        }
        
        $requirements = $this->getSiteRequirements($siteId);
        $assignments = $plan->getAssignments($planId);
        
        $checkItems = [];
        $passed = 0;
        $failed = 0;
        
        foreach ($assignments as $a) {
            // Check people certificates
            if ($a['people_id']) {
                $personCerts = $this->getPeopleCertificates($a['people_id']);
                
                foreach ($requirements as $req) {
                    if ($req['applies_to'] !== 'All' && $req['applies_to'] !== 'People') {
                        continue;
                    }
                    if ($req['requirement_type'] !== 'Certificate') {
                        continue;
                    }
                    
                    $status = self::STATUS_FAIL;
                    $details = 'Certificate not found';
                    $certId = null;
                    $expiryDate = null;
                    
                    foreach ($personCerts as $cert) {
                        if (stripos($cert['certificate_type'], $req['name']) !== false || 
                            stripos($req['name'], $cert['certificate_type']) !== false) {
                            
                            $certId = $cert['id'];
                            $expiryDate = $cert['expiry_date'];
                            
                            if ($cert['validity_status'] === 'Expired') {
                                $status = self::STATUS_FAIL;
                                $details = 'Certificate expired on ' . $cert['expiry_date'];
                            } elseif ($cert['validity_status'] === 'Expiring') {
                                $status = self::STATUS_WARNING;
                                $details = 'Certificate expiring on ' . $cert['expiry_date'];
                            } else {
                                $status = self::STATUS_PASS;
                                $details = 'Valid until ' . ($cert['expiry_date'] ?? 'N/A');
                            }
                            break;
                        }
                    }
                    
                    if ($status === self::STATUS_PASS || $status === self::STATUS_WARNING) {
                        $passed++;
                    } elseif ($req['is_mandatory']) {
                        $failed++;
                    }
                    
                    $checkItems[] = [
                        'requirement_id' => $req['id'],
                        'entity_type' => 'People',
                        'entity_id' => $a['people_id'],
                        'entity_name' => $a['people_name'],
                        'requirement_name' => $req['name'],
                        'status' => $status,
                        'details' => $details,
                        'certificate_id' => $certId,
                        'expiry_date' => $expiryDate,
                        'is_mandatory' => $req['is_mandatory']
                    ];
                }
            }
            
            // Check serial certificates
            if ($a['serial_id']) {
                $serialCerts = $this->getSerialCertificates($a['serial_id']);
                
                foreach ($requirements as $req) {
                    if ($req['applies_to'] !== 'All' && $req['applies_to'] !== 'Serials') {
                        continue;
                    }
                    if ($req['requirement_type'] !== 'Certificate') {
                        continue;
                    }
                    
                    $status = self::STATUS_FAIL;
                    $details = 'Certificate not found';
                    $certId = null;
                    $expiryDate = null;
                    
                    foreach ($serialCerts as $cert) {
                        if (stripos($cert['certificate_type'], $req['name']) !== false ||
                            stripos($req['name'], $cert['certificate_type']) !== false) {
                            
                            $certId = $cert['id'];
                            $expiryDate = $cert['expiry_date'];
                            
                            if ($cert['validity_status'] === 'Expired') {
                                $status = self::STATUS_FAIL;
                                $details = 'Certificate expired on ' . $cert['expiry_date'];
                            } elseif ($cert['validity_status'] === 'Expiring') {
                                $status = self::STATUS_WARNING;
                                $details = 'Certificate expiring on ' . $cert['expiry_date'];
                            } else {
                                $status = self::STATUS_PASS;
                                $details = 'Valid until ' . ($cert['expiry_date'] ?? 'N/A');
                            }
                            break;
                        }
                    }
                    
                    if ($status === self::STATUS_PASS || $status === self::STATUS_WARNING) {
                        $passed++;
                    } elseif ($req['is_mandatory']) {
                        $failed++;
                    }
                    
                    $checkItems[] = [
                        'requirement_id' => $req['id'],
                        'entity_type' => 'Serial',
                        'entity_id' => $a['serial_id'],
                        'entity_name' => $a['serial_number'] . ' (' . $a['item_name'] . ')',
                        'requirement_name' => $req['name'],
                        'status' => $status,
                        'details' => $details,
                        'certificate_id' => $certId,
                        'expiry_date' => $expiryDate,
                        'is_mandatory' => $req['is_mandatory']
                    ];
                }
            }
        }
        
        $overallStatus = $failed > 0 ? self::STATUS_FAIL : self::STATUS_PASS;
        
        return [
            'success' => true,
            'status' => $overallStatus,
            'total' => count($checkItems),
            'passed' => $passed,
            'failed' => $failed,
            'items' => $checkItems
        ];
    }
    
    /**
     * Record a compliance check
     */
    public function recordCheck(int $jobId, ?int $planId, string $checkType, array $checkResult, ?string $overrideReason = null, ?int $overrideBy = null): array {
        try {
            $this->db->beginTransaction();
            
            $overallStatus = $checkResult['status'];
            if ($overrideReason && $checkResult['status'] === self::STATUS_FAIL) {
                $overallStatus = 'PassWithOverride';
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO compliance_checks (
                    job_id, plan_id, check_type, checked_at, checked_by,
                    overall_status, total_requirements, passed_requirements,
                    failed_requirements, override_reason, override_approved_by
                ) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $jobId,
                $planId,
                $checkType,
                $_SESSION['user_id'],
                $overallStatus,
                $checkResult['total'] ?? 0,
                $checkResult['passed'] ?? 0,
                $checkResult['failed'] ?? 0,
                $overrideReason,
                $overrideBy
            ]);
            
            $checkId = (int) $this->db->lastInsertId();
            
            // Record check items
            if (!empty($checkResult['items'])) {
                $stmt = $this->db->prepare("
                    INSERT INTO compliance_check_items (
                        check_id, requirement_id, entity_type, entity_id,
                        entity_name, requirement_name, status, details,
                        certificate_id, expiry_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($checkResult['items'] as $item) {
                    $stmt->execute([
                        $checkId,
                        $item['requirement_id'] ?? null,
                        $item['entity_type'],
                        $item['entity_id'] ?? null,
                        $item['entity_name'] ?? null,
                        $item['requirement_name'],
                        $item['status'],
                        $item['details'] ?? null,
                        $item['certificate_id'] ?? null,
                        $item['expiry_date'] ?? null
                    ]);
                }
            }
            
            $this->audit->log(AUDIT_ACTION_CREATE, 'COMPLIANCE_CHECK', $checkId, null, [
                'job_id' => $jobId, 'status' => $overallStatus
            ]);
            
            $this->db->commit();
            
            return ['success' => true, 'id' => $checkId, 'status' => $overallStatus];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Check if dispatch is allowed (compliance gate)
     */
    public function canDispatch(int $planId): array {
        $result = $this->checkPlanCompliance($planId);
        
        if (!$result['success']) {
            return $result;
        }
        
        if ($result['status'] === self::STATUS_PASS) {
            return ['allowed' => true, 'message' => 'All compliance checks passed'];
        }
        
        // Check if there's a recent override
        $plan = new Plan();
        $planData = $plan->getById($planId);
        
        $stmt = $this->db->prepare("
            SELECT * FROM compliance_checks 
            WHERE plan_id = ? AND overall_status = 'PassWithOverride'
            AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY checked_at DESC LIMIT 1
        ");
        $stmt->execute([$planId]);
        $override = $stmt->fetch();
        
        if ($override) {
            return [
                'allowed' => true,
                'message' => 'Compliance check overridden by manager',
                'override' => $override
            ];
        }
        
        return [
            'allowed' => false,
            'message' => 'Compliance check failed. Manager override required.',
            'failed_items' => array_filter($result['items'], fn($i) => $i['status'] === self::STATUS_FAIL && $i['is_mandatory'])
        ];
    }
    
    // ==================== REPORTS ====================
    
    /**
     * Get expiring certificates
     */
    public function getExpiringCertificates(int $daysAhead = 30): array {
        $results = [];
        
        // People certificates
        $stmt = $this->db->prepare("
            SELECT pc.*, p.full_name as people_name, p.code as people_code,
                   'People' as entity_type
            FROM people_certificates pc
            JOIN people p ON pc.people_id = p.id
            WHERE pc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
              AND pc.status = 'Valid'
            ORDER BY pc.expiry_date ASC
        ");
        $stmt->execute([$daysAhead]);
        $results['people'] = $stmt->fetchAll();
        
        // Serial certificates
        $stmt = $this->db->prepare("
            SELECT sc.*, s.serial_number, i.name as item_name,
                   'Serial' as entity_type
            FROM serial_certificates sc
            JOIN serials s ON sc.serial_id = s.id
            JOIN items i ON s.item_id = i.id
            WHERE sc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
              AND sc.status = 'Valid'
            ORDER BY sc.expiry_date ASC
        ");
        $stmt->execute([$daysAhead]);
        $results['serials'] = $stmt->fetchAll();
        
        return $results;
    }
    
    /**
     * Get compliance check history for a job
     */
    public function getJobCheckHistory(int $jobId): array {
        $stmt = $this->db->prepare("
            SELECT cc.*, u.full_name as checked_by_name,
                   u2.full_name as override_by_name
            FROM compliance_checks cc
            JOIN users u ON cc.checked_by = u.id
            LEFT JOIN users u2 ON cc.override_approved_by = u2.id
            WHERE cc.job_id = ?
            ORDER BY cc.checked_at DESC
        ");
        $stmt->execute([$jobId]);
        return $stmt->fetchAll();
    }
}
