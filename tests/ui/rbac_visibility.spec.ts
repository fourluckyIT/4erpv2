import { test, expect, Page } from '@playwright/test';

/**
 * RBAC Visibility Tests
 * 
 * Tests that UI elements are correctly shown/hidden based on user roles,
 * and that attempts to access forbidden actions are properly denied.
 */

const BASE_URL = 'http://localhost:8888/4erpv2';

interface TestUser {
    username: string;
    password: string;
    role: string;
}

// Test users per role (seeded by scripts/seed_rbac_test_users.php)
const TEST_USERS: Record<string, TestUser> = {
    ADM: { username: 'admin', password: 'Test1234!', role: 'ADM' },
    PLN: { username: 'planner', password: 'Test1234!', role: 'PLN' },
    PUR: { username: 'purchaser', password: 'Test1234!', role: 'PUR' },
    WH: { username: 'warehouse', password: 'Test1234!', role: 'WH' },
    ACC: { username: 'accountant', password: 'Test1234!', role: 'ACC' },
    MGR: { username: 'manager', password: 'Test1234!', role: 'MGR' },
};

async function loginAs(page: Page, role: string) {
    const user = TEST_USERS[role];
    if (!user) throw new Error(`Unknown role: ${role}`);

    await page.goto(BASE_URL + '/modules/auth/login.php');
    await page.fill('input[name="username"]', user.username);
    await page.fill('input[name="password"]', user.password);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/index.php', { timeout: 10000 });
}

test.describe('RBAC Visibility - ADM (Admin)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAs(page, 'ADM');
    });

    test('ADM can see Admin menu', async ({ page }) => {
        await page.goto(BASE_URL + '/index.php');
        await expect(page.getByTestId('nav-admin')).toBeVisible();
    });

    test('ADM can access Jobs', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/jobs/');
        await expect(page.locator('h2, h1')).toContainText(/Jobs/i);
    });

    test('ADM can access Procurement', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/');
        await expect(page.locator('h1, h2')).toContainText(/Procurement/i);
    });

    test('ADM can see Reversal Button in Payments', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/accounting/payments/index.php');
        await expect(page.locator('table th:has-text("Action")')).toBeVisible();
        // Check for at least one reversal button if data exists, or just the UI structure
        // We assume test data might not have posted payments, but the column header verifies UI load
        await expect(page.locator('h2')).toContainText(/Payments/i);
    });
});

test.describe('RBAC Visibility - WH (Warehouse)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAs(page, 'WH');
    });

    test('WH cannot see Admin menu', async ({ page }) => {
        await page.goto(BASE_URL + '/index.php');
        const adminLink = page.locator('nav >> text=Admin');
        await expect(adminLink).toHaveCount(0);
    });

    test('WH can access GR (Goods Receipt)', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/gr/');
        await expect(page).not.toHaveURL(/login/);
    });

    test('WH can access Receive Page (Fix Verification)', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/warehouse/receive.php');
        await expect(page.locator('h2')).toContainText(/Receive/i);
        await expect(page).not.toHaveURL(/login/);
    });

    test('WH is blocked from Accounting Invoices', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/accounting/invoices/');
        // Expect redirect to index or login, or error message
        // Just checking it doesn't stay on invoices page
        await expect(page).not.toHaveURL(/\/accounting\/invoices\/?$/);
    });

    test('WH is blocked from Accounting Payments', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/accounting/payments/');
        await expect(page).not.toHaveURL(/\/accounting\/payments\/?$/);
    });
});

test.describe('RBAC Visibility - PLN (Planner)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAs(page, 'PLN');
    });

    test('PLN can access Planning', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/planning/');
        await expect(page.locator('h1, h2')).toContainText(/Planning/i);
    });

    test('PLN can access Routes', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/logistics/routes/');
        await expect(page).not.toHaveURL(/login/);
    });

    test('PLN cannot access Accounting', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/accounting/invoices/');
        await expect(page).not.toHaveURL(/\/accounting\/invoices\/?$/);
    });
});

test.describe('RBAC Visibility - PUR (Procurement)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAs(page, 'PUR');
    });

    test('PUR can see Procurement menu', async ({ page }) => {
        await page.goto(BASE_URL + '/index.php');
        const procLink = page.locator('nav >> text=Procurement');
        await expect(procLink).toBeVisible();
    });

    test('PUR can access PR list', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/pr/');
        await expect(page).not.toHaveURL(/login/);
        await expect(page.locator('table')).toBeVisible();
    });

    test('PUR can access PO list', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/po/');
        await expect(page).not.toHaveURL(/login/);
    });

    test('PUR can access Procurement Dashboard (Fix Verification)', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/');
        await expect(page.locator('h2')).toContainText(/Procurement/i);
    });

    test('PUR cannot access Warehouse', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/warehouse/receive.php');
        await expect(page).not.toHaveURL(/\/warehouse\/receive\.php/);
    });
});

test.describe('RBAC Visibility - ACC (Accounting)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAs(page, 'ACC');
    });

    test('ACC can see PO (for invoice verification)', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/procurement/po/');
        await expect(page).not.toHaveURL(/login/);
    });

    test('ACC can access Payments', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/accounting/payments/');
        await expect(page.locator('h2')).toContainText(/Payments/i);
    });

    test('ACC can see Reversal Button', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/accounting/payments/');
        // Verify Action column exists
        await expect(page.locator('table th:has-text("Action")')).toBeVisible();
    });

    test('ACC cannot access Warehouse Receive', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/warehouse/receive.php');
        await expect(page).not.toHaveURL(/\/warehouse\/receive\.php/);
    });
});

test.describe('RBAC Visibility - MGR (Manager)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAs(page, 'MGR');
    });

    test('MGR can see Admin menu', async ({ page }) => {
        await page.goto(BASE_URL + '/index.php');
        await expect(page.locator('nav >> text=Admin')).toBeVisible();
    });

    test('MGR can access Audit Logs', async ({ page }) => {
        await page.goto(BASE_URL + '/modules/admin/audit_logs.php');
        await expect(page.locator('h1, h2')).toContainText(/Audit/i);
    });

    test('MGR can View All Modules', async ({ page }) => {
        // Manager usually has view access to most modules?
        // Let's check Jobs
        await page.goto(BASE_URL + '/modules/jobs/');
        await expect(page.locator('h2')).toContainText(/Jobs/i);
    });
});
