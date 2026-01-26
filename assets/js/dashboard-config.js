/**
 * Dashboard Configuration Engine
 * ERP v2 - Admin Dashboard Visibility Control
 * 
 * Manages widget visibility, sizes, and positions per role
 */

class DashboardConfigEngine {
    constructor(options = {}) {
        this.apiBase = options.apiBase || '/4erpv2/modules/admin/dashboard-config/api.php';
        this.currentRole = null;
        this.widgets = {};
        this.categories = {};
        this.sizes = {};
        this.roleConfig = [];
        this.unsavedChanges = false;
        
        this.init();
    }
    
    async init() {
        await this.loadWidgets();
        this.bindEvents();
    }
    
    /**
     * Load all available widgets
     */
    async loadWidgets() {
        try {
            const response = await fetch(`${this.apiBase}?action=widgets`);
            const result = await response.json();
            
            if (result.success) {
                this.widgets = result.data.widgets;
                this.categories = result.data.categories;
                this.sizes = result.data.sizes;
            } else {
                console.error('Failed to load widgets:', result.error);
            }
        } catch (error) {
            console.error('Error loading widgets:', error);
        }
    }
    
    /**
     * Load configuration for a role
     */
    async loadRoleConfig(roleCode) {
        try {
            const response = await fetch(`${this.apiBase}?action=config&role=${roleCode}`);
            const result = await response.json();
            
            if (result.success) {
                this.currentRole = roleCode;
                this.roleConfig = result.data.widgets;
                this.unsavedChanges = false;
                this.renderWidgetConfig();
                this.updatePreview();
            } else {
                console.error('Failed to load role config:', result.error);
            }
        } catch (error) {
            console.error('Error loading role config:', error);
        }
    }
    
    /**
     * Save current configuration
     */
    async saveConfig() {
        if (!this.currentRole) return;
        
        const widgets = this.roleConfig.map((w, index) => ({
            widget_code: w.code,
            is_enabled: w.is_enabled ? 1 : 0,
            size: w.size || 'S',
            position: index
        }));
        
        try {
            const response = await fetch(`${this.apiBase}?action=save`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    role: this.currentRole,
                    widgets: widgets
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.unsavedChanges = false;
                this.showNotification('success', 'Configuration saved successfully!');
            } else {
                this.showNotification('error', result.error || 'Failed to save');
            }
        } catch (error) {
            console.error('Error saving config:', error);
            this.showNotification('error', 'Error saving configuration');
        }
    }
    
