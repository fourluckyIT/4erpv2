<?php
/**
 * User Profile Page
 * 4ERP
 */

require_once __DIR__ . '/../../config/bootstrap.php';

$auth = new Auth();
$auth->requireAuth();

$currentUser = $auth->getCurrentUser();
$userRoles = $auth->getCurrentRoles();

$pageTitle = 'Profile - 4ERP';
require_once __DIR__ . '/../../includes/modern/layout_start.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="bi bi-person-circle me-2"></i>Profile
        </h2>
        <p class="text-muted">จัดการข้อมูลส่วนตัว</p>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-person me-2"></i>User Information
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th style="width: 40%;">Username</th>
                        <td><?= e($currentUser['username'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>Full Name</th>
                        <td><?= e($currentUser['full_name'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>Email</th>
                        <td><?= e($currentUser['email'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>Roles</th>
                        <td>
                            <?php foreach ($userRoles as $role): ?>
                                <span class="badge bg-primary me-1"><?= e($role) ?></span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td>
                            <?php if ($currentUser['is_active'] ?? true): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-shield-lock me-2"></i>Security
            </div>
            <div class="card-body">
                <p class="text-muted">Change Password feature coming soon.</p>
                <button class="btn btn-outline-secondary" disabled>
                    <i class="bi bi-key me-2"></i>Change Password
                </button>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <i class="bi bi-clock-history me-2"></i>Session Info
            </div>
            <div class="card-body">
                <table class="table table-borderless table-sm">
                    <tr>
                        <th>Session Started</th>
                        <td><?= date('Y-m-d H:i:s', $_SESSION['login_time'] ?? time()) ?></td>
                    </tr>
                    <tr>
                        <th>User ID</th>
                        <td><?= e($_SESSION['user_id'] ?? '-') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/modern/layout_end.php'; ?>
