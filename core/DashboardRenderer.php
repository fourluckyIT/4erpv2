<?php
/**
 * Dashboard Widget Renderer
 * 4ERP - Renders dashboard widgets based on role configuration
 */

class DashboardRenderer
{
    private PDO $db;
    private DashboardConfig $config;
    private string $roleCode;
    
    public function __construct(PDO $db, string $roleCode)
    {
        $this->db = $db;
        $this->config = new DashboardConfig($db);
        $this->roleCode = $roleCode;
    }
    
    /**
     * Render all enabled widgets for the current role
     */
    public function render(): string
    {
        $widgets = $this->config->getEnabledWidgets($this->roleCode);
        
        if (empty($widgets)) {
            return '<div class="alert alert-info">No widgets configured for this role.</div>';
        }
        
        $html = '<div class="widgets-grid">';
        
        foreach ($widgets as $widget) {
            $html .= $this->renderWidget($widget);
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render a single widget
     */
    private function renderWidget(array $widget): string
    {
        $colClass = $this->getSizeClass($widget['size']);
        $content = $this->getWidgetContent($widget);
        
        return "
            <div class=\"widget {$colClass}\" data-widget=\"{$widget['code']}\">
                <div class=\"card-header\">
                    <h3 class=\"card-title\">
                        <i class=\"{$widget['icon']}\"></i> {$widget['name']}
                    </h3>
                </div>
                <div class=\"card-body\">
                    {$content}
                </div>
            </div>
        ";
    }
    
    /**
     * Get widget content based on type
     */
    private function getWidgetContent(array $widget): string
    {
        $code = $widget['code'];
        $category = $widget['category'];
        
        switch ($category) {
            case 'stats':
                return $this->renderStatWidget($code);
            case 'table':
                return $this->renderTableWidget($code);
            case 'chart':
                return $this->renderChartWidget($code);
            case 'action':
                return $this->renderActionWidget($code);
            case 'timeline':
                return $this->renderTimelineWidget($code);
            default:
                return '<p>Widget content</p>';
        }
    }
    
    /**
     * Render stat widget
     */
    private function renderStatWidget(string $code): string
    {
        $data = $this->getStatData($code);
        
        return "
            <div class=\"stat-display\">
                <div class=\"stat-value\">{$data['value']}</div>
                <div class=\"stat-label\">{$data['label']}</div>
            </div>
        ";
    }
    
    /**
     * Get stat data based on widget code
     */
    private function getStatData(string $code): array
    {
        switch ($code) {
            case 'stat_total_jobs':
                $count = $this->db->query("SELECT COUNT(*) FROM jobs")->fetchColumn();
                return ['value' => $count, 'label' => 'Total Jobs'];
                
            case 'stat_revenue':
                $sum = $this->db->query("SELECT COALESCE(SUM(total_amount), 0) FROM ar_invoices WHERE status = 'Paid' AND MONTH(created_at) = MONTH(CURRENT_DATE)")->fetchColumn();
                return ['value' => '฿ ' . number_format($sum), 'label' => 'Revenue MTD'];
                
            case 'stat_pending_approvals':
                $count = $this->db->query("SELECT COUNT(*) FROM jobs WHERE status = 'Submitted'")->fetchColumn();
                return ['value' => $count, 'label' => 'Pending Approvals'];
                
            case 'stat_active_users':
                $count = $this->db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
                return ['value' => $count, 'label' => 'Active Users'];
                
            case 'stat_jobs_awaiting_plan':
                $count = $this->db->query("SELECT COUNT(*) FROM jobs WHERE status = 'Approved'")->fetchColumn();
                return ['value' => $count, 'label' => 'Awaiting Plan'];
                
            case 'stat_dispatches_today':
                $count = $this->db->query("SELECT COUNT(*) FROM routes WHERE DATE(dispatch_date) = CURRENT_DATE")->fetchColumn();
                return ['value' => $count, 'label' => 'Dispatches Today'];
                
            case 'stat_stock_items':
                $count = $this->db->query("SELECT COUNT(*) FROM items WHERE is_active = 1")->fetchColumn();
                return ['value' => $count, 'label' => 'Stock Items'];
                
            case 'stat_low_stock':
                $count = $this->db->query("SELECT COUNT(*) FROM item_stock_levels WHERE qty_available < min_qty")->fetchColumn();
                return ['value' => $count, 'label' => 'Low Stock'];
                
            case 'stat_outstanding_ar':
                $sum = $this->db->query("SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM ar_invoices WHERE status NOT IN ('Paid', 'Voided')")->fetchColumn();
                return ['value' => '฿ ' . number_format($sum), 'label' => 'Outstanding AR'];
                
            case 'stat_total_people':
                $count = $this->db->query("SELECT COUNT(*) FROM people WHERE is_active = 1")->fetchColumn();
                return ['value' => $count, 'label' => 'Total People'];
                
            case 'stat_pr_pending':
                $count = $this->db->query("SELECT COUNT(*) FROM purchase_requests WHERE status = 'Approved'")->fetchColumn();
                return ['value' => $count, 'label' => 'PRs Ready'];
                
            default:
                return ['value' => '0', 'label' => 'N/A'];
        }
    }
    
    /**
     * Render table widget
     */
    private function renderTableWidget(string $code): string
    {
        return '<div class="table-placeholder">Table widget: ' . $code . '</div>';
    }
    
    /**
     * Render chart widget
     */
    private function renderChartWidget(string $code): string
    {
        return '<div class="chart-placeholder" style="height: 200px; display: flex; align-items: center; justify-content: center; background: var(--gray-50); border-radius: 8px;"><i class="bi bi-bar-chart" style="font-size: 2rem; color: var(--gray-400);"></i></div>';
    }
    
    /**
     * Render action widget (quick actions)
     */
    private function renderActionWidget(string $code): string
    {
        $actions = $this->getQuickActions();
        
        $html = '<div class="quick-actions">';
        foreach ($actions as $action) {
            $html .= "
                <a href=\"{$action['url']}\" class=\"quick-action\">
                    <div class=\"quick-action-icon\"><i class=\"{$action['icon']}\"></i></div>
                    <span class=\"quick-action-label\">{$action['label']}</span>
                </a>
            ";
        }
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get quick actions based on role
     */
    private function getQuickActions(): array
    {
        $actions = [
            ['url' => BASE_URL . '/modules/jobs/create.php', 'icon' => 'bi-plus-circle', 'label' => 'New Job'],
        ];
        
        if (in_array($this->roleCode, ['ADM', 'PUR'])) {
            $actions[] = ['url' => BASE_URL . '/modules/procurement/pr/create.php', 'icon' => 'bi-file-text', 'label' => 'Create PR'];
            $actions[] = ['url' => BASE_URL . '/modules/procurement/po/create.php', 'icon' => 'bi-cart-plus', 'label' => 'Create PO'];
        }
        
        if (in_array($this->roleCode, ['ADM', 'ACC'])) {
            $actions[] = ['url' => BASE_URL . '/modules/accounting/invoices/create.php', 'icon' => 'bi-receipt', 'label' => 'Invoice'];
        }
        
        return $actions;
    }
    
    /**
     * Render timeline widget
     */
    private function renderTimelineWidget(string $code): string
    {
        $logs = $this->getRecentActivity();
        
        $html = '<div class="activity-timeline">';
        foreach ($logs as $log) {
            $html .= "
                <div class=\"activity-item\">
                    <div class=\"activity-time\">{$log['time']}</div>
                    <div class=\"activity-content\">{$log['content']}</div>
                </div>
            ";
        }
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get recent activity
     */
    private function getRecentActivity(): array
    {
        $sql = "
            SELECT a.*, u.full_name
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.id
        ";
        $params = [];
        if ($this->roleCode !== 'ADM') {
            $sql .= " WHERE a.action_name NOT IN ('" . AUDIT_ACTION_LOGIN . "','" . AUDIT_ACTION_LOGOUT . "') ";
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            if ($userId > 0) {
                $sql .= " AND a.user_id = :user_id ";
                $params['user_id'] = $userId;
            }
        }
        $sql .= " ORDER BY a.created_at DESC LIMIT 5 ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $logs = [];
        while ($row = $stmt->fetch()) {
            $action = $row['action_name'] ?? ($row['action'] ?? '');
            $name = $row['full_name'] ?? 'System';
            $logs[] = [
                'time' => $this->formatTime($row['created_at']),
                'content' => "<strong>{$name}</strong> {$action} {$row['entity_type']}"
            ];
        }
        
        return $logs ?: [['time' => 'Now', 'content' => 'No recent activity']];
    }
    
    /**
     * Format time as relative
     */
    private function formatTime(string $datetime): string
    {
        $time = strtotime($datetime);
        $diff = time() - $time;
        
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
        return date('d M', $time);
    }
    
    /**
     * Get CSS class for widget size
     */
    private function getSizeClass(string $size): string
    {
        return match ($size) {
            'S' => 'col-4',
            'M' => 'col-6',
            'L' => 'col-12',
            default => 'col-4'
        };
    }
}
