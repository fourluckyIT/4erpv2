<?php
/**
 * Route Dispatch - Redirect to Release Page
 * 4ERP - Phase 5
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

// Redirect to route overview
setFlash('info', 'กรุณาใช้หน้า Routes เพื่อปล่อยรถ');
redirect('index.php');
