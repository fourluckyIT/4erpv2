<?php
/**
 * Status Machine for Jobs
 * 4ERP - Phase 2
 * 
 * Enforces:
 * - Valid status transitions only
 * - Role-based permission per status
 * - Lockpoints (what can/cannot be edited)
 * - Reason requirement for void/cancel
 */

class StatusMachine {
    
    // All valid statuses (16 total)
    const STATUSES = [
        'Draft',
        'Submitted',
        'Approved',
        'Planned',
        'Dispatched',
        'In Progress',
        'Returned',
        'WH Received',
        'POS Checked',
        'Accounting Ready',
        'Invoiced',
        'Paid',
        'Partial Paid',
        'Closed',
        'Cancelled',  // Draft abandoned or Rejected
        'Voided'      // Post-Approved errors only
    ];
    
    // Valid transitions: from => [to, to, ...]
    const TRANSITIONS = [
        'Draft' => ['Submitted', 'Cancelled'],
        'Submitted' => ['Approved', 'Cancelled'],  // Reject = Cancelled
        'Approved' => ['Planned', 'Voided'],       // After Approved = Voided only
        'Planned' => ['Dispatched', 'Voided'],
        'Dispatched' => ['In Progress', 'Returned', 'Voided'],
        'In Progress' => ['Returned', 'Voided'],
        'Returned' => ['WH Received'],
        'WH Received' => ['POS Checked'],
        'POS Checked' => ['Accounting Ready'],
        'Accounting Ready' => ['Invoiced'],
        'Invoiced' => ['Paid', 'Partial Paid'],
        'Partial Paid' => ['Paid'],
        'Paid' => ['Closed'],
        'Closed' => [],     // Terminal
        'Cancelled' => [],  // Terminal (pre-Approved)
        'Voided' => []      // Terminal (post-Approved)
    ];
    
    // Actions mapped to transitions
    const ACTIONS = [
        'submit' => ['from' => 'Draft', 'to' => 'Submitted'],
        'approve' => ['from' => 'Submitted', 'to' => 'Approved'],
        'reject' => ['from' => 'Submitted', 'to' => 'Cancelled'],      // Rejected = Cancelled
        'cancel' => ['from' => 'Draft', 'to' => 'Cancelled'],          // Abandon draft
        'plan' => ['from' => 'Approved', 'to' => 'Planned'],
        'dispatch' => ['from' => 'Planned', 'to' => 'Dispatched'],
        'start' => ['from' => 'Dispatched', 'to' => 'In Progress'],
        'return' => ['from' => ['Dispatched', 'In Progress'], 'to' => 'Returned'],
        'wh_receive' => ['from' => 'Returned', 'to' => 'WH Received'],
        'pos_check' => ['from' => 'WH Received', 'to' => 'POS Checked'],
        'acc_ready' => ['from' => 'POS Checked', 'to' => 'Accounting Ready'],
        'invoice' => ['from' => 'Accounting Ready', 'to' => 'Invoiced'],
        'pay' => ['from' => ['Invoiced', 'Partial Paid'], 'to' => 'Paid'],
        'partial_pay' => ['from' => 'Invoiced', 'to' => 'Partial Paid'],
        'close' => ['from' => 'Paid', 'to' => 'Closed'],
        'void' => ['from' => ['Approved', 'Planned', 'Dispatched', 'In Progress'], 'to' => 'Voided']  // Post-Approved only
    ];
    
    // Who can perform actions (role codes)
    const ACTION_ROLES = [
        'submit' => ['ADM', 'SAL', 'PLN'],
        'approve' => ['ADM', 'MGR', 'PLN'],
        'reject' => ['ADM', 'MGR', 'PLN'],
        'cancel' => ['ADM', 'SAL', 'PLN'],  // Owner can cancel own draft
        'plan' => ['ADM', 'PLN'],
        'dispatch' => ['ADM', 'PLN'],
        'start' => ['ADM', 'PLN', 'WH'],
        'return' => ['ADM', 'PLN'],
        'wh_receive' => ['ADM', 'WH'],
        'pos_check' => ['ADM', 'WH'],
        'acc_ready' => ['ADM', 'ACC'],
        'invoice' => ['ADM', 'ACC'],
        'pay' => ['ADM', 'ACC'],
        'partial_pay' => ['ADM', 'ACC'],
        'close' => ['ADM', 'MGR'],
        'void' => ['ADM', 'MGR']  // MGR approval required for void
    ];
    
    // Editable fields per status (lockpoints)
    // After these statuses, certain fields become locked
    const LOCKPOINTS = [
        'Draft' => [
            'locked' => [],
            'editable' => '*'  // All fields
        ],
        'Submitted' => [
            'locked' => [],
            'editable' => ['scope_short', 'scope_detail', 'plan_start_date', 'plan_end_date', 'budget']
        ],
        'Approved' => [
            'locked' => ['job_number', 'customer_id', 'site_id', 'contract_value'],
            'editable' => ['scope_detail', 'owner_planner_id']
        ],
        'Planned' => [
            'locked' => '*',  // All locked, changes via Extension only
            'editable' => []
        ],
        // After Planned, all changes must go through Extension system
    ];
    
    // Statuses that require a reason
    const REASON_REQUIRED = ['Voided', 'Cancelled'];

    /**
     * Map job action to RBAC permission action (if any)
     */
    public static function getPermissionAction(string $action): ?string {
        return match ($action) {
            'submit' => 'create',
            'approve' => 'approve',
            'reject' => 'approve',
            'cancel' => 'cancel',
            'plan' => 'edit',
            'dispatch' => 'dispatch',
            'close' => 'close',
            'void' => 'void',
            default => null
        };
    }
    
