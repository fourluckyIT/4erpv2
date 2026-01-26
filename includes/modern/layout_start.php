<?php
/**
 * Modern Layout Start
 * 4ERP - Unified Layout System
 * 
 * Usage: Include this at the start of any page after bootstrap.php
 * Required variables:
 *   $pageTitle - Page title for browser tab
 *   $currentPage - Current page identifier for sidebar highlighting
 *   $breadcrumbs - Array of breadcrumb items (optional)
 */

// Ensure auth is initialized
if (!isset($auth)) {
    $auth = new Auth();
    $auth->requireAuth();
}
if (!isset($rbac)) {
    $rbac = new RBAC();
}

$currentUser = $auth->getCurrentUser();
$userRoles = $auth->getCurrentRoles();
$primaryRole = $userRoles[0] ?? 'SAL';

// Default page title
$pageTitle = $pageTitle ?? '4ERP';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - 4ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/modern-ui.css" rel="stylesheet">
    <?php if (!empty($extraCss)): ?>
        <?php foreach ($extraCss as $css): ?>
        <link href="<?= e($css) ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
    <style>
        .flash-message {
            position: fixed;
            top: 80px;
            right: 24px;
            z-index: 1000;
            min-width: 300px;
            padding: 16px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            animation: slideIn 0.3s ease;
        }
        .flash-message.success { background: var(--success-light); color: var(--success); border-left: 4px solid var(--success); }
        .flash-message.error { background: var(--danger-light); color: var(--danger); border-left: 4px solid var(--danger); }
        .flash-message.warning { background: var(--warning-light); color: var(--warning); border-left: 4px solid var(--warning); }
        .flash-message.info { background: var(--info-light); color: var(--info); border-left: 4px solid var(--info); }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php require_once __DIR__ . '/sidebar.php'; ?>

        <main class="main-content">
            <?php require_once __DIR__ . '/header.php'; ?>

            <?php
            // Display flash messages
            $flash = getFlash();
            if ($flash): 
            ?>
            <div class="flash-message <?= $flash['type'] === 'error' ? 'error' : e($flash['type']) ?>" id="flashMessage">
                <?= e($flash['message']) ?>
            </div>
            <?php endif; ?>

            <div class="page-content">
