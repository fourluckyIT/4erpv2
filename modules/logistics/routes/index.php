<?php
/**
 * Routes Dashboard - Redirects to Release page
 * 4ERP - Phase 5 v2
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

// Redirect to Release Route page
header('Location: ' . BASE_URL . '/modules/logistics/dispatch/release.php');
exit;
