<?php
/**
 * Dashboard Configuration Manager
 * 4ERP - Dashboard Widget Visibility Control
 * 
 * Manages per-role dashboard widget configurations
 * Admin can control which widgets are visible for each role
 */

class DashboardConfig
{
    private PDO $db;
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    
    /**
     * Get all available widgets
     */
    public function getAllWidgets(): array
    {
        $stmt = $this->db->query("
            SELECT * FROM dashboard_widgets 
            WHERE is_active = 1 
            ORDER BY category, sort_order
        ");
        return $stmt->fetchAll();
    }
    
    /**
     * Get widgets grouped by category
     */
    public function getWidgetsByCategory(): array
    {
        $widgets = $this->getAllWidgets();
        $grouped = [];
        
        foreach ($widgets as $widget) {
            $category = $widget['category'];
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $widget;
        }
        
        return $grouped;
    }
    
    /**
     * Get widget configuration for a specific role
     */
    public function getRoleConfig(string $roleCode): array
    {
        $rolePermissions = $this->getRolePermissionCodes($roleCode);
        $rolePermLookup = array_fill_keys($rolePermissions, true);

        $stmt = $this->db->prepare("
            SELECT 
                w.*,
                COALESCE(rc.is_enabled, w.default_enabled) as is_enabled,
                COALESCE(rc.size, w.default_size) as size,
                COALESCE(rc.position, w.sort_order) as position,
                rc.custom_settings
            FROM dashboard_widgets w
            LEFT JOIN dashboard_role_config rc 
                ON w.code = rc.widget_code AND rc.role_code = ?
            WHERE w.is_active = 1
            AND (
                w.allowed_roles IS NULL 
                OR JSON_CONTAINS(w.allowed_roles, JSON_QUOTE(?))
            )
            ORDER BY COALESCE(rc.position, w.sort_order)
        ");
        $stmt->execute([$roleCode, $roleCode]);
        $widgets = $stmt->fetchAll();

        // Filter by required permissions (if defined)
        $filtered = [];
        foreach ($widgets as $widget) {
            $required = $widget['required_permissions'] ?? null;
            if (!$required) {
                $filtered[] = $widget;
                continue;
            }

            $reqList = json_decode((string) $required, true);
            if (!is_array($reqList) || empty($reqList)) {
                $filtered[] = $widget;
                continue;
            }

            $hasAny = false;
            foreach ($reqList as $permCode) {
                if (isset($rolePermLookup[$permCode])) {
                    $hasAny = true;
                    break;
                }
            }

            if ($hasAny) {
                $filtered[] = $widget;
            }
        }

        return $filtered;
    }
    
    /**
     * Get enabled widgets for a role (for rendering dashboard)
     */
    public function getEnabledWidgets(string $roleCode): array
    {
        $config = $this->getRoleConfig($roleCode);
        return array_filter($config, fn($w) => $w['is_enabled']);
    }
    
    /**
     * Save widget configuration for a role
     */
    public function saveRoleConfig(string $roleCode, array $widgetConfigs, int $updatedBy): bool
    {
        $this->db->beginTransaction();
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO dashboard_role_config 
                    (role_code, widget_code, is_enabled, size, position, updated_by)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    is_enabled = VALUES(is_enabled),
                    size = VALUES(size),
                    position = VALUES(position),
                    updated_by = VALUES(updated_by)
            ");
            
            foreach ($widgetConfigs as $index => $config) {
                $stmt->execute([
                    $roleCode,
                    $config['widget_code'],
                    $config['is_enabled'] ? 1 : 0,
                    $config['size'] ?? 'S',
                    $config['position'] ?? $index,
                    $updatedBy
                ]);
            }
            
            $this->db->commit();
            
            // Log the action
            if (class_exists('AuditLog')) {
                $audit = new AuditLog();
                $audit->log(
                    'update',
                    'DASHBOARD_CONFIG',
                    null,
                    null,
                    ['role' => $roleCode, 'widgets_count' => count($widgetConfigs)]
                );
            }
            
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Toggle a single widget for a role
     */
    public function toggleWidget(string $roleCode, string $widgetCode, bool $enabled, int $updatedBy): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO dashboard_role_config 
                (role_code, widget_code, is_enabled, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                updated_by = VALUES(updated_by)
        ");
        
        return $stmt->execute([$roleCode, $widgetCode, $enabled ? 1 : 0, $updatedBy]);
    }
    
    /**
     * Update widget size for a role
     */
    public function updateWidgetSize(string $roleCode, string $widgetCode, string $size, int $updatedBy): bool
    {
        if (!in_array($size, ['S', 'M', 'L'])) {
            throw new InvalidArgumentException("Invalid size: $size");
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO dashboard_role_config 
                (role_code, widget_code, size, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                size = VALUES(size),
                updated_by = VALUES(updated_by)
        ");
        
        return $stmt->execute([$roleCode, $widgetCode, $size, $updatedBy]);
    }
    
    /**
     * Reset role config to defaults
     */
    public function resetRoleConfig(string $roleCode, int $updatedBy): bool
    {
        $stmt = $this->db->prepare("DELETE FROM dashboard_role_config WHERE role_code = ?");
        $result = $stmt->execute([$roleCode]);
        
        if ($result && class_exists('AuditLog')) {
            $audit = new AuditLog();
            $audit->log(
                'update',
                'DASHBOARD_CONFIG',
                null,
                null,
                ['role' => $roleCode, 'action' => 'reset_to_default']
            );
        }
        
        return $result;
    }
    
    /**
     * Get category labels
     */
    public static function getCategoryLabels(): array
    {
        return [
            'stats' => ['label' => 'Statistics Widgets', 'icon' => 'bi-bar-chart'],
            'table' => ['label' => 'Table Widgets', 'icon' => 'bi-table'],
            'chart' => ['label' => 'Chart Widgets', 'icon' => 'bi-pie-chart'],
            'action' => ['label' => 'Quick Actions', 'icon' => 'bi-lightning'],
            'timeline' => ['label' => 'Timeline Widgets', 'icon' => 'bi-clock-history']
        ];
    }
    
    /**
     * Get size labels
     */
    public static function getSizeLabels(): array
    {
        return [
            'S' => ['label' => 'Small', 'cols' => 4],
            'M' => ['label' => 'Medium', 'cols' => 6],
            'L' => ['label' => 'Large', 'cols' => 12]
        ];
    }

    /**
     * Get permission codes assigned to a role
     */
    private function getRolePermissionCodes(string $roleCode): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT p.code
            FROM role_permissions rp
            JOIN roles r ON rp.role_id = r.id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE r.code = ?
            AND rp.is_granted = 1
        ");
        $stmt->execute([$roleCode]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
