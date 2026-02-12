<?php
/**
 * Routes Dashboard - Redirects to Route overview
 * 4ERP - Phase 5 v2
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

// Redirect to Route overview
header('Location: ' . BASE_URL . '/modules/logistics/dispatch/index.php');
exit;
