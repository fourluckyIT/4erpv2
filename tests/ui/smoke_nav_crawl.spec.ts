import { test, expect, Page } from '@playwright/test';

/**
 * Smoke Navigation Crawler
 * 
 * Logs in as ADM and crawls all navigation links,
 * verifying each page loads without PHP errors.
 */

const BASE_URL = 'http://localhost:8888/4erp/4erpv2';

// Test user credentials (must exist in test DB)
const TEST_USER = {
    username: 'admin',
    password: 'admin123'
};

// All discoverable nav links from nav.php
const NAV_LINKS = [
    { name: 'Dashboard', url: '/index.php' },
    { name: 'Jobs', url: '/modules/jobs/' },
    { name: 'Planning', url: '/modules/planning/' },
    { name: 'Logistics - Dispatch', url: '/modules/logistics/dispatch/' },
    { name: 'Logistics - Routes', url: '/modules/logistics/routes/' },
    { name: 'Warehouse - Movements', url: '/modules/warehouse/movements.php' },
    { name: 'Warehouse - Receive', url: '/modules/warehouse/receive.php' },
    { name: 'Procurement Hub', url: '/modules/procurement/' },
    { name: 'Procurement - PR', url: '/modules/procurement/pr/' },
    { name: 'Procurement - PO', url: '/modules/procurement/po/' },
    { name: 'Procurement - GR', url: '/modules/procurement/gr/' },
    { name: 'Accounting - Invoices', url: '/modules/accounting/invoices/' },
    { name: 'Accounting - Payments', url: '/modules/accounting/payments/' },
    { name: 'Admin Dashboard', url: '/modules/admin/' },
    { name: 'Admin - Users', url: '/modules/admin/users.php' },
    { name: 'Admin - Roles', url: '/modules/admin/roles.php' },
    { name: 'Admin - Permissions', url: '/modules/admin/permissions.php' },
    { name: 'Admin - Doc Numbers', url: '/modules/admin/doc_numbers.php' },
    { name: 'Admin - LINE Bindings', url: '/modules/admin/line_bindings.php' },
    { name: 'Admin - Audit Logs', url: '/modules/admin/audit_logs.php' },
    { name: 'Master - Index', url: '/modules/master/' },
    { name: 'Master - Customers', url: '/modules/master/customers.php' },
    { name: 'Master - Suppliers', url: '/modules/master/suppliers.php' },
    { name: 'Master - Items', url: '/modules/master/items.php' },
    { name: 'Master - Serials', url: '/modules/master/serials.php' },
    { name: 'Master - Sites', url: '/modules/master/sites.php' },
    { name: 'Master - People', url: '/modules/master/people.php' },
    { name: 'Profile', url: '/modules/auth/profile.php' },
];

// PHP error indicators (avoid false positives from UI text)
const PHP_ERRORS = [
    'Fatal error',
    'Parse error',
    '<b>Warning</b>:',  // PHP style warning format
    '<b>Notice</b>:',   // PHP style notice format
    'Uncaught',
    'Stack trace',
    'SQLSTATE',
];

async function login(page: Page) {
    await page.goto(BASE_URL + '/modules/auth/login.php');
    await page.fill('input[name="username"]', TEST_USER.username);
    await page.fill('input[name="password"]', TEST_USER.password);
    await page.click('button[type="submit"]');

    // Wait for redirect to dashboard
    await page.waitForURL('**/index.php', { timeout: 10000 });
}

test.describe('Smoke Navigation Crawl', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    for (const link of NAV_LINKS) {
        test(`Page loads: ${link.name}`, async ({ page }) => {
            const response = await page.goto(BASE_URL + link.url);

            // Assert HTTP 200
            expect(response?.status()).toBe(200);

            // Check for PHP errors in page content
            const content = await page.content();
            for (const errorPattern of PHP_ERRORS) {
                expect(content).not.toContain(errorPattern);
            }

            // Check page has some expected structure (navbar)
            await expect(page.locator('nav.navbar')).toBeVisible();
        });
    }

    test('All nav dropdowns expand', async ({ page }) => {
        await page.goto(BASE_URL + '/index.php');

        // Check Logistics dropdown
        const logisticsDropdown = page.locator('text=Logistics');
        if (await logisticsDropdown.isVisible()) {
            await logisticsDropdown.click();
            await expect(page.locator('text=Dispatch')).toBeVisible();
        }

        // Check Procurement dropdown
        const procDropdown = page.locator('text=Procurement');
        if (await procDropdown.isVisible()) {
            await procDropdown.click();
            await expect(page.locator('.dropdown-menu >> text=Purchase').first()).toBeVisible();
        }

        // Check Admin dropdown
        const adminDropdown = page.locator('text=Admin').first();
        if (await adminDropdown.isVisible()) {
            await adminDropdown.click();
            await expect(page.locator('text=Users')).toBeVisible();
        }
    });
});
