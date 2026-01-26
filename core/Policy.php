<?php
/**
 * Policy - Centralized RBAC Action Constants and Permission Matrix
 * 4ERP - Phase M6 RBAC Hardening
 * 
 * Single Source of Truth for all action permissions following agents.md §4.
 * 
 * Usage:
 *   $policy = new Policy();
 *   if (!$policy->can('JOB_APPROVE', $jobStatus)) {
 *       throw new Exception('Permission denied');
 *   }
 */

require_once __DIR__ . '/RBAC.php';

class Policy {
    
    // ===== ROLE CODES (agents.md §4) =====
    const ROLE_ADM = 'ADM'; // Admin
    const ROLE_SAL = 'SAL'; // Sales
    const ROLE_PLN = 'PLN'; // Planner
    const ROLE_PUR = 'PUR'; // Procurement
    const ROLE_HR  = 'HR';  // HR
    const ROLE_WH  = 'WH';  // Warehouse
    const ROLE_ACC = 'ACC'; // Accounting
    const ROLE_MGR = 'MGR'; // Manager
    
    // ===== ACTION CONSTANTS =====
    // Job Actions (§4.1)
    const JOB_VIEW = 'JOB_VIEW';
    const JOB_CREATE = 'JOB_CREATE';
    const JOB_EDIT = 'JOB_EDIT';
    const JOB_SUBMIT = 'JOB_SUBMIT';
    const JOB_APPROVE = 'JOB_APPROVE';
    const JOB_PLAN = 'JOB_PLAN';
    const JOB_DISPATCH = 'JOB_DISPATCH';
    const JOB_EXTEND = 'JOB_EXTEND';
    const JOB_VOID = 'JOB_VOID';
    const JOB_CLOSE = 'JOB_CLOSE';
    
    // PR/PO Actions (§4.2-4.3)
    const PR_CREATE = 'PR_CREATE';
    const PR_VIEW = 'PR_VIEW';
    const PR_APPROVE = 'PR_APPROVE';
    const PO_CREATE = 'PO_CREATE';
    const PO_VIEW = 'PO_VIEW';
    const PO_APPROVE = 'PO_APPROVE';
    const GR_CREATE = 'GR_CREATE';
    const GR_REGISTER_MANPOWER = 'GR_REGISTER_MANPOWER';
    
    // Planning/Route Actions (§4.4-4.5)
    const PLAN_CREATE = 'PLAN_CREATE';
    const PLAN_EDIT = 'PLAN_EDIT';
    const PLAN_CONFIRM = 'PLAN_CONFIRM';
    const PLAN_OVERRIDE_CERT = 'PLAN_OVERRIDE_CERT';
    const ROUTE_CREATE = 'ROUTE_CREATE';
    const ROUTE_EDIT = 'ROUTE_EDIT';
    const ROUTE_CONFIRM = 'ROUTE_CONFIRM';
    const ROUTE_DISPATCH = 'ROUTE_DISPATCH';
    const ROUTE_VOID = 'ROUTE_VOID';
    const ROUTE_ADD_PHOTO = 'ROUTE_ADD_PHOTO';
    
    // Warehouse Actions (§4.6)
    const WH_RECEIVE_RETURN = 'WH_RECEIVE_RETURN';
    const WH_RECEIVE_GR = 'WH_RECEIVE_GR';
    const STOCK_ADJUST = 'STOCK_ADJUST';
    const STOCK_ADJUST_APPROVE = 'STOCK_ADJUST_APPROVE';
    const STOCK_MOVE_RECORD = 'STOCK_MOVE_RECORD';
    const STOCK_MOVE_REVERSE = 'STOCK_MOVE_REVERSE';
    
