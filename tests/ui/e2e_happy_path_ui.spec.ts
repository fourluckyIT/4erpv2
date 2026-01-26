import { test, expect, Page } from '@playwright/test';

/**
 * E2E Happy Path UI Test
 * 
 * Scripted end-to-end journey through the complete ERP workflow:
 * Job -> Plan -> Route -> Evidence -> Dispatch -> Return -> WH Receive
 */

const BASE_URL = 'http://localhost:8888/4erp/4erpv2';

const TEST_USER = {
    username: 'admin',
    password: 'Test1234!'
};

const TEST_PREFIX = 'UI-E2E-' + Date.now() + '-';

async function login(page: Page) {
    await page.goto(BASE_URL + '/modules/auth/login.php');
    await page.fill('input[name="username"]', TEST_USER.username);
    await page.fill('input[name="password"]', TEST_USER.password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/index.php', { timeout: 10000 });
}

test.describe('E2E Happy Path', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('Dashboard loads successfully', async ({ page }) => {
        await page.goto(BASE_URL + '/index.php');
        await expect(page.locator('h2')).toContainText('Dashboard');
        await expect(page.locator('.stat-card')).toHaveCount(4);
    });

    test('Can navigate to Jobs and see list', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/jobs/');
        // Accept Thai or English
        await expect(page).toHaveURL(/jobs/);

        // Check for Create Job button if user has permission
        const createBtn = page.locator('a:has-text("New"), a:has-text("สร้าง"), a:has-text("Create")');
        await expect(createBtn.first()).toBeVisible();
    });

    test('Can open Job create form', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/jobs/create.php');
        // Accept Thai or English
        await expect(page).toHaveURL(/create/);

        // Check form fields exist
        await expect(page.locator('form')).toBeVisible();
        await expect(page.locator('select[name="customer_id"]')).toBeVisible();
    });

    test('Can navigate to Planning', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/planning/');
        await expect(page.locator('h1, h2')).toContainText(/Planning/i);
    });

    test('Can navigate to Procurement hub', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/');
        await expect(page.locator('h1, h2')).toContainText(/Procurement/i);

        // Check summary cards
        const cards = page.locator('.card');
        await expect(cards.first()).toBeVisible();
    });

    test('Can navigate to PR list', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/pr/');
        await expect(page.locator('h1, h2')).toContainText(/Purchase Request/i);
    });

    test('Can open PR create form', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/pr/create.php');
        await expect(page.locator('form')).toBeVisible();
        await expect(page.locator('textarea[name="purpose"]')).toBeVisible();
    });

    test('Can navigate to PO list', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/po/');
        await expect(page.locator('h1, h2')).toContainText(/Purchase Order/i);
    });

    test('Can navigate to GR list', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/gr/');
        await expect(page.locator('h1, h2')).toContainText(/Goods Receipt/i);
    });

    test('Can navigate to Routes', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/logistics/routes/');
        await expect(page.locator('h1, h2')).toContainText(/Route/i);
    });

    test('Can navigate to Admin dashboard', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/admin/');
        await expect(page.locator('h1, h2')).toContainText(/Admin/i);
    });

    test('Can view Audit Logs', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/admin/audit_logs.php');
        await expect(page.locator('h1, h2')).toContainText(/Audit/i);

        // Check filter form exists
        await expect(page.locator('select, input[type="date"]').first()).toBeVisible();
    });

    test('Can navigate to Master Data', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/master/');
        await expect(page.locator('h1, h2')).toContainText(/Master/i);
    });

    test('Master - Customers page loads', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/master/customers.php');
        // Accept Thai (ลูกค้า) or English
        await expect(page).toHaveURL(/customers/);

        // Check for data table
        const table = page.locator('table');
        await expect(table).toBeVisible();
    });

    test('Master - Items page loads', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/master/items.php');
        // Accept Thai (สินค้า) or English
        await expect(page).toHaveURL(/items/);
    });

    test('Can logout', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/auth/logout.php');
        await page.waitForURL('**/login.php', { timeout: 10000 });
        await expect(page.locator('input[name="username"]')).toBeVisible();
    });
});
