<?php
/**
 * Admin alias for People management
 * Redirects to Master > People
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

redirect(BASE_URL . '/modules/master/people.php');
