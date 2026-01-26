<?php
/**
 * Login Page
 * ERP v2 - Phase 1
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();

// Redirect if already logged in
if ($auth->isAuthenticated()) {
    redirect('/4erpv2/index.php');
}

$error = '';

// Handle login form submission
if (isPost()) {
    // Verify CSRF
    if (!verifyCsrf(post('csrf_token', ''))) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = sanitize(post('username', ''));
        $password = post('password', '');
        
        if (empty($username) || empty($password)) {
            $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
        } else {
            $result = $auth->login($username, $password);
            
            if ($result['success']) {
                redirect('/4erpv2/index.php');
            } else {
                $error = $result['error'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ERP v2</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/4erpv2/assets/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="text-center">
                <i class="bi bi-box-seam login-logo"></i>
                <h3 class="login-title">ERP v2</h3>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-circle me-2"></i><?= e($error) ?>
                </div>
            <?php endif; ?>
            
            <?php 
            $flash = getFlash();
            if ($flash): 
            ?>
                <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : e($flash['type']) ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                
                <div class="mb-3">
                    <label for="username" class="form-label">
                        <i class="bi bi-person me-1"></i>Username or Email
                    </label>
                    <input 
                        type="text" 
                        class="form-control form-control-lg" 
                        id="username" 
                        name="username" 
                        value="<?= e(post('username', '')) ?>"
                        placeholder="Enter username or email"
                        autofocus
                        required
                    >
                </div>
                
                <div class="mb-4">
                    <label for="password" class="form-label">
                        <i class="bi bi-lock me-1"></i>Password
                    </label>
                    <input 
                        type="password" 
                        class="form-control form-control-lg" 
                        id="password" 
                        name="password" 
                        placeholder="Enter password"
                        required
                    >
                </div>
                
                <button type="submit" class="btn btn-primary btn-lg w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Login
                </button>
            </form>
            
            <div class="text-center mt-4 text-muted small">
                <p>Default: admin / admin123</p>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
