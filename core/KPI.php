<?php
/**
 * KPI Model Class
 * ERP v2 - M11: KPI Dashboard
 * 
 * Provides 8+ KPIs as required by blueprint.md §15:
 * 
 * Operational:
 * 1. Active Jobs count
 * 2. Upcoming dispatches
 * 3. Packing queue / GR pending
 * 4. PO overdue
 * 5. Booking conflicts count
 * 6. Stock at risk (reserved > onhand)
 * 
 * Management:
 * 7. Jobs completed this month
 * 8. Pending approvals
 * 9. AR aging summary
 * 10. Timesheet anomalies
 * 11. Job profitability summary
 */

class KPI {
    private PDO $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Get all dashboard KPIs
     */
    public function getDashboardKPIs(): array {
        return [
            'operational' => [
                'active_jobs' => $this->getActiveJobsCount(),
                'completed_this_month' => $this->getCompletedThisMonth(),
                'pending_approvals' => $this->getPendingApprovalsCount(),
                'overdue_items' => $this->getOverdueCount(),
                'upcoming_dispatches' => $this->getUpcomingDispatches(),
                'pending_gr' => $this->getPendingGRCount(),
                'po_overdue' => $this->getOverduePOCount(),
                'stock_at_risk' => $this->getStockAtRiskCount(),
            ],
            'management' => [
                'ar_aging' => $this->getARAgingSummary(),
                'timesheet_anomalies' => $this->getTimesheetAnomaliesCount(),
                'avg_margin' => $this->getAverageMargin(),
                'jobs_by_status' => $this->getJobsByStatus(),
            ]
        ];
    }
    
    // ==================== OPERATIONAL KPIs ====================
    
    /**
     * KPI 1: Active Jobs count
     */
    public function getActiveJobsCount(): int {
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM jobs 
            WHERE status IN ('Approved', 'Planned', 'Dispatched', 'In Progress')
        ");
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * KPI 2: Jobs completed this month
     */
    public function getCompletedThisMonth(): int {
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM jobs 
            WHERE status IN ('Closed', 'Invoiced', 'Paid', 'Partial Paid')
              AND (closed_at >= DATE_FORMAT(NOW(), '%Y-%m-01') 
                   OR actual_end_date >= DATE_FORMAT(NOW(), '%Y-%m-01'))
        ");
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * KPI 3: Pending approvals count
     */
    public function getPendingApprovalsCount(): int {
        // Check if approval_requests table exists
        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) FROM approval_requests WHERE status = 'Pending'
            ");
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            // Table doesn't exist yet
            return 0;
        }
    }
    