    // Accounting Actions (§4.8)
    const INVOICE_CREATE = 'INVOICE_CREATE';
    const INVOICE_VIEW = 'INVOICE_VIEW';
    const INVOICE_ISSUE = 'INVOICE_ISSUE';
    const INVOICE_VOID = 'INVOICE_VOID';
    const INVOICE_CREDIT = 'INVOICE_CREDIT';
    const PAYMENT_RECORD = 'PAYMENT_RECORD';
    const PAYMENT_REVERSE = 'PAYMENT_REVERSE';
    const ACCOUNTING_READY = 'ACCOUNTING_READY';
    const POS_CHECK = 'POS_CHECK';
    
    // ===== PERMISSION MATRIX =====
    // Maps action => [allowed_roles]
    // ADM can do all, so not always listed
    private static array $matrix = [
        // Job Actions
        self::JOB_VIEW => [self::ROLE_ADM, self::ROLE_SAL, self::ROLE_PLN, self::ROLE_MGR, self::ROLE_ACC, self::ROLE_WH],
        self::JOB_CREATE => [self::ROLE_ADM, self::ROLE_SAL, self::ROLE_PLN],
        self::JOB_EDIT => [self::ROLE_ADM, self::ROLE_SAL, self::ROLE_PLN],
        self::JOB_SUBMIT => [self::ROLE_ADM, self::ROLE_SAL, self::ROLE_PLN],
        self::JOB_APPROVE => [self::ROLE_ADM, self::ROLE_MGR, self::ROLE_PLN],
        self::JOB_PLAN => [self::ROLE_ADM, self::ROLE_PLN],
        self::JOB_DISPATCH => [self::ROLE_ADM, self::ROLE_PLN],
        self::JOB_EXTEND => [self::ROLE_ADM, self::ROLE_PLN],
        self::JOB_VOID => [self::ROLE_ADM, self::ROLE_MGR],
        self::JOB_CLOSE => [self::ROLE_ADM, self::ROLE_MGR],
        
        // PR/PO Actions
        self::PR_CREATE => [self::ROLE_ADM, self::ROLE_SAL, self::ROLE_PLN, self::ROLE_PUR, self::ROLE_WH, self::ROLE_ACC],
        self::PR_VIEW => [self::ROLE_ADM, self::ROLE_PUR, self::ROLE_MGR],
        self::PR_APPROVE => [self::ROLE_ADM, self::ROLE_PUR, self::ROLE_MGR],
        self::PO_CREATE => [self::ROLE_ADM, self::ROLE_PUR],
        self::PO_VIEW => [self::ROLE_ADM, self::ROLE_PUR, self::ROLE_MGR, self::ROLE_ACC],
        self::PO_APPROVE => [self::ROLE_ADM, self::ROLE_MGR],
        self::GR_CREATE => [self::ROLE_ADM, self::ROLE_WH],
        self::GR_REGISTER_MANPOWER => [self::ROLE_ADM, self::ROLE_HR],
        
        // Planning/Route Actions
        self::PLAN_CREATE => [self::ROLE_ADM, self::ROLE_PLN],
        self::PLAN_EDIT => [self::ROLE_ADM, self::ROLE_PLN],
        self::PLAN_CONFIRM => [self::ROLE_ADM, self::ROLE_PLN],
        self::PLAN_OVERRIDE_CERT => [self::ROLE_ADM, self::ROLE_MGR],
        self::ROUTE_CREATE => [self::ROLE_ADM, self::ROLE_PLN],
        self::ROUTE_EDIT => [self::ROLE_ADM, self::ROLE_PLN],
        self::ROUTE_CONFIRM => [self::ROLE_ADM, self::ROLE_PLN, self::ROLE_WH],
        self::ROUTE_DISPATCH => [self::ROLE_ADM, self::ROLE_PLN, self::ROLE_WH],
        self::ROUTE_VOID => [self::ROLE_ADM, self::ROLE_MGR],
        self::ROUTE_ADD_PHOTO => [self::ROLE_ADM, self::ROLE_PLN, self::ROLE_WH],
        
        // Warehouse Actions
        self::WH_RECEIVE_RETURN => [self::ROLE_ADM, self::ROLE_WH],
        self::WH_RECEIVE_GR => [self::ROLE_ADM, self::ROLE_WH],
        self::STOCK_ADJUST => [self::ROLE_ADM, self::ROLE_WH],
        self::STOCK_ADJUST_APPROVE => [self::ROLE_ADM, self::ROLE_MGR],
        self::STOCK_MOVE_RECORD => [self::ROLE_ADM, self::ROLE_WH],
        self::STOCK_MOVE_REVERSE => [self::ROLE_ADM, self::ROLE_WH, self::ROLE_MGR],
        
        // Accounting Actions
        self::INVOICE_CREATE => [self::ROLE_ADM, self::ROLE_ACC],
        self::INVOICE_VIEW => [self::ROLE_ADM, self::ROLE_ACC, self::ROLE_MGR],
        self::INVOICE_ISSUE => [self::ROLE_ADM, self::ROLE_ACC],
        self::INVOICE_VOID => [self::ROLE_ADM, self::ROLE_ACC, self::ROLE_MGR],
        self::INVOICE_CREDIT => [self::ROLE_ADM, self::ROLE_ACC],
        self::PAYMENT_RECORD => [self::ROLE_ADM, self::ROLE_ACC],
        self::PAYMENT_REVERSE => [self::ROLE_ADM, self::ROLE_ACC, self::ROLE_MGR],
        self::ACCOUNTING_READY => [self::ROLE_ADM, self::ROLE_ACC],
        self::POS_CHECK => [self::ROLE_ADM, self::ROLE_WH],
    ];
    
