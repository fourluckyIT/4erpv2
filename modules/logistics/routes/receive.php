<?php
/**
 * Receive Route - Deprecated (use Route view)
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$routeId = (int) get('id', 0);
if ($routeId) {
    setFlash('info', 'กรุณารับของจากหน้า Route');
    redirect(BASE_URL . '/modules/logistics/routes/view.php?id=' . $routeId);
}

setFlash('error', 'ไม่พบ Route');
redirect(BASE_URL . '/modules/logistics/dispatch/index.php');
