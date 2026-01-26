<?php
/**
 * Dashboard Configuration API
 * 4ERP - Admin Dashboard Visibility Control
 * 
 * Endpoints:
 * GET  ?action=widgets           - Get all widgets
 * GET  ?action=config&role=XXX   - Get config for role
 * POST ?action=save              - Save role config
 * POST ?action=toggle            - Toggle single widget
 * POST ?action=size              - Update widget size
 * POST ?action=reset             - Reset to defaults
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

header('Content-Type: application/json');

// Check authentication
$auth = new Auth();
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Only ADM can manage dashboard config
if (!$auth->hasRole('ADM')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden - Admin only']);
    exit;
}

$currentUserId = $auth->getCurrentUserId();

$db = getDB();
$dashConfig = new DashboardConfig($db);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'widgets':
            // Get all widgets grouped by category
            $widgets = $dashConfig->getWidgetsByCategory();
            $categories = DashboardConfig::getCategoryLabels();
            $sizes = DashboardConfig::getSizeLabels();
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'widgets' => $widgets,
                    'categories' => $categories,
                    'sizes' => $sizes
                ]
            ]);
            break;
            
        case 'config':
            // Get config for specific role
            $roleCode = strtoupper($_GET['role'] ?? '');
            if (empty($roleCode)) {
                throw new InvalidArgumentException('Role code required');
            }
            
            $config = $dashConfig->getRoleConfig($roleCode);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'role' => $roleCode,
                    'widgets' => $config
                ]
            ]);
            break;
            
        case 'save':
            // Save full role configuration
            $input = json_decode(file_get_contents('php://input'), true);
            $roleCode = strtoupper($input['role'] ?? '');
            $widgets = $input['widgets'] ?? [];
            
            if (empty($roleCode)) {
                throw new InvalidArgumentException('Role code required');
            }
            
            $dashConfig->saveRoleConfig($roleCode, $widgets, $currentUserId);
            
            echo json_encode([
                'success' => true,
                'message' => "Configuration saved for role: $roleCode"
            ]);
            break;
            
        case 'toggle':
            // Toggle single widget
            $input = json_decode(file_get_contents('php://input'), true);
            $roleCode = strtoupper($input['role'] ?? '');
            $widgetCode = $input['widget'] ?? '';
            $enabled = (bool)($input['enabled'] ?? false);
            
            if (empty($roleCode) || empty($widgetCode)) {
                throw new InvalidArgumentException('Role and widget code required');
            }
            
            $dashConfig->toggleWidget($roleCode, $widgetCode, $enabled, $currentUserId);
            
            echo json_encode([
                'success' => true,
                'message' => "Widget $widgetCode " . ($enabled ? 'enabled' : 'disabled') . " for $roleCode"
            ]);
            break;
            
        case 'size':
            // Update widget size
            $input = json_decode(file_get_contents('php://input'), true);
            $roleCode = strtoupper($input['role'] ?? '');
            $widgetCode = $input['widget'] ?? '';
            $size = strtoupper($input['size'] ?? 'S');
            
            if (empty($roleCode) || empty($widgetCode)) {
                throw new InvalidArgumentException('Role and widget code required');
            }
            
            $dashConfig->updateWidgetSize($roleCode, $widgetCode, $size, $currentUserId);
            
            echo json_encode([
                'success' => true,
                'message' => "Widget $widgetCode size updated to $size for $roleCode"
            ]);
            break;
            
        case 'reset':
            // Reset role to defaults
            $input = json_decode(file_get_contents('php://input'), true);
            $roleCode = strtoupper($input['role'] ?? '');
            
            if (empty($roleCode)) {
                throw new InvalidArgumentException('Role code required');
            }
            
            $dashConfig->resetRoleConfig($roleCode, $currentUserId);
            
            echo json_encode([
                'success' => true,
                'message' => "Configuration reset to defaults for role: $roleCode"
            ]);
            break;
            
        case 'roles':
            // Get all roles
            $stmt = $db->query("SELECT code, name, description FROM roles ORDER BY id");
            $roles = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'data' => $roles
            ]);
            break;
            
        default:
            throw new InvalidArgumentException('Invalid action');
    }
    
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
