<?php
/**
 * Logout Page
 * 4ERP - Phase 1
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->logout();

setFlash('success', 'ออกจากระบบเรียบร้อยแล้ว');
redirect('/4erpv2/modules/auth/login.php');
