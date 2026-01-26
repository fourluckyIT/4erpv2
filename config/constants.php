<?php
/**
 * System Constants
 * 4ERP - Phase 1
 */

// Application Info
define('APP_NAME', '4ERP');
define('APP_VERSION', '2.0.0');

// Session settings
define('SESSION_LIFETIME', 3600 * 8); // 8 hours
define('SESSION_NAME', 'ERP_SESSION');

// Role codes (locked per agents.md)
define('ROLE_ADMIN', 'ADM');
define('ROLE_SALE', 'SAL');
define('ROLE_PLANNER', 'PLN');
define('ROLE_PURCHASE', 'PUR');
define('ROLE_HRM', 'HR');
define('ROLE_WAREHOUSE', 'WH');
define('ROLE_ACCOUNTANT', 'ACC');
define('ROLE_MANAGER', 'MGR');

// Job statuses (locked per agents.md)
define('JOB_STATUS_DRAFT', 'Draft');
define('JOB_STATUS_SUBMITTED', 'Submitted');
define('JOB_STATUS_APPROVED', 'Approved');
define('JOB_STATUS_PLANNED', 'Planned');
define('JOB_STATUS_DISPATCHED', 'Dispatched');
define('JOB_STATUS_IN_PROGRESS', 'In Progress');
define('JOB_STATUS_RETURNED', 'Returned');
define('JOB_STATUS_WH_RECEIVED', 'WH Received');
define('JOB_STATUS_POS_CHECKED', 'POS Checked');
define('JOB_STATUS_ACCOUNTING_READY', 'Accounting Ready');
define('JOB_STATUS_INVOICED', 'Invoiced');
define('JOB_STATUS_PAID', 'Paid');
define('JOB_STATUS_PARTIAL_PAID', 'Partial Paid');
define('JOB_STATUS_CLOSED', 'Closed');
define('JOB_STATUS_VOIDED', 'Voided');

// All job statuses as array
define('JOB_STATUSES', [
    JOB_STATUS_DRAFT,
    JOB_STATUS_SUBMITTED,
    JOB_STATUS_APPROVED,
    JOB_STATUS_PLANNED,
    JOB_STATUS_DISPATCHED,
    JOB_STATUS_IN_PROGRESS,
    JOB_STATUS_RETURNED,
    JOB_STATUS_WH_RECEIVED,
    JOB_STATUS_POS_CHECKED,
    JOB_STATUS_ACCOUNTING_READY,
    JOB_STATUS_INVOICED,
    JOB_STATUS_PAID,
    JOB_STATUS_PARTIAL_PAID,
    JOB_STATUS_CLOSED,
    JOB_STATUS_VOIDED,
]);

// Job types
define('JOB_TYPE_LUMPSUM', 'Lumpsum');
define('JOB_TYPE_DAYRENT', 'Dayrent');
define('JOB_TYPE_MANPOWER', 'Manpower');

define('JOB_TYPES', [
    JOB_TYPE_LUMPSUM,
    JOB_TYPE_DAYRENT,
    JOB_TYPE_MANPOWER,
]);

// Item types
define('ITEM_TYPE_DEVICE', 'Device');
define('ITEM_TYPE_EQUIPMENT', 'Equipment');
define('ITEM_TYPE_VEHICLE', 'Vehicle');
define('ITEM_TYPE_CONSUMABLE', 'Consumable');

define('ITEM_TYPES', [
    ITEM_TYPE_DEVICE,
    ITEM_TYPE_EQUIPMENT,
    ITEM_TYPE_VEHICLE,
    ITEM_TYPE_CONSUMABLE,
]);

// Document types for numbering
define('DOC_TYPES', [
    'JOB', 'PO', 'PR', 'GR', 'DN', 'RN', 'INV', 'PAY'
]);

// Audit actions
define('AUDIT_ACTION_CREATE', 'create');
define('AUDIT_ACTION_UPDATE', 'update');
define('AUDIT_ACTION_DELETE', 'delete');
define('AUDIT_ACTION_APPROVE', 'approve');
define('AUDIT_ACTION_REJECT', 'reject');
define('AUDIT_ACTION_VOID', 'void');
define('AUDIT_ACTION_CANCEL', 'cancel');
define('AUDIT_ACTION_DISPATCH', 'dispatch');
define('AUDIT_ACTION_RECEIVE', 'receive');
define('AUDIT_ACTION_RETURN', 'return');
define('AUDIT_ACTION_CLOSE', 'close');
define('AUDIT_ACTION_LOGIN', 'login');
define('AUDIT_ACTION_LOGOUT', 'logout');
define('AUDIT_ACTION_PRINT', 'print');
define('AUDIT_ACTION_EXPORT', 'export');
define('AUDIT_ACTION_PERMISSION_CHANGE', 'permission_change');
define('AUDIT_ACTION_LINE_BIND', 'line_bind');
define('AUDIT_ACTION_OVERRIDE', 'override');

// Photo evidence requirements
define('REQUIRED_PHOTOS_DISPATCH', 4);
define('REQUIRED_PHOTOS_RECEIVE', 4);
define('REQUIRED_PHOTOS_RETURN', 4);
define('REQUIRED_PHOTOS_POS_CHECK', 4);

// Paths
define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_PATH', BASE_PATH . '/uploads');

// URL - auto-detect based on server config
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8888';

// For CLI, just use empty base
if (php_sapi_name() === 'cli') {
    define('BASE_URL', '');
} else {
    // Detect base URL from script path
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $baseDir = '';
    
    // If running under /4erpv2/ subfolder (Apache/XAMPP)
    if (strpos($scriptName, '/4erpv2/') !== false) {
        $baseDir = '/4erpv2';
    }
    // If running from project root (PHP built-in server)
    // No prefix needed
    
    define('BASE_URL', rtrim($protocol . $host . $baseDir, '/'));
}
