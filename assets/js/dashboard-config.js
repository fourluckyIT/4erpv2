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
                <div class="widget-category">
                    <div class="widget-category-title">
                        <i class="${catInfo.icon}"></i> ${catInfo.label}
                    </div>
                    <div class="widget-list">
            `;
            
            widgets.forEach(widget => {
                const isEnabled = widget.is_enabled ? 'checked' : '';
                const isDisabled = !widget.is_enabled ? 'disabled' : '';
                
                html += `
                    <div class="widget-item ${isDisabled}" data-widget="${widget.code}">
                        <div class="widget-item-icon" style="background: var(--${widget.icon_bg_color}-light, var(--gray-100)); color: var(--${widget.icon_bg_color}, var(--gray-600));">
                            <i class="${widget.icon}"></i>
                        </div>
                        <div class="widget-item-content">
                            <div class="widget-item-title">${widget.name}</div>
                            <div class="widget-item-desc">${widget.description || ''}</div>
                        </div>
                        <div class="widget-item-controls">
                            <label class="switch">
                                <input type="checkbox" ${isEnabled} onchange="dashConfig.toggleWidget('${widget.code}', this.checked)">
                                <span class="switch-slider"></span>
                            </label>
                            <div class="size-selector">
                                <button class="size-btn ${widget.size === 'S' ? 'active' : ''}" onclick="dashConfig.updateSize('${widget.code}', 'S')">S</button>
                                <button class="size-btn ${widget.size === 'M' ? 'active' : ''}" onclick="dashConfig.updateSize('${widget.code}', 'M')">M</button>
                                <button class="size-btn ${widget.size === 'L' ? 'active' : ''}" onclick="dashConfig.updateSize('${widget.code}', 'L')">L</button>
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
     * Update dashboard preview
     */
    updatePreview() {
        const container = document.getElementById('dashboard-preview');
        if (!container) return;
        
        const enabledWidgets = this.roleConfig.filter(w => w.is_enabled);
        
        if (enabledWidgets.length === 0) {
            container.innerHTML = '<div style="text-align: center; color: var(--gray-400); padding: 40px;">No widgets enabled</div>';
            return;
        }
        
        let html = '<div style="display: flex; flex-wrap: wrap; gap: 12px;">';
        
        enabledWidgets.forEach(widget => {
            const sizeClass = {
                'S': 'width: 32%;',
                'M': 'width: 48%;',
                'L': 'width: 100%;'
            }[widget.size] || 'width: 32%;';
            
            html += `
                <div class="preview-widget" style="${sizeClass} display: inline-flex; background: var(--white); padding: 12px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); align-items: center; gap: 8px;">
                    <i class="${widget.icon}" style="color: var(--${widget.icon_bg_color}, var(--primary));"></i>
                    <span style="font-size: 0.85rem;">${widget.name}</span>
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
