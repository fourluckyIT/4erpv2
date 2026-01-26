<?php
/**
 * Header Template
 * 4ERP - Bootstrap 5
 */
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? '4ERP') ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    
    <?php if (!empty($extraCss)): ?>
        <?php foreach ($extraCss as $css): ?>
            <link href="<?= e($css) ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body class="d-flex flex-column min-vh-100">
<?php
// Include navigation if user is logged in
if (!empty($_SESSION['logged_in'])) {
    require_once __DIR__ . '/nav.php';
}
?>

<main class="flex-grow-1">
    <div class="container-fluid py-3">
        <?php
        // Display flash messages
        $flash = getFlash();
        if ($flash): 
        ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : e($flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= e($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
