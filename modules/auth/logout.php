<?php
/**
 * Logout Page
 * ERP v2 - Phase 1
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->logout();

setFlash('success', 'ออกจากระบบเรียบร้อยแล้ว');
redirect('/4erpv2/modules/auth/login.php');