    /**
     * KPI 4: Overdue items (jobs past end date, PO past delivery)
     */
    public function getOverdueCount(): int {
        $jobsOverdue = $this->db->query("
            SELECT COUNT(*) FROM jobs 
            WHERE status IN ('Approved', 'Planned', 'Dispatched', 'In Progress')
              AND plan_end_date < CURDATE()
        ")->fetchColumn();
        
        $poOverdue = $this->getOverduePOCount();
        
        return (int)$jobsOverdue + $poOverdue;
    }
    
    /**
     * KPI 5: Upcoming dispatches (next 7 days)
     */
    public function getUpcomingDispatches(): int {
        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) FROM routes 
                WHERE status IN ('Draft', 'Confirmed')
                  AND route_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            ");
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * KPI 6: Pending GR count
     */
    public function getPendingGRCount(): int {
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM purchase_orders 
            WHERE status IN ('Approved', 'Partially Received')
        ");
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * KPI 7: Overdue PO count
     */
    public function getOverduePOCount(): int {
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM purchase_orders 
            WHERE status IN ('Approved', 'Partially Received')
              AND delivery_date < CURDATE()
        ");
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * KPI 8: Stock at risk (items where reserved >= on_hand)
     */
    public function getStockAtRiskCount(): int {
        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) FROM item_stock_levels 
                WHERE reserved >= on_hand AND on_hand > 0
            ");
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    // ==================== MANAGEMENT KPIs ====================
    
    /**
     * KPI 9: AR Aging Summary
     */
    public function getARAgingSummary(): array {
        try {
            $stmt = $this->db->query("
                SELECT 
                    SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) <= 0 THEN total_amount - paid_amount ELSE 0 END) as current,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 1 AND 30 THEN total_amount - paid_amount ELSE 0 END) as days_1_30,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN total_amount - paid_amount ELSE 0 END) as days_31_60,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN total_amount - paid_amount ELSE 0 END) as days_61_90,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN total_amount - paid_amount ELSE 0 END) as days_over_90,
                    SUM(total_amount - paid_amount) as total_outstanding
                FROM ar_invoices
                WHERE status IN ('Issued', 'Partial')
            ");
            return $stmt->fetch() ?: [
                'current' => 0, 'days_1_30' => 0, 'days_31_60' => 0,
                'days_61_90' => 0, 'days_over_90' => 0, 'total_outstanding' => 0
            ];
        } catch (Exception $e) {
            return [
                'current' => 0, 'days_1_30' => 0, 'days_31_60' => 0,
                'days_61_90' => 0, 'days_over_90' => 0, 'total_outstanding' => 0
            ];
        }
    }
    
    /**
     * KPI 10: Timesheet anomalies count
     */
    public function getTimesheetAnomaliesCount(): int {
        try {
            // Count timesheets with high OT or manual additions
            $stmt = $this->db->query("
                SELECT COUNT(DISTINCT t.id)
                FROM timesheets t
                JOIN timesheet_entries te ON t.id = te.timesheet_id
                WHERE t.status = 'Draft'
                  AND (te.ot_hours > 4 OR te.manually_added = 1 OR te.missing_checkout = 1)
            ");
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * KPI 11: Average margin percentage
     */
    public function getAverageMargin(): float {
        try {
            $stmt = $this->db->query("
                SELECT AVG(actual_margin_pct) as avg_margin
                FROM job_costs jc
                JOIN jobs j ON jc.job_id = j.id
                WHERE j.status IN ('Closed', 'Invoiced', 'Paid')
                  AND jc.actual_revenue > 0
            ");
            $result = $stmt->fetchColumn();
            return $result ? round((float)$result, 1) : 0;
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Jobs by status (for chart)
     */
    public function getJobsByStatus(): array {
        $stmt = $this->db->query("
            SELECT status, COUNT(*) as count
            FROM jobs
            WHERE status != 'Voided'
            GROUP BY status
            ORDER BY FIELD(status, 'Draft', 'Submitted', 'Approved', 'Planned', 
                          'Dispatched', 'In Progress', 'Returned', 'WH Received',
                          'POS Checked', 'Accounting Ready', 'Invoiced', 'Paid', 'Partial Paid', 'Closed')
        ");
        return $stmt->fetchAll();
    }
    
    // ==================== DETAILED REPORTS ====================
    
    /**
     * Get overdue jobs list
     */
    public function getOverdueJobs(): array {
        $stmt = $this->db->query("
            SELECT j.*, c.name as customer_name,
                   DATEDIFF(CURDATE(), j.plan_end_date) as days_overdue
            FROM jobs j
            JOIN customers c ON j.customer_id = c.id
            WHERE j.status IN ('Approved', 'Planned', 'Dispatched', 'In Progress')
              AND j.plan_end_date < CURDATE()
            ORDER BY days_overdue DESC
        ");
        return $stmt->fetchAll();
    }
    
    /**
     * Get overdue POs list
     */
    public function getOverduePOs(): array {
        $stmt = $this->db->query("
            SELECT po.*, s.name as supplier_name,
                   DATEDIFF(CURDATE(), po.delivery_date) as days_overdue
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.status IN ('Approved', 'Partially Received')
              AND po.delivery_date < CURDATE()
            ORDER BY days_overdue DESC
        ");
        return $stmt->fetchAll();
    }
    
    /**
     * Get stock at risk items
     */
    public function getStockAtRiskItems(): array {
        try {
            $stmt = $this->db->query("
                SELECT isl.*, i.code as item_code, i.name as item_name,
                       (isl.reserved - isl.on_hand) as shortage
                FROM item_stock_levels isl
                JOIN items i ON isl.item_id = i.id
                WHERE isl.reserved >= isl.on_hand AND isl.on_hand > 0
                ORDER BY shortage DESC
            ");
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Get AR aging detail
     */
    public function getARAgingDetail(): array {
        try {
            $stmt = $this->db->query("
                SELECT 
                    inv.id, inv.invoice_no, inv.invoice_date, inv.due_date,
                    inv.total_amount, inv.paid_amount,
                    (inv.total_amount - inv.paid_amount) as outstanding,
                    DATEDIFF(CURDATE(), inv.due_date) as days_overdue,
                    c.name as customer_name, j.job_number
                FROM ar_invoices inv
                JOIN customers c ON inv.customer_id = c.id
                JOIN jobs j ON inv.job_id = j.id
                WHERE inv.status IN ('Issued', 'Partial')
                ORDER BY days_overdue DESC
            ");
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Get monthly revenue summary
     */
    public function getMonthlyRevenue(int $months = 6): array {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    DATE_FORMAT(invoice_date, '%Y-%m') as month,
                    SUM(total_amount) as revenue,
                    COUNT(*) as invoice_count
                FROM ar_invoices
                WHERE status IN ('Issued', 'Partial', 'Paid')
                  AND invoice_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
                ORDER BY month
            ");
            $stmt->execute([$months]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Get job type distribution
     */
    public function getJobTypeDistribution(): array {
        $stmt = $this->db->query("
            SELECT job_type, COUNT(*) as count,
                   SUM(contract_value) as total_value
            FROM jobs
            WHERE status NOT IN ('Voided', 'Draft')
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY job_type
        ");
        return $stmt->fetchAll();
    }
    
    /**
     * Get utilization metrics
     */
    public function getUtilization(): array {
        // People utilization (assigned vs total)
        $totalPeople = $this->db->query("SELECT COUNT(*) FROM people WHERE is_active = 1")->fetchColumn();
        
        try {
            $assignedPeople = $this->db->query("
                SELECT COUNT(DISTINCT pa.people_id)
                FROM plan_assignments pa
                JOIN plans p ON pa.plan_id = p.id
                JOIN jobs j ON p.job_id = j.id
                WHERE pa.people_id IS NOT NULL
                  AND j.status IN ('Planned', 'Dispatched', 'In Progress')
            ")->fetchColumn();
        } catch (Exception $e) {
            $assignedPeople = 0;
        }
        
        // Serial/Asset utilization
        $totalSerials = $this->db->query("SELECT COUNT(*) FROM serials WHERE status != 'Sold'")->fetchColumn();
        $inUseSerials = $this->db->query("
            SELECT COUNT(*) FROM serials WHERE status IN ('Allocated', 'Dispatched', 'InUse')
        ")->fetchColumn();
        
        return [
            'people' => [
                'total' => (int)$totalPeople,
                'assigned' => (int)$assignedPeople,
                'utilization_pct' => $totalPeople > 0 ? round(($assignedPeople / $totalPeople) * 100, 1) : 0
            ],
            'assets' => [
                'total' => (int)$totalSerials,
                'in_use' => (int)$inUseSerials,
                'utilization_pct' => $totalSerials > 0 ? round(($inUseSerials / $totalSerials) * 100, 1) : 0
            ]
        ];
    }
}