    /**
     * Toggle widget enabled/disabled
     */
    async toggleWidget(widgetCode, enabled) {
        // Update local state
        const widget = this.roleConfig.find(w => w.code === widgetCode);
        if (widget) {
            widget.is_enabled = enabled ? 1 : 0;
            this.unsavedChanges = true;
            this.updatePreview();
        }
        
        // Also update immediately via API
        try {
            await fetch(`${this.apiBase}?action=toggle`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    role: this.currentRole,
                    widget: widgetCode,
                    enabled: enabled
                })
            });
        } catch (error) {
            console.error('Error toggling widget:', error);
        }
    }
    
    /**
     * Update widget size
     */
    async updateSize(widgetCode, size) {
        const widget = this.roleConfig.find(w => w.code === widgetCode);
        if (widget) {
            widget.size = size;
            this.unsavedChanges = true;
            this.updatePreview();
        }
        
        try {
            await fetch(`${this.apiBase}?action=size`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    role: this.currentRole,
                    widget: widgetCode,
                    size: size
                })
            });
        } catch (error) {
            console.error('Error updating size:', error);
        }
    }
    
    /**
     * Reset configuration to defaults
     */
    async resetConfig() {
        if (!this.currentRole) return;
        
        if (!confirm(`Reset configuration for ${this.currentRole} to defaults?`)) {
            return;
        }
        
        try {
            const response = await fetch(`${this.apiBase}?action=reset`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ role: this.currentRole })
            });
            
            const result = await response.json();
            
            if (result.success) {
                await this.loadRoleConfig(this.currentRole);
                this.showNotification('success', 'Configuration reset to defaults');
            } else {
                this.showNotification('error', result.error || 'Failed to reset');
            }
        } catch (error) {
            console.error('Error resetting config:', error);
        }
    }
    
    /**
     * Enable all widgets
     */
    enableAll() {
        this.roleConfig.forEach(w => w.is_enabled = 1);
        this.unsavedChanges = true;
        this.renderWidgetConfig();
        this.updatePreview();
    }
    
    /**
     * Disable all widgets
     */
    disableAll() {
        this.roleConfig.forEach(w => w.is_enabled = 0);
        this.unsavedChanges = true;
        this.renderWidgetConfig();
        this.updatePreview();
    }
    
    /**
     * Render widget configuration UI
     */
    renderWidgetConfig() {
        const container = document.getElementById('widget-config-body');
        if (!container) return;
        
        // Group widgets by category
        const grouped = {};
        this.roleConfig.forEach(widget => {
            const cat = widget.category || 'other';
            if (!grouped[cat]) grouped[cat] = [];
            grouped[cat].push(widget);
        });
        
        let html = '';
        
        for (const [category, widgets] of Object.entries(grouped)) {
            const catInfo = this.categories[category] || { label: category, icon: 'bi-grid' };
            
            html += `
                <div class="mb-4">
                    <h6 class="text-secondary mb-3">
                        <i class="${catInfo.icon} me-1"></i> ${catInfo.label}
                    </h6>
                    <div class="row g-3">
            `;
            
            widgets.forEach(widget => {
                const isEnabled = widget.is_enabled ? 'checked' : '';
                const disabledClass = !widget.is_enabled ? 'disabled' : '';
                const iconColors = this.getIconColors(widget.icon_bg_color);
                
                html += `
                    <div class="col-md-6 col-lg-4">
                        <div class="widget-item border rounded p-3 h-100 ${disabledClass}" data-widget="${widget.code}">
                            <div class="d-flex align-items-start gap-3">
                                <div class="widget-item-icon rounded" style="background: ${iconColors.bg}; color: ${iconColors.text};">
                                    <i class="${widget.icon}"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small">${widget.name}</div>
                                    <div class="text-muted small">${widget.description || ''}</div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" ${isEnabled} onchange="dashConfig.toggleWidget('${widget.code}', this.checked)">
                                </div>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn ${widget.size === 'S' ? 'btn-primary' : 'btn-outline-secondary'} size-btn" onclick="dashConfig.updateSize('${widget.code}', 'S')">S</button>
                                    <button class="btn ${widget.size === 'M' ? 'btn-primary' : 'btn-outline-secondary'} size-btn" onclick="dashConfig.updateSize('${widget.code}', 'M')">M</button>
                                    <button class="btn ${widget.size === 'L' ? 'btn-primary' : 'btn-outline-secondary'} size-btn" onclick="dashConfig.updateSize('${widget.code}', 'L')">L</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div></div>';
        }
        
        container.innerHTML = html;
    }
    
    /**
     * Get icon colors based on color name
     */
    getIconColors(colorName) {
        const colors = {
            'primary': { bg: '#e7f1ff', text: '#0d6efd' },
            'success': { bg: '#d1e7dd', text: '#198754' },
            'warning': { bg: '#fff3cd', text: '#ffc107' },
            'danger': { bg: '#f8d7da', text: '#dc3545' },
            'info': { bg: '#cff4fc', text: '#0dcaf0' },
            'secondary': { bg: '#e2e3e5', text: '#6c757d' }
        };
        return colors[colorName] || colors['secondary'];
    }
    
    /**
     * Update dashboard preview
     */
    updatePreview() {
        const container = document.getElementById('dashboard-preview');
        if (!container) return;
        
        const enabledWidgets = this.roleConfig.filter(w => w.is_enabled);
        
        if (enabledWidgets.length === 0) {
            container.innerHTML = '<div class="text-center text-muted py-4">No widgets enabled</div>';
            return;
        }
        
        let html = '<div class="row g-2">';
        
        enabledWidgets.forEach(widget => {
            const colClass = {
                'S': 'col-md-4',
                'M': 'col-md-6',
                'L': 'col-12'
            }[widget.size] || 'col-md-4';
            
            const iconColors = this.getIconColors(widget.icon_bg_color);
            
            html += `
                <div class="${colClass}">
                    <div class="bg-white rounded p-2 border d-flex align-items-center gap-2">
                        <i class="${widget.icon}" style="color: ${iconColors.text};"></i>
                        <span class="small">${widget.name}</span>
                    </div>
                </div>
            `;
        });
        
        html += '</div>';
        container.innerHTML = html;
    }
    
    /**
     * Bind UI events
     */
    bindEvents() {
        // Role selector
        document.querySelectorAll('.role-option').forEach(el => {
            el.addEventListener('click', () => {
                const role = el.dataset.role;
                if (role) {
                    document.querySelectorAll('.role-option').forEach(r => r.classList.remove('active'));
                    el.classList.add('active');
                    this.loadRoleConfig(role);
                    
                    // Update role badge
                    const badge = document.getElementById('selected-role');
                    if (badge) {
                        badge.className = `role-badge role-${role.toLowerCase()}`;
                        badge.textContent = role;
                    }
                }
            });
        });
        
        // Save button
        document.getElementById('btn-save-config')?.addEventListener('click', () => this.saveConfig());
        
        // Reset button
        document.getElementById('btn-reset-config')?.addEventListener('click', () => this.resetConfig());
        
        // Enable/Disable all
        document.getElementById('btn-enable-all')?.addEventListener('click', () => this.enableAll());
        document.getElementById('btn-disable-all')?.addEventListener('click', () => this.disableAll());
        
        // Warn on leave if unsaved
        window.addEventListener('beforeunload', (e) => {
            if (this.unsavedChanges) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }
    
    /**
     * Show notification
     */
    showNotification(type, message) {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <i class="bi ${type === 'success' ? 'bi-check-circle' : 'bi-exclamation-circle'}"></i>
            <span>${message}</span>
        `;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            background: ${type === 'success' ? '#10B981' : '#EF4444'};
            color: white;
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 9999;
            animation: slideIn 0.3s ease;
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }
}

// Initialize when DOM ready
let dashConfig;
document.addEventListener('DOMContentLoaded', () => {
    dashConfig = new DashboardConfigEngine();
});