    private RBAC $rbac;
    private array $userRoles;
    
    public function __construct(?int $userId = null) {
        $this->rbac = new RBAC($userId);
        $this->userRoles = $this->rbac->getUserRoleCodes();
    }
    
    /**
     * Check if current user can perform action
     * 
     * @param string $action Action constant (e.g., Policy::INVOICE_CREATE)
     * @param string|null $entityStatus Entity status for status-based checks
     * @return bool
     */
    public function can(string $action, ?string $entityStatus = null): bool {
        // ADM can always do everything
        if (in_array(self::ROLE_ADM, $this->userRoles)) {
            return true;
        }
        
        // Check matrix
        if (!isset(self::$matrix[$action])) {
            // Unknown action - deny by default
            return false;
        }
        
        $allowedRoles = self::$matrix[$action];
        
        foreach ($this->userRoles as $role) {
            if (in_array($role, $allowedRoles)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Require permission - throws exception if denied
     * 
     * @throws Exception
     */
    public function require(string $action, ?string $entityStatus = null): void {
        if (!$this->can($action, $entityStatus)) {
            $roles = implode(', ', $this->userRoles);
            throw new Exception("Permission denied: Action '$action' not allowed for roles [$roles]");
        }
    }
    
    /**
     * Check permission with explicit role (for testing)
     * 
     * @param string $roleCode Single role code to check
     * @param string $action Action constant
     * @return bool
     */
    public static function roleCanDo(string $roleCode, string $action): bool {
        if ($roleCode === self::ROLE_ADM) {
            return true;
        }
        
        if (!isset(self::$matrix[$action])) {
            return false;
        }
        
        return in_array($roleCode, self::$matrix[$action]);
    }
    
    /**
     * Get allowed roles for an action
     */
    public static function getAllowedRoles(string $action): array {
        return self::$matrix[$action] ?? [];
    }
    
    /**
     * Get current user's roles
     */
    public function getUserRoles(): array {
        return $this->userRoles;
    }
    
    /**
     * Set user roles (for testing purposes)
     */
    public function setUserRoles(array $roles): void {
        $this->userRoles = $roles;
    }
    
    /**
     * Get all defined actions
     */
    public static function getAllActions(): array {
        return array_keys(self::$matrix);
    }
}
