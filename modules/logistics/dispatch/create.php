<?php
/**
 * Route Dispatch - Redirect to Release Page
 * 4ERP - Phase 5
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

// Redirect to new release page
setFlash('info', 'กรุณาใช้หน้า "ปล่อย Route" สำหรับจัดการการปล่อย Route แบบใหม่');
redirect('release.php');
