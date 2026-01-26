<?php
/**
 * Bootstrap - Application initialization
 * ERP v2 - Phase 1
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__) . '/logs/error.log');

// Load constants FIRST (before using SESSION_NAME)
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/database.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// Load core classes
require_once dirname(__DIR__) . '/core/Session.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/AuditLog.php';
require_once dirname(__DIR__) . '/core/RBAC.php';
require_once dirname(__DIR__) . '/core/DocumentNumber.php';
require_once dirname(__DIR__) . '/core/Plan.php';
require_once dirname(__DIR__) . '/core/Dispatch.php';
require_once dirname(__DIR__) . '/core/Route.php';
require_once dirname(__DIR__) . '/core/EvidencePhoto.php';

// Phase 1-8 classes
require_once dirname(__DIR__) . '/core/Timesheet.php';
require_once dirname(__DIR__) . '/core/Reservation.php';
require_once dirname(__DIR__) . '/core/Approval.php';
require_once dirname(__DIR__) . '/core/Notification.php';
require_once dirname(__DIR__) . '/core/SiteOperation.php';
require_once dirname(__DIR__) . '/core/Costing.php';
require_once dirname(__DIR__) . '/core/KPI.php';
require_once dirname(__DIR__) . '/core/Compliance.php';

// Load helpers
require_once dirname(__DIR__) . '/includes/functions.php';

// Generate request ID for audit trail
if (!isset($_SESSION['request_id'])) {
    $_SESSION['request_id'] = generateUUID();
}

// Timezone
date_default_timezone_set('Asia/Bangkok');