    /**
     * Check if transition is valid
     */
    public static function canTransition(string $from, string $to): bool {
        if (!isset(self::TRANSITIONS[$from])) {
            return false;
        }
        return in_array($to, self::TRANSITIONS[$from]);
    }
    
    /**
     * Get available transitions from current status
     */
    public static function getAvailableTransitions(string $currentStatus): array {
        return self::TRANSITIONS[$currentStatus] ?? [];
    }
    
    /**
     * Get available actions based on current status and user roles
     */
    public static function getAvailableActions(string $currentStatus, array $userRoles): array {
        $available = [];
        
        foreach (self::ACTIONS as $action => $config) {
            // Check if action is valid from current status
            $fromStatuses = is_array($config['from']) ? $config['from'] : [$config['from']];
            if (!in_array($currentStatus, $fromStatuses)) {
                continue;
            }
            
            // Check if user has required role
            $allowedRoles = self::ACTION_ROLES[$action] ?? [];
            if (empty(array_intersect($userRoles, $allowedRoles))) {
                continue;
            }
            
            $available[$action] = [
                'to_status' => $config['to'],
                'requires_reason' => in_array($config['to'], self::REASON_REQUIRED) || $action === 'void' || $action === 'reject'
            ];
        }
        
        return $available;
    }
    
    /**
     * Validate transition with reason check
     */
    public static function validateTransition(string $from, string $to, ?string $reason = null): array {
        // Check if valid transition
        if (!self::canTransition($from, $to)) {
            return ['valid' => false, 'error' => "ไม่สามารถเปลี่ยนสถานะจาก $from เป็น $to ได้"];
        }
        
        // Check if reason is required
        if (in_array($to, self::REASON_REQUIRED) && empty($reason)) {
            return ['valid' => false, 'error' => "กรุณาระบุเหตุผลสำหรับการเปลี่ยนสถานะเป็น $to"];
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * Check if field is editable at current status
     */
    public static function isFieldEditable(string $status, string $field): bool {
        // After Planned, nothing is directly editable
        $afterPlanned = ['Planned', 'Dispatched', 'In Progress', 'Returned', 'WH Received', 
                         'POS Checked', 'Accounting Ready', 'Invoiced', 'Paid', 'Partial Paid', 
                         'Closed', 'Cancelled', 'Voided'];
        
        if (in_array($status, $afterPlanned)) {
            return false;
        }
        
        $lockpoint = self::LOCKPOINTS[$status] ?? null;
        if (!$lockpoint) {
            return true;  // Default allow if not defined
        }
        
        // Check if all fields editable
        if ($lockpoint['editable'] === '*') {
            return true;
        }
        
        // Check if field is in locked list
        if ($lockpoint['locked'] === '*') {
            return false;
        }
        
        if (in_array($field, $lockpoint['locked'])) {
            return false;
        }
        
        // Check if field is in editable list
        if (!empty($lockpoint['editable']) && !in_array($field, $lockpoint['editable'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get status badge class for UI
     */
    public static function getStatusBadgeClass(string $status): string {
        return match($status) {
            'Draft' => 'secondary',
            'Submitted' => 'info',
            'Approved' => 'primary',
            'Planned' => 'warning',
            'Dispatched' => 'warning',
            'In Progress' => 'warning',
            'Returned' => 'info',
            'WH Received' => 'info',
            'POS Checked' => 'info',
            'Accounting Ready' => 'primary',
            'Invoiced' => 'primary',
            'Paid', 'Partial Paid' => 'success',
            'Closed' => 'dark',
            'Cancelled' => 'secondary',  // Gray - just cancelled
            'Voided' => 'danger',         // Red - error/serious
            default => 'secondary'
        };
    }
    
    /**
     * Get status label in Thai
     */
    public static function getStatusLabel(string $status): string {
        return match($status) {
            'Draft' => 'แบบร่าง',
            'Submitted' => 'รออนุมัติ',
            'Approved' => 'อนุมัติแล้ว',
            'Planned' => 'วางแผนแล้ว',
            'Dispatched' => 'ส่งของแล้ว',
            'In Progress' => 'กำลังดำเนินการ',
            'Returned' => 'รับคืนแล้ว',
            'WH Received' => 'คลังรับแล้ว',
            'POS Checked' => 'ตรวจสอบแล้ว',
            'Accounting Ready' => 'พร้อมวางบิล',
            'Invoiced' => 'วางบิลแล้ว',
            'Paid' => 'ชำระแล้ว',
            'Partial Paid' => 'ชำระบางส่วน',
            'Closed' => 'ปิดงาน',
            'Cancelled' => 'ยกเลิก',     // Draft/Rejected
            'Voided' => 'ยกเลิก(Void)',  // Post-Approved error
            default => $status
        };
    }
    
    /**
     * Get action label in Thai
     */
    public static function getActionLabel(string $action): string {
        return match($action) {
            'submit' => 'ส่งอนุมัติ',
            'approve' => 'อนุมัติ',
            'reject' => 'ไม่อนุมัติ',
            'plan' => 'วางแผน',
            'dispatch' => 'ออกของ',
            'start' => 'เริ่มงาน',
            'return' => 'รับคืน',
            'wh_receive' => 'คลังรับ',
            'pos_check' => 'ตรวจ POS',
            'acc_ready' => 'พร้อมวางบิล',
            'invoice' => 'วางบิล',
            'pay' => 'ชำระเงิน',
            'partial_pay' => 'ชำระบางส่วน',
            'close' => 'ปิดงาน',
            'cancel' => 'ยกเลิก',
            'void' => 'Void (ยกเลิกหลังอนุมัติ)',
            default => $action
        };
    }
}
