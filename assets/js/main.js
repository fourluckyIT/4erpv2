/**
 * ERP v2 Main JavaScript
 * Phase 1 - Foundation
 */

document.addEventListener('DOMContentLoaded', function () {
    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 5000);
    });

    // Confirm delete actions
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function (e) {
            const message = this.dataset.confirm || 'Are you sure?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });

    // CSRF token for AJAX
    window.csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

    // Tooltips
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));

    // Search filter for tables
    const searchInputs = document.querySelectorAll('[data-table-search]');
    searchInputs.forEach(input => {
        input.addEventListener('input', function () {
            const tableId = this.dataset.tableSearch;
            const table = document.getElementById(tableId);
            if (!table) return;

            const searchTerm = this.value.toLowerCase();
            const rows = table.querySelectorAll('tbody tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    });
});

/**
 * Format number with commas
 */
function formatNumber(number, decimals = 2) {
    return Number(number).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

/**
 * AJAX helper
 */
async function ajaxPost(url, data) {
    const formData = new FormData();
    formData.append('csrf_token', window.csrfToken);

    for (const [key, value] of Object.entries(data)) {
        formData.append(key, value);
    }

    const response = await fetch(url, {
        method: 'POST',
        body: formData
    });

    return response.json();
}

/**
 * Show loading spinner
 */
function showLoading(container) {
    const spinner = `
        <div class="text-center py-4" id="loading-spinner">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `;
    if (typeof container === 'string') {
        container = document.querySelector(container);
    }
    container.innerHTML = spinner;
}

/**
 * Hide loading spinner
 */
function hideLoading() {
    const spinner = document.getElementById('loading-spinner');
    if (spinner) spinner.remove();
}
