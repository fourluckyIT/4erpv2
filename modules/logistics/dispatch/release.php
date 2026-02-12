<?php
/**
 * Release Routes - Deprecated (redirect to Route overview)
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

setFlash('info', 'หน้า "ปล่อย Route" ถูกรวมไว้ในหน้า Routes แล้ว');
redirect(BASE_URL . '/modules/logistics/dispatch/index.php');
